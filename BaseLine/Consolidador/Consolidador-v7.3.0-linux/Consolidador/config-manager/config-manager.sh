#!/usr/bin/env bash
set -Eeuo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${project_root}"
readonly consolidation_compose_project="moodle-consolidation-production"
export COMPOSE_PROJECT_NAME="${consolidation_compose_project}"

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

require_runtime() {
  [[ -f .env ]] || die "Falta .env. Ejecute primero ./CONFIGURAR.sh."
  set -a
  # shellcheck disable=SC1091
  source ./.env
  set +a
  command -v docker >/dev/null 2>&1 || die "No se encontró Docker Engine."
  docker compose version >/dev/null 2>&1 ||
    die "Se requiere Docker Compose v2."
  docker info >/dev/null 2>&1 ||
    die "Docker no responde o el usuario no tiene permiso."
  command -v flock >/dev/null 2>&1 || die "Se requiere flock."

  local socket_path
  if [[ "${DOCKER_HOST:-}" == unix://* ]]; then
    socket_path="${DOCKER_HOST#unix://}"
  else
    socket_path=/var/run/docker.sock
  fi
  [[ -S "${socket_path}" ]] ||
    die "No se encontró el socket Docker en ${socket_path}."
  export ASSISTANT_PROJECT_ROOT="${project_root}"
  export ASSISTANT_UID="$(id -u)"
  export ASSISTANT_GID="$(id -g)"
  export DOCKER_GID="$(stat -c '%g' "${socket_path}")"
  export DOCKER_SOCKET_PATH="${socket_path}"

  managed_dir="${MOODLE_MANAGED_CONFIG_DIR:-${project_root}/managed-config}"
  [[ "${managed_dir}" == /* ]] ||
    die "MOODLE_MANAGED_CONFIG_DIR debe ser una ruta absoluta."
  [[ "${managed_dir}" =~ ^/[A-Za-z0-9._/@+-]+(/[A-Za-z0-9._@+-]+)*$ ]] ||
    die "La ruta administrada solo puede usar letras, números, / . _ @ + y -."
  [[ "${managed_dir}" != "${project_root}" ]] ||
    die "El directorio administrado no puede ser la raíz del paquete."
  local reserved
  for reserved in copias config config-manager docker exports reports scripts; do
    [[ "${managed_dir}" != "${project_root}/${reserved}" &&
        "${managed_dir}" != "${project_root}/${reserved}/"* ]] ||
      die "El directorio administrado no puede ubicarse dentro de ${reserved}/."
  done
  export MOODLE_MANAGED_CONFIG_DIR="${managed_dir}"
  mkdir -p "${managed_dir}" "${managed_dir}/history"
  chmod 0700 "${managed_dir}" "${managed_dir}/history"
  exec {manager_lock_fd}>"${managed_dir}/manager.lock"
  flock -n "${manager_lock_fd}" ||
    die "Otro proceso está modificando la configuración administrada."
}

compose() {
  docker compose --project-name "${consolidation_compose_project}" "$@"
}

run_tool() {
  compose run --rm --no-deps -T --entrypoint php moodle-target \
    /usr/local/lib/moodle-managed-config.php "$@"
}

container_path() {
  local host_path="$1"
  [[ "${host_path}" == "${managed_dir}/"* ]] ||
    die "La ruta ${host_path} no pertenece al directorio administrado."
  printf '/run/moodle-config/%s' "${host_path#"${managed_dir}/"}"
}

operator_name() {
  printf '%s' "${SUDO_USER:-${USER:-$(id -un)}}"
}

to_base64() {
  printf '%s' "$1" | base64 | tr -d '\n'
}

new_version_id() {
  local hash="$1" candidate
  while true; do
    candidate="$(date -u +%Y%m%dT%H%M%SZ)-${hash:0:12}"
    if [[ ! -e "${managed_dir}/history/${candidate}" ]]; then
      printf '%s' "${candidate}"
      return 0
    fi
    sleep 1
  done
}

active_target() {
  [[ -L "${managed_dir}/active" ]] || return 1
  readlink "${managed_dir}/active"
}

active_version() {
  local target
  target="$(active_target)" || return 1
  [[ "${target}" =~ ^history/([0-9]{8}T[0-9]{6}Z-[a-f0-9]{12})$ ]] ||
    die "El enlace active tiene un destino no permitido: ${target}."
  printf '%s' "${BASH_REMATCH[1]}"
}

switch_active() {
  local version="$1"
  local temporary="${managed_dir}/.active.$$"
  [[ -d "${managed_dir}/history/${version}" ]] ||
    die "No existe la versión ${version}."
  ln -s "history/${version}" "${temporary}"
  mv -Tf "${temporary}" "${managed_dir}/active"
  [[ "$(readlink "${managed_dir}/active")" == "history/${version}" ]] ||
    die "No fue posible activar atómicamente la versión ${version}."
}

ensure_convenience_links() {
  ln -sfn active/settings.json "${managed_dir}/current.json"
  ln -sfn active/current.php "${managed_dir}/current.php"
  ln -sfn active/manifest.json "${managed_dir}/manifest.json"
}

image_available() {
  local image_ref
  image_ref="${MOODLE_TARGET_IMAGE:-moodle-consolidation-target:5.2.1-v7.3.0}"
  docker image inspect "${image_ref}" >/dev/null 2>&1
}

target_container_id() {
  compose ps --all --quiet moodle-target 2>/dev/null | sed -n '1p'
}

service_running() {
  local service="$1" container_id state
  container_id="$(compose --profile live ps --all --quiet "${service}" 2>/dev/null | sed -n '1p')"
  [[ -n "${container_id}" ]] || return 1
  state="$(docker inspect --format '{{.State.Status}}' "${container_id}" 2>/dev/null || true)"
  [[ "${state}" == "running" ]]
}

wait_for_target() {
  local deadline container_id state health
  deadline=$((SECONDS + 900))
  while (( SECONDS < deadline )); do
    container_id="$(target_container_id)"
    if [[ -z "${container_id}" ]]; then
      sleep 4
      continue
    fi
    state="$(docker inspect --format '{{.State.Status}}' "${container_id}" 2>/dev/null || true)"
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
      "${container_id}" 2>/dev/null || true)"
    if [[ "${state}" == "running" && "${health}" == "healthy" ]]; then
      return 0
    fi
    case "${state}" in
      exited|dead|removing) return 1 ;;
    esac
    sleep 6
  done
  return 1
}

notify() {
  local status="$1" message="$2"
  [[ "${CONSOLIDATION_EMAIL_ENABLED:-0}" == "1" ]] || return 0
  if ! compose run --rm --no-deps -T assistant-runtime \
      pwsh -NoLogo -NoProfile -File scripts/send-notification.ps1 \
      -Stage "Gestor de configuración" \
      -Status "${status}" \
      -Message "${message}"; then
    printf 'ADVERTENCIA: no fue posible enviar el correo del gestor.\n' >&2
  fi
}

append_audit() {
  local manifest_path="$1"
  tr -d '\r\n' < "${manifest_path}" >> "${managed_dir}/audit.jsonl"
  printf '\n' >> "${managed_dir}/audit.jsonl"
  chmod 0600 "${managed_dir}/audit.jsonl"
}

write_manifest() {
  local input="$1" compiled="$2" version="$3" reason="$4"
  local status="$5" output="$6" old_input="${7:-}" previous="${8:-}"
  local args
  args=(
    manifest
    "--input=$(container_path "${input}")"
    "--compiled=$(container_path "${compiled}")"
    "--version=${version}"
    "--status=${status}"
    "--reason-base64=$(to_base64 "${reason}")"
    "--operator-base64=$(to_base64 "$(operator_name)")"
  )
  [[ -n "${old_input}" ]] &&
    args+=("--old=$(container_path "${old_input}")")
  [[ -n "${previous}" ]] && args+=("--previous=${previous}")
  run_tool "${args[@]}" > "${output}"
  chmod 0600 "${output}"
}

initialize() {
  if [[ -e "${managed_dir}/active" || -L "${managed_dir}/active" ]]; then
    run_tool verify --directory=/run/moodle-config
    ensure_convenience_links
    printf 'CONFIG_ADMINISTRADA_YA_INICIALIZADA version=%s\n' "$(active_version)"
    return 0
  fi
  image_available ||
    die "La imagen Moodle aún no existe. Ejecute ./PREPARAR-DESTINO.sh."

  local candidate compiled hash version version_dir manifest
  candidate="${managed_dir}/.initial-settings.$$"
  compiled="${managed_dir}/.initial-current.$$"
  cp config-manager/default-settings.json "${candidate}"
  chmod 0600 "${candidate}"
  run_tool compile "--input=$(container_path "${candidate}")" > "${compiled}"
  chmod 0600 "${compiled}"
  hash="$(sha256sum "${compiled}" | awk '{print $1}')"
  version="$(new_version_id "${hash}")"
  version_dir="${managed_dir}/history/${version}"
  mkdir "${version_dir}"
  chmod 0700 "${version_dir}"
  mv "${candidate}" "${version_dir}/settings.json"
  mv "${compiled}" "${version_dir}/current.php"
  manifest="${version_dir}/manifest.json"
  write_manifest \
    "${version_dir}/settings.json" \
    "${version_dir}/current.php" \
    "${version}" \
    "Inicialización automática del gestor" \
    initialized \
    "${manifest}"
  switch_active "${version}"
  ensure_convenience_links
  append_audit "${manifest}"
  run_tool verify --directory=/run/moodle-config
  printf 'CONFIG_ADMINISTRADA_INICIALIZADA version=%s ruta=%s\n' \
    "${version}" "${managed_dir}"
}

edit_pending() {
  initialize >/dev/null
  local pending="${managed_dir}/pending.json"
  cp "${managed_dir}/active/settings.json" "${pending}"
  chmod 0600 "${pending}"
  local editor="${EDITOR:-}"
  if [[ -z "${editor}" ]]; then
    if command -v nano >/dev/null 2>&1; then
      editor=nano
    elif command -v vi >/dev/null 2>&1; then
      editor=vi
    else
      die "Defina EDITOR o instale nano/vi."
    fi
  fi
  local editor_command
  read -r -a editor_command <<< "${editor}"
  "${editor_command[@]}" "${pending}"
  run_tool validate "--input=$(container_path "${pending}")"
  if cmp -s "${pending}" "${managed_dir}/active/settings.json"; then
    rm -f "${pending}"
    printf 'SIN_CAMBIOS no_se_creo_propuesta=1\n'
  else
    printf 'PROPUESTA_LISTA archivo=%s\n' "${pending}"
    printf 'Revísela y aplique con: ./GESTIONAR-CONFIG.sh aplicar --motivo "descripción"\n'
  fi
}

restart_after_switch() {
  local cron_was_running="$1"
  compose up -d --no-deps --force-recreate moodle-target || return 1
  wait_for_target || return 1
  compose exec -T -u www-data moodle-target \
    php admin/cli/purge_caches.php || return 1
  if [[ "${cron_was_running}" == "1" ]]; then
    compose --profile live up -d --no-deps --force-recreate moodle-cron ||
      return 1
  fi
}

apply_pending() {
  local reason="$1"
  local pending="${managed_dir}/pending.json"
  [[ -f "${pending}" ]] ||
    die "No existe pending.json. Ejecute primero ./GESTIONAR-CONFIG.sh editar."
  [[ -n "${reason//[[:space:]]/}" ]] || die "El motivo no puede quedar vacío."
  [[ ${#reason} -le 1000 ]] || die "El motivo supera 1000 caracteres."
  initialize >/dev/null
  run_tool validate "--input=$(container_path "${pending}")"
  if cmp -s "${pending}" "${managed_dir}/active/settings.json"; then
    rm -f "${pending}"
    printf 'SIN_CAMBIOS configuracion_activa_sin_modificaciones=1\n'
    return 0
  fi

  local previous compiled hash version version_dir manifest
  local target_was_running=0 cron_was_running=0
  previous="$(active_version)"
  compiled="${managed_dir}/.candidate-current.$$"
  run_tool compile "--input=$(container_path "${pending}")" > "${compiled}"
  chmod 0600 "${compiled}"
  if cmp -s "${compiled}" "${managed_dir}/active/current.php"; then
    rm -f "${pending}" "${compiled}"
    printf 'SIN_CAMBIOS valores_de_configuracion_sin_modificaciones=1\n'
    return 0
  fi
  hash="$(sha256sum "${compiled}" | awk '{print $1}')"
  version="$(new_version_id "${hash}")"
  version_dir="${managed_dir}/history/${version}"
  [[ ! -e "${version_dir}" ]] || die "Ya existe la versión ${version}."
  mkdir "${version_dir}"
  chmod 0700 "${version_dir}"
  cp "${pending}" "${version_dir}/settings.json"
  mv "${compiled}" "${version_dir}/current.php"
  chmod 0600 "${version_dir}/settings.json" "${version_dir}/current.php"
  manifest="${version_dir}/manifest.json"
  write_manifest \
    "${version_dir}/settings.json" \
    "${version_dir}/current.php" \
    "${version}" \
    "${reason}" \
    applied \
    "${manifest}" \
    "${managed_dir}/active/settings.json" \
    "${previous}"
  run_tool verify "--directory=/run/moodle-config/history/${version}"

  service_running moodle-target && target_was_running=1
  service_running moodle-cron && cron_was_running=1
  if [[ "${cron_was_running}" == "1" ]]; then
    compose --profile live stop moodle-cron >/dev/null
  fi
  switch_active "${version}"
  ensure_convenience_links

  if [[ "${target_was_running}" == "0" ]] ||
      restart_after_switch "${cron_was_running}"; then
    rm -f "${pending}"
    append_audit "${manifest}"
    printf 'CONFIG_APLICADA version=%s anterior=%s\n' "${version}" "${previous}"
    notify "APLICADA" "Versión ${version}; motivo: ${reason}"
    return 0
  fi

  printf 'La nueva configuración no superó el healthcheck; revirtiendo...\n' >&2
  switch_active "${previous}"
  ensure_convenience_links
  write_manifest \
    "${version_dir}/settings.json" \
    "${version_dir}/current.php" \
    "${version}" \
    "${reason}" \
    failed_rolled_back \
    "${manifest}" \
    "${managed_dir}/history/${previous}/settings.json" \
    "${previous}"
  append_audit "${manifest}"
  if ! restart_after_switch "${cron_was_running}"; then
    notify "FALLO_CRITICO" \
      "Falló ${version} y el destino no recuperó salud tras volver a ${previous}."
    die "Se reactivó ${previous}, pero Moodle no recuperó un estado saludable. Revise logs."
  fi
  notify "REVERTIDA" \
    "La versión ${version} falló y se restauró automáticamente ${previous}."
  die "La versión ${version} fue revertida; la propuesta se conserva en pending.json."
}

apply_command() {
  local reason=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --motivo)
        [[ $# -ge 2 ]] || die "--motivo requiere un valor."
        reason="$2"
        shift 2
        ;;
      *) die "Opción no reconocida para aplicar: $1" ;;
    esac
  done
  if [[ -z "${reason}" && -t 0 ]]; then
    printf 'Motivo del cambio: '
    read -r reason
  fi
  [[ -n "${reason}" ]] ||
    die "Indique --motivo, especialmente en ejecuciones no interactivas."
  apply_pending "${reason}"
}

restore_version() {
  [[ $# -ge 1 ]] || die "Indique la versión que desea restaurar."
  local version="$1"
  shift
  [[ "${version}" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$ ]] ||
    die "El identificador de versión no es válido."
  local source="${managed_dir}/history/${version}/settings.json"
  [[ -f "${source}" ]] || die "No existe la versión ${version}."
  cp "${source}" "${managed_dir}/pending.json"
  chmod 0600 "${managed_dir}/pending.json"
  if [[ $# -eq 0 ]]; then
    apply_pending "Restauración auditada de ${version}"
  else
    apply_command "$@"
  fi
}

verify_active() {
  initialize >/dev/null
  run_tool verify --directory=/run/moodle-config
  if service_running moodle-target; then
    compose exec -T moodle-target sh -lc '
      set -eu
      options="$(findmnt -T /run/moodle-config/active/current.php -no OPTIONS)"
      printf "%s\n" "$options" | tr "," "\n" | grep -qx ro
      test -r /var/www/html/.managed-config.php
      runuser -u www-data -- test -r /var/www/html/config.php
      if runuser -u www-data -- test -w /var/www/html/config.php; then exit 1; fi
      if runuser -u www-data -- test -w /var/www/html/.managed-config.php; then exit 1; fi
      php -l /var/www/html/config.php >/dev/null
      php -l /var/www/html/.managed-config.php >/dev/null
    '
    printf 'MONTAJE_CONFIG_OK solo_lectura=1 proceso_web_sin_escritura=1\n'
  else
    printf 'CONFIG_DECLARATIVA_OK destino_no_iniciado=1\n'
  fi
}

show_usage() {
  cat <<'EOF'
Uso: ./GESTIONAR-CONFIG.sh COMANDO

  inicializar             Crea la primera versión declarativa si no existe.
  ver                     Muestra la versión activa y sus ajustes.
  editar                  Abre una copia pendiente con $EDITOR y la valida.
  aplicar --motivo TEXTO  Activa la propuesta, verifica Moodle y revierte si falla.
  historial               Lista todas las versiones y sus motivos.
  restaurar VERSION       Restaura una versión como un cambio nuevo y auditado.
  verificar               Comprueba hashes, montaje de solo lectura y permisos.

La utilidad no edita archivos dentro del contenedor. La fuente persistente vive
en MOODLE_MANAGED_CONFIG_DIR y el contenedor recibe una copia de solo lectura al
arrancar. Los valores no se escriben en audit.jsonl ni en correos.
EOF
}

command_name="${1:-ayuda}"
shift || true
case "${command_name}" in
  ayuda|-h|--help)
    show_usage
    exit 0
    ;;
esac
require_runtime
case "${command_name}" in
  inicializar) initialize ;;
  ver)
    initialize >/dev/null
    run_tool show --directory=/run/moodle-config
    ;;
  editar) edit_pending ;;
  aplicar) apply_command "$@" ;;
  historial)
    initialize >/dev/null
    run_tool history --directory=/run/moodle-config/history
    ;;
  restaurar) restore_version "$@" ;;
  verificar) verify_active ;;
  *) show_usage; die "Comando desconocido: ${command_name}" ;;
esac
