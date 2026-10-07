#!/usr/bin/env bash
set -Eeuo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${project_root}"
export ASSISTANT_PROJECT_ROOT="${project_root}"
readonly consolidation_compose_project="moodle-consolidation-production"
export COMPOSE_PROJECT_NAME="${consolidation_compose_project}"

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

parse_runtime_options() {
  CONSOLIDATION_WORKERS="${CONSOLIDATION_WORKERS:-auto}"
  while (($#)); do
    case "$1" in
      --workers=*) CONSOLIDATION_WORKERS="${1#*=}" ;;
      --workers)
        shift
        (($#)) || die "--workers requiere auto o un número entre 1 y 4."
        CONSOLIDATION_WORKERS="$1"
        ;;
      *) die "Opción no reconocida: $1" ;;
    esac
    shift
  done
  [[ "${CONSOLIDATION_WORKERS}" == auto ||
      "${CONSOLIDATION_WORKERS}" =~ ^[1-4]$ ]] ||
    die "--workers debe ser auto o un entero entre 1 y 4."
  export CONSOLIDATION_WORKERS
}

required_engine_files=(
  APLICAR-REVISION-IDENTIDADES.sh
  REINICIAR-FASE13.sh
  GESTIONAR-CONFIG.sh
  config/identity-policy.json
  config/oauth2.json
  config/phase6-degradation-resolutions.csv
  config/source-integrity-policies.csv
  config/plugin-compatibility-catalog.json
  config/theme-policy.json
  config-manager/config-manager.sh
  config-manager/default-settings.json
  scripts/Common.ps1
  scripts/ConfigAccess.ps1
  scripts/ConfigParser.ps1
  scripts/Notifications.ps1
  scripts/send-notification.ps1
  scripts/progress-notifier.ps1
  scripts/run-background-with-progress.sh
  scripts/audit-package-plugins.ps1
  scripts/MoodleVersion.ps1
  scripts/confirm-package-config.ps1
  scripts/consolidation-wizard.ps1
  scripts/export-consolidated-site.ps1
  scripts/import-source-packages.ps1
  scripts/PackageIntegrity.ps1
  scripts/oauth2-validate.php
  scripts/oauth2-validate.ps1
  scripts/phase4-apply.php
  scripts/phase4-apply.ps1
  scripts/phase4-lib.php
  scripts/phase4-plan.php
  scripts/phase4-plan.ps1
  scripts/phase4-verify.php
  scripts/target-shared-email-policy.php
  scripts/phase4-verify.ps1
  scripts/phase5-apply-preflight.php
  scripts/phase5-apply.ps1
  scripts/phase5-finalize.php
  scripts/phase5-lib.php
  scripts/phase5-package-plan.ps1
  scripts/phase5-prepare-package.php
  scripts/phase5-prepare.php
  scripts/phase5-restore.php
  scripts/phase5-target-inventory.php
  scripts/phase5-verify.php
  scripts/phase5-verify.ps1
  scripts/phase6-apply-categories.php
  scripts/phase6-apply-course.php
  scripts/phase6-apply-preflight.php
  scripts/phase6-apply.ps1
  scripts/phase6-analyze-degradations.php
  scripts/phase6-analyze-course.php
  scripts/phase6-approve-degradations.ps1
  scripts/phase6-backup-users.php
  scripts/phase6-build-batch-manifest.php
  scripts/phase6-course-worker.ps1
  scripts/Phase6SourceRouting.ps1
  scripts/phase6-inventory.php
  scripts/phase6-degradation.php
  scripts/phase6-lib.php
  scripts/phase6-package-plan.ps1
  scripts/phase6-package-prepare.ps1
  scripts/phase6-random-normalization.php
  scripts/phase6-plan.php
  scripts/phase6-prepare-package-course.php
  scripts/phase6-seal-apply.php
  scripts/phase6-seal-backups.php
  scripts/phase6-target-inventory.php
  scripts/phase6-verify.php
  scripts/phase6-verify.ps1
  scripts/phase7-close.ps1
  scripts/reconcile-identities.php
  scripts/reconcile-packages.ps1
  scripts/site-backup-metadata.php
  scripts/target-plugins.php
  scripts/target-plugin-pin.php
  scripts/v8-course-themes.php
  scripts/v8-course-themes.ps1
  scripts/v8-plugin-catalog.php
  scripts/v8-plugin-resolver.ps1
  scripts/v8-preparation.php
  scripts/v8-readiness.ps1
  docker/managed-config.php
  tests/contracts.php
  tests/verify-package.sh
)

verify_engine_complete() {
  local relative missing
  missing=0
  for relative in "${required_engine_files[@]}"; do
    if [[ ! -s "${relative}" ]]; then
      printf 'FALTA_MOTOR %s\n' "${relative}" >&2
      missing=$((missing + 1))
    fi
  done
  [[ "${missing}" -eq 0 ]] ||
    die "El paquete está incompleto: faltan ${missing} archivo(s) del motor."
}

random_hex() {
  od -An -N 24 -tx1 /dev/urandom | tr -d ' \n'
}

random_admin_password() {
  printf 'Mdl9@%s' "$(random_hex)"
}

valid_admin_password() {
  local value="$1"
  [[ ${#value} -ge 12 &&
      "${value}" =~ [[:lower:]] &&
      "${value}" =~ [[:upper:]] &&
      "${value}" =~ [[:digit:]] &&
      "${value}" =~ [._@-] &&
      "${value}" =~ ^[A-Za-z0-9._@-]+$ ]]
}

valid_managed_config_path() {
  local value="$1" reserved
  [[ "${value}" == /* &&
      "${value}" =~ ^/[A-Za-z0-9._/@+-]+(/[A-Za-z0-9._@+-]+)*$ ]] ||
    return 1
  [[ "${value}" != "${project_root}" ]] || return 1
  for reserved in copias config config-manager docker exports reports scripts; do
    [[ "${value}" != "${project_root}/${reserved}" &&
        "${value}" != "${project_root}/${reserved}/"* ]] || return 1
  done
}

require_docker() {
  command -v docker >/dev/null 2>&1 ||
    die "No se encontró Docker Engine."
  docker compose version >/dev/null 2>&1 ||
    die "Se requiere Docker Compose v2: el comando 'docker compose'."
  docker info >/dev/null 2>&1 ||
    die "Docker no responde o el usuario actual no tiene permiso."
}

prepare_compose_environment() {
  local socket_path
  if [[ "${DOCKER_HOST:-}" == unix://* ]]; then
    socket_path="${DOCKER_HOST#unix://}"
  else
    socket_path="/var/run/docker.sock"
  fi
  [[ -S "${socket_path}" ]] ||
    die "No se encontró el socket Docker en ${socket_path}."

  export ASSISTANT_PROJECT_ROOT="${project_root}"
  export ASSISTANT_UID
  export ASSISTANT_GID
  export DOCKER_GID
  export DOCKER_SOCKET_PATH="${socket_path}"
  ASSISTANT_UID="$(id -u)"
  ASSISTANT_GID="$(id -g)"
  DOCKER_GID="$(stat -c '%g' "${socket_path}")"
}

compose() {
  prepare_compose_environment
  [[ "${ASSISTANT_PROJECT_ROOT}" == "${project_root}" ]] ||
    die "El proyecto del asistente no coincide con el paquete invocado."
  docker compose --project-name "${consolidation_compose_project}" "$@"
}

verify_assistant_runtime_mount() {
  local configuration
  configuration="$(compose --profile tools config --format json)" ||
    die "No fue posible inspeccionar assistant-runtime con el perfil tools."
  printf '%s' "${configuration}" | python3 -c '
import json, os, sys
try:
    services = json.load(sys.stdin)["services"]
except (ValueError, KeyError, TypeError):
    sys.exit("ASSISTANT_RUNTIME_CONFIG_INVALID: modelo Compose ilegible o sin servicios")
service = services.get("assistant-runtime") if isinstance(services, dict) else None
if not isinstance(service, dict):
    sys.exit("ASSISTANT_RUNTIME_MISSING: assistant-runtime no aparece con --profile tools")
root = os.environ["ASSISTANT_PROJECT_ROOT"]
volumes = service.get("volumes")
binds = [v for v in volumes if isinstance(v, dict) and v.get("type") == "bind"] if isinstance(volumes, list) else []
if not any(v.get("source") == root and v.get("target") == root for v in binds):
    sys.exit("ASSISTANT_PROJECT_ROOT: bind source/target no coincide con el proyecto invocado")
if service.get("working_dir") != root:
    sys.exit("ASSISTANT_PROJECT_ROOT: working_dir no coincide con el proyecto invocado")
environment = service.get("environment")
if not isinstance(environment, dict) or environment.get("ASSISTANT_PROJECT_ROOT") != root:
    sys.exit("ASSISTANT_PROJECT_ROOT: variable del contenedor no coincide con el proyecto invocado")
' || die "Falló la validación de assistant-runtime con el perfil tools."
}

restore_interrupted_export_ownership() {
  local relative_path container_path restored=0
  for relative_path in exports/phase5 exports/phase6; do
    [[ -d "${relative_path}" ]] || continue
    container_path="/${relative_path}"
    compose exec -T -u root moodle-target sh -ec \
      "chown -R '${ASSISTANT_UID}:www-data' '${container_path}'; chmod -R u=rwX,g=rX,o= '${container_path}'"
    restored=$((restored + 1))
  done
  if (( restored > 0 )); then
    printf 'RECUPERACION_PERMISOS_OK directorios=%s\n' "${restored}"
  fi
}

prepare_script_mount_permissions() {
  [[ -d scripts ]] || die "Falta el directorio scripts/."
  chmod 0755 scripts ||
    die "No fue posible habilitar el recorrido de scripts/ para www-data."
  find scripts -maxdepth 1 -type f -exec chmod 0644 {} + ||
    die "No fue posible habilitar la lectura de los scripts para www-data."
}

verify_script_mount_permissions() {
  local blocked
  blocked="$(find scripts -maxdepth 0 ! -perm -001 -print -quit)"
  [[ -z "${blocked}" ]] ||
    die "scripts/ no permite el recorrido desde el contenedor."
  blocked="$(find scripts -maxdepth 1 -type f ! -perm -004 -print -quit)"
  [[ -z "${blocked}" ]] ||
    die "El contenedor no puede leer ${blocked}."
}

require_configuration() {
  [[ -f .env ]] ||
    die "Falta .env. Ejecute primero ./CONFIGURAR.sh."
  if grep -Eq '(^|=)CHANGE_ME($|[[:space:]])' .env; then
    die ".env todavía contiene valores CHANGE_ME."
  fi
  # El archivo es generado por esta herramienta con un subconjunto seguro de
  # sintaxis dotenv, por lo que puede cargarse para mostrar la URL final.
  set -a
  # shellcheck disable=SC1091
  source ./.env
  set +a
  [[ "${MOODLE_PUBLIC_URL:-}" =~ ^https?:// ]] ||
    die "MOODLE_PUBLIC_URL no es válida."
  MOODLE_INTERNAL_HEALTH_URL="${MOODLE_INTERNAL_HEALTH_URL:-${MOODLE_CONTROL_URL:-}}"
  [[ "${MOODLE_INTERNAL_HEALTH_URL:-}" =~ ^http://127\.0\.0\.1:[0-9]+$ ]] ||
    die "MOODLE_INTERNAL_HEALTH_URL no es válida."
  valid_admin_password "${MOODLE_ADMIN_PASSWORD:-}" ||
    die "MOODLE_ADMIN_PASSWORD debe tener 12 caracteres o más, con minúscula, mayúscula, número y . _ @ o -."
  MOODLE_MANAGED_CONFIG_DIR="${MOODLE_MANAGED_CONFIG_DIR:-${project_root}/managed-config}"
  valid_managed_config_path "${MOODLE_MANAGED_CONFIG_DIR}" ||
    die "MOODLE_MANAGED_CONFIG_DIR debe ser absoluta y no contener espacios."
  export MOODLE_MANAGED_CONFIG_DIR
  export MOODLE_INTERNAL_HEALTH_URL
}

configure() {
  [[ -t 0 ]] ||
    die "La configuración inicial requiere una terminal interactiva."
  if [[ -e .env ]]; then
    die ".env ya existe. No se sobrescribirá una configuración real."
  fi

  local public_url admin_email admin_user admin_password generated_password
  local http_port bind_address reverse_proxy ssl_proxy
  local notifications_enabled notify_to notify_from smtp_host smtp_port smtp_user
  local smtp_password smtp_use_tls smtp_user_b64 smtp_password_b64
  local notifications_answer tls_answer managed_config_dir prepare_answer
  local progress_email_enabled progress_email_interval_minutes progress_email_answer

  printf 'URL pública definitiva del Moodle consolidado: '
  read -r public_url
  public_url="${public_url%/}"
  [[ "${public_url}" =~ ^https?://[A-Za-z0-9._:-]+(/[A-Za-z0-9._~/-]*)?$ ]] ||
    die "Use una URL http(s) sin espacios, parámetros ni fragmentos."

  printf 'Correo del administrador del sitio: '
  read -r admin_email
  [[ "${admin_email}" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] ||
    die "El correo no tiene un formato válido."

  printf 'Usuario administrador [admin]: '
  read -r admin_user
  admin_user="${admin_user:-admin}"
  [[ "${admin_user}" =~ ^[a-z][a-z0-9._-]{2,31}$ ]] ||
    die "El usuario debe usar minúsculas, números, punto, guion o guion bajo."

  printf 'Clave del administrador (vacío para generar una): '
  read -r -s admin_password
  printf '\n'
  generated_password=0
  if [[ -z "${admin_password}" ]]; then
    admin_password="$(random_admin_password)"
    generated_password=1
  fi
  valid_admin_password "${admin_password}" ||
    die "La clave debe tener al menos 12 caracteres, con minúscula, mayúscula, número y . _ @ o -."

  printf 'Puerto HTTP local [8090]: '
  read -r http_port
  http_port="${http_port:-8090}"
  [[ "${http_port}" =~ ^[0-9]{2,5}$ &&
      "${http_port}" -ge 1 &&
      "${http_port}" -le 65535 ]] ||
    die "El puerto debe estar entre 1 y 65535."

  notifications_enabled=0
  notify_to=""
  notify_from=""
  smtp_host=""
  smtp_port=587
  smtp_user=""
  smtp_password=""
  smtp_use_tls=1
  smtp_user_b64=""
  smtp_password_b64=""
  progress_email_enabled=0
  progress_email_interval_minutes=15
  printf '¿Activar correos de estado e intervención? [s/N]: '
  read -r notifications_answer
  case "${notifications_answer,,}" in
    s|si|sí|y|yes)
      notifications_enabled=1
      printf 'Correo que recibirá las notificaciones [%s]: ' "${admin_email}"
      read -r notify_to
      notify_to="${notify_to:-${admin_email}}"
      [[ "${notify_to}" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] ||
        die "El destinatario de notificaciones no tiene un formato válido."

      printf 'Correo remitente autorizado por el servidor SMTP: '
      read -r notify_from
      [[ "${notify_from}" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] ||
        die "El correo remitente no tiene un formato válido."

      printf 'Servidor SMTP: '
      read -r smtp_host
      [[ "${smtp_host}" =~ ^[A-Za-z0-9._:-]+$ ]] ||
        die "El servidor SMTP contiene caracteres no permitidos."
      printf 'Puerto SMTP [587]: '
      read -r smtp_port
      smtp_port="${smtp_port:-587}"
      [[ "${smtp_port}" =~ ^[0-9]{1,5}$ &&
          "${smtp_port}" -ge 1 && "${smtp_port}" -le 65535 ]] ||
        die "El puerto SMTP debe estar entre 1 y 65535."

      printf 'Usuario SMTP (vacío para relay sin autenticación): '
      read -r smtp_user
      if [[ -n "${smtp_user}" ]]; then
        printf 'Clave SMTP: '
        read -r -s smtp_password
        printf '\n'
        [[ -n "${smtp_password}" ]] ||
          die "La clave SMTP no puede quedar vacía cuando hay usuario."
        smtp_user_b64="$(printf '%s' "${smtp_user}" | base64 | tr -d '\n')"
        smtp_password_b64="$(printf '%s' "${smtp_password}" | base64 | tr -d '\n')"
      fi

      printf '¿Usar TLS/STARTTLS? [S/n]: '
      read -r tls_answer
      case "${tls_answer,,}" in
        n|no) smtp_use_tls=0 ;;
        *) smtp_use_tls=1 ;;
      esac

      printf '¿Enviar reportes periódicos durante procesos largos? [S/n]: '
      read -r progress_email_answer
      case "${progress_email_answer,,}" in
        n|no)
          progress_email_enabled=0
          ;;
        *)
          progress_email_enabled=1
          printf 'Intervalo de progreso en minutos [15]: '
          read -r progress_email_interval_minutes
          progress_email_interval_minutes="${progress_email_interval_minutes:-15}"
          [[ "${progress_email_interval_minutes}" =~ ^[0-9]{1,4}$ &&
              "${progress_email_interval_minutes}" -ge 1 &&
              "${progress_email_interval_minutes}" -le 1440 ]] ||
            die "El intervalo debe estar entre 1 y 1440 minutos."
          ;;
      esac
      ;;
    *) notifications_enabled=0 ;;
  esac

  if [[ "${public_url}" == https://* ]]; then
    bind_address="127.0.0.1"
    reverse_proxy=1
    ssl_proxy=1
  else
    bind_address="0.0.0.0"
    reverse_proxy=0
    ssl_proxy=0
  fi

  printf 'Directorio persistente para ajustes de config.php [%s]: ' \
    "${project_root}/managed-config"
  read -r managed_config_dir
  managed_config_dir="${managed_config_dir:-${project_root}/managed-config}"
  valid_managed_config_path "${managed_config_dir}" ||
    die "Use una ruta absoluta segura, sin espacios y fuera de las carpetas operativas."
  mkdir -p "${managed_config_dir}" ||
    die "No fue posible crear ${managed_config_dir}. Prepárelo y repita."
  chmod 0700 "${managed_config_dir}"

  local db_password root_password
  db_password="$(random_hex)"
  root_password="$(random_hex)"

  umask 077
  {
    printf 'MOODLE_PUBLIC_URL=%s\n' "${public_url}"
    # Esta URL solo comprueba el contenedor desde el servidor. No identifica
    # el Moodle ni se escribe en config.yaml.
    printf 'MOODLE_INTERNAL_HEALTH_URL=http://127.0.0.1:%s\n' "${http_port}"
    printf 'MOODLE_BIND_ADDRESS=%s\n' "${bind_address}"
    printf 'MOODLE_HTTP_PORT=%s\n' "${http_port}"
    printf 'MOODLE_SITE_NAME="Moodle consolidado UFPS"\n'
    printf 'MOODLE_SITE_SHORTNAME=CONSOLIDADO\n'
    printf 'MOODLE_ADMIN_USER=%s\n' "${admin_user}"
    printf 'MOODLE_ADMIN_PASSWORD=%s\n' "${admin_password}"
    printf 'MOODLE_ADMIN_EMAIL=%s\n' "${admin_email}"
    printf 'MOODLE_DB_NAME=moodle_target\n'
    printf 'MOODLE_DB_USER=moodle_target\n'
    printf 'MOODLE_DB_PASSWORD=%s\n' "${db_password}"
    printf 'MARIADB_ROOT_PASSWORD=%s\n' "${root_password}"
    printf 'MOODLE_TARGET_IMAGE=moodle-consolidation-target:5.2.1-v8.0.0\n'
    printf 'MOODLE_REVERSE_PROXY=%s\n' "${reverse_proxy}"
    printf 'MOODLE_SSL_PROXY=%s\n' "${ssl_proxy}"
    printf 'MOODLE_MANAGED_CONFIG_DIR=%s\n' "${managed_config_dir}"
    printf 'CONSOLIDATION_AUTO_DELAY_SECONDS=15\n'
    printf 'CONSOLIDATION_EMAIL_ENABLED=%s\n' "${notifications_enabled}"
    printf 'CONSOLIDATION_EMAIL_TO=%s\n' "${notify_to}"
    printf 'CONSOLIDATION_EMAIL_FROM=%s\n' "${notify_from}"
    printf 'CONSOLIDATION_EMAIL_FROM_NAME="Consolidador Moodle"\n'
    printf 'CONSOLIDATION_SMTP_HOST=%s\n' "${smtp_host}"
    printf 'CONSOLIDATION_SMTP_PORT=%s\n' "${smtp_port}"
    printf 'CONSOLIDATION_SMTP_USE_TLS=%s\n' "${smtp_use_tls}"
    printf 'CONSOLIDATION_SMTP_USERNAME_BASE64=%s\n' "${smtp_user_b64}"
    printf 'CONSOLIDATION_SMTP_PASSWORD_BASE64=%s\n' "${smtp_password_b64}"
    printf 'CONSOLIDATION_PROGRESS_EMAIL_ENABLED=%s\n' "${progress_email_enabled}"
    printf 'CONSOLIDATION_PROGRESS_EMAIL_INTERVAL_MINUTES=%s\n' "${progress_email_interval_minutes}"
  } > .env
  chmod 0600 .env

  printf '\nCONFIGURACION_OK\n'
  printf 'Destino público definitivo: %s\n' "${public_url}"
  printf 'Comprobación interna: http://127.0.0.1:%s\n' "${http_port}"
  if [[ "${generated_password}" -eq 1 ]]; then
    printf 'Clave administrativa generada: %s\n' "${admin_password}"
    printf 'Guárdela ahora en el gestor institucional de credenciales.\n'
  fi
  printf 'El archivo .env quedó protegido con permisos 600.\n'
  printf 'Configuración administrada persistente: %s\n' "${managed_config_dir}"
  if [[ "${notifications_enabled}" -eq 1 ]]; then
    printf 'Correos de estado habilitados: %s -> %s.\n' \
      "${notify_from}" "${notify_to}"
  else
    printf 'Correos de estado deshabilitados; pueden activarse editando .env.\n'
  fi

  while true; do
    printf '\n¿Desea ejecutar ahora la siguiente fase, Preparar destino? [S/n]: '
    read -r prepare_answer
    case "${prepare_answer,,}" in
      ""|s|si|sí|y|yes)
        printf '\nContinuando con Preparar destino...\n'
        prepare_target
        return 0
        ;;
      n|no)
        printf '\nConfiguración finalizada. Para continuar, ejecute:\n'
        printf '  ./PREPARAR-DESTINO.sh\n'
        return 0
        ;;
      *)
        printf 'Respuesta no válida. Escriba S o N.\n' >&2
        ;;
    esac
  done
}

wait_for_target() {
  local deadline state health container_id
  deadline=$((SECONDS + 900))
  printf 'Esperando la instalación inicial de Moodle 5.2.1...\n'
  while (( SECONDS < deadline )); do
    container_id="$(compose ps --all --quiet moodle-target 2>/dev/null || true)"
    if [[ -z "${container_id}" ]]; then
      sleep 4
      continue
    fi
    state="$(docker inspect --format '{{.State.Status}}' "${container_id}" 2>/dev/null || true)"
    case "${state}" in
      restarting|exited|dead|removing)
        compose logs --tail=150 moodle-target >&2 || true
        die "moodle-target quedó en estado ${state}."
        ;;
    esac
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
      "${container_id}" 2>/dev/null || true)"
    if [[ "${state}" == "running" && "${health}" == "healthy" ]]; then
      printf 'DESTINO_LISTO url_publica=%s version=5.2.1\n' \
        "${MOODLE_PUBLIC_URL}"
      return 0
    fi
    sleep 8
  done
  compose logs --tail=150 moodle-target >&2 || true
  die "Moodle 5.2.1 no quedó listo dentro de 15 minutos."
}

preflight() {
  verify_engine_complete
  require_docker
  prepare_compose_environment
  local available_kb
  available_kb="$(df -Pk "${project_root}" | awk 'NR==2 {print $4}')"
  [[ "${available_kb}" =~ ^[0-9]+$ ]] ||
    die "No fue posible calcular el espacio disponible."
  if (( available_kb < 20 * 1024 * 1024 )); then
    printf 'ADVERTENCIA: hay menos de 20 GiB libres en el filesystem del proyecto.\n'
  fi
  printf 'PREFLIGHT_OK\n'
  printf 'Docker: %s\n' "$(docker version --format '{{.Client.Version}}')"
  printf 'Compose: %s\n' "$(docker compose version --short)"
  printf 'Proyecto: %s\n' "${project_root}"
  printf 'Espacio libre aproximado: %s GiB\n' "$((available_kb / 1024 / 1024))"
}

prepare_target() {
  require_docker
  require_configuration
  local previous_runtime_tag="${MOODLE_TARGET_IMAGE:-}"
  if [[ "${MOODLE_TARGET_IMAGE:-}" =~ ^moodle-consolidation-target:5\.2\.1-v7\.4\.0-rc([1-9]|1[0-9]|2[0-4])$ ]]; then
    python3 - <<'PY'
from pathlib import Path
import os, tempfile

path = Path('.env')
prefix = b'MOODLE_TARGET_IMAGE=moodle-consolidation-target:5.2.1-v7.4.0-rc'
previous_options = tuple(prefix + str(number).encode() + b'\n' for number in range(1, 25))
updated = b'MOODLE_TARGET_IMAGE=moodle-consolidation-target:5.2.1-v8.0.0\n'
content = path.read_bytes()
matches = [option for option in previous_options if content.count(option) == 1]
if len(matches) != 1:
    raise SystemExit('V8_RUNTIME_IMAGE_INVALID: etiqueta anterior ambigua en .env')
with tempfile.NamedTemporaryFile(prefix='.env.v8.', dir='.', delete=False) as handle:
    temporary = Path(handle.name)
    try:
        os.fchmod(handle.fileno(), 0o600)
        handle.write(content.replace(matches[0], updated))
        handle.flush()
        os.fsync(handle.fileno())
    except BaseException:
        temporary.unlink(missing_ok=True)
        raise
os.replace(temporary, path)
PY
    require_configuration
    printf 'RUNTIME_IMAGE_MIGRATED previous=%s current=v8.0.0\n' "${previous_runtime_tag}"
  fi
  [[ "${MOODLE_TARGET_IMAGE:-}" == 'moodle-consolidation-target:5.2.1-v8.0.0' ]] ||
    die 'V8_RUNTIME_IMAGE_INVALID: el destino debe usar la imagen v8.0.0.'
  preflight
  prepare_script_mount_permissions
  mkdir -p exports reports copias "${MOODLE_MANAGED_CONFIG_DIR}"
  chmod 0700 "${MOODLE_MANAGED_CONFIG_DIR}"
  printf 'Construyendo Moodle 5.2.1 fijado y el runtime del asistente...\n'
  compose build --pull moodle-target assistant-runtime
  printf 'Inicializando la configuración declarativa externa...\n'
  ./GESTIONAR-CONFIG.sh inicializar
  ./GESTIONAR-CONFIG.sh asegurar-email-compartido
  printf 'Iniciando base de datos y destino vacío...\n'
  compose up -d --force-recreate db
  printf 'Ejecutando la instalación inicial en primer plano...\n'
  if ! compose run --rm --no-deps moodle-target true; then
    die "Falló la instalación inicial. La causa quedó visible arriba; no se inició el servidor Moodle."
  fi
  printf 'Iniciando el servidor Moodle con la base ya instalada...\n'
  compose up -d --no-deps --force-recreate moodle-target
  wait_for_target

  # /exports es un bind mount compartido entre el operador y procesos
  # Moodle ejecutados como www-data. El directorio raíz debe conservar
  # propietario del operador, grupo www-data, escritura de grupo y setgid.
  # Se aplica desde el propio contenedor como root para no depender de sudo
  # ni de que el operador pertenezca al grupo 33 en el host.
  local export_owner_uid="${ASSISTANT_UID:-$(id -u)}"

  compose exec -T -u root moodle-target sh -ec \
    "chown '${export_owner_uid}:www-data' /exports && chmod 2770 /exports" ||
    die "EXPORT_TRANSPORT_FAILED: no fue posible preparar /exports."

  compose exec -T -u www-data moodle-target sh -ec \
    'test -x /exports && test -w /exports' ||
    die "EXPORT_TRANSPORT_FAILED: www-data no puede atravesar/escribir /exports."

  printf 'EXPORT_TRANSPORT_OK owner_uid=%s group=www-data mode=2770\n' \
    "${export_owner_uid}"

  local policy_result policy_report
  policy_result="$(compose exec -T -u www-data moodle-target \
    php /opt/consolidator/target-shared-email-policy.php)" ||
    die "TARGET_SHARED_EMAIL_POLICY_FAILED: Moodle no permite correos compartidos."
  [[ "${policy_result}" == 'TARGET_SHARED_EMAIL_POLICY_OK setting=allowaccountssameemail expected=1 actual=1 status=OK' ]] ||
    die "TARGET_SHARED_EMAIL_POLICY_FAILED: respuesta inesperada del destino."
  policy_report="reports/.target-shared-email-policy.$$"
  python3 - "${MOODLE_MANAGED_CONFIG_DIR}/active/settings.json" > "${policy_report}" <<'PY'
import hashlib, json, pathlib, sys
settings_path = pathlib.Path(sys.argv[1])
settings = json.loads(settings_path.read_text())['settings']
if settings.get('allowaccountssameemail') != 1 or isinstance(settings.get('allowaccountssameemail'), bool):
    sys.exit('TARGET_SHARED_EMAIL_POLICY_FAILED: configuración declarativa discrepante')
print(json.dumps({
    'setting': 'allowaccountssameemail', 'expected': 1, 'actual': 1,
    'status': 'OK', 'managed_settings_sha256': hashlib.sha256(settings_path.read_bytes()).hexdigest()
}, indent=2, sort_keys=True))
PY
  mv -f "${policy_report}" reports/target-shared-email-policy.json
  printf '%s\n' "${policy_result}"
}

run_assistant() {
  local execution_mode="${1:-interactive}"
  local assistant_lock_fd assistant_exit=0
  local progress_notifier_pid=""
  local progress_active_file=""
  local progress_log_path=""
  verify_engine_complete
  require_docker
  require_configuration
  prepare_compose_environment
  command -v flock >/dev/null 2>&1 ||
    die "Se requiere flock para impedir ejecuciones simultáneas."
  mkdir -p reports
  exec {assistant_lock_fd}>reports/assistant-run.lock
  flock -n "${assistant_lock_fd}" ||
    die "Ya existe otra ejecución interactiva o en segundo plano."
  local zip_files
  shopt -s nullglob
  zip_files=(copias/*.zip)
  shopt -u nullglob
  [[ ${#zip_files[@]} -ge 2 ]] ||
    die "Se requieren al menos 2 ZIP sellados en copias; se encontraron ${#zip_files[@]}."
  [[ ${#zip_files[@]} -le 32 ]] ||
    die "Se admiten como máximo 32 ZIP por ejecución; se encontraron ${#zip_files[@]}."
  prepare_script_mount_permissions
  wait_for_target
  restore_interrupted_export_ownership
  verify_assistant_runtime_mount
  compose build assistant-runtime

  # RC11: durante la ejecución interactiva el notifier observa
  # únicamente snapshots; no participa en decisiones del asistente.
  if [[ "${execution_mode}" == "interactive" ]]; then
    progress_active_file="${project_root}/reports/progress-notifier.active"
    progress_log_path="${project_root}/reports/progress-notifier.log"

    : > "${progress_active_file}"

    compose --profile tools run       --rm       --no-deps       -T       assistant-runtime       pwsh       -NoLogo       -NoProfile       -File ./scripts/progress-notifier.ps1       -Loop       -PollSeconds 15       >>"${progress_log_path}" 2>&1 &

    progress_notifier_pid=$!

    printf 'PROGRESS_NOTIFIER_OK mode=interactive pid=%s\n'       "${progress_notifier_pid}"
  fi

  set +e
  if [[ "${execution_mode}" == "automatic" ]]; then
    compose run --rm --no-deps \
      -e "CONSOLIDATION_MASS_EXECUTION_MODE=background" \
      -e "CONSOLIDATION_WORKERS=${CONSOLIDATION_WORKERS}" assistant-runtime \
      pwsh -NoLogo -NoProfile -File scripts/consolidation-wizard.ps1 \
      -Automatic
    assistant_exit=$?
  else
    compose run --rm --no-deps \
      -e "CONSOLIDATION_WORKERS=${CONSOLIDATION_WORKERS}" assistant-runtime \
      pwsh -NoLogo -NoProfile -File scripts/consolidation-wizard.ps1
    assistant_exit=$?
  fi
  set -e

  # El notifier interactivo debe terminar completamente ANTES
  # de transferir la ejecución al runner background.
  if [[ -n "${progress_notifier_pid}" ]]; then
    rm -f -- "${progress_active_file}"
    wait "${progress_notifier_pid}" 2>/dev/null || true

    printf 'PROGRESS_NOTIFIER_STOPPED mode=interactive\n'

    progress_notifier_pid=""
  fi

  if [[ ${assistant_exit} -eq 24 && "${execution_mode}" == "interactive" ]]; then
    exec {assistant_lock_fd}>&-
    start_background
    return 0
  fi
  return "${assistant_exit}"
}

apply_identity_review() {
  verify_engine_complete
  require_docker
  require_configuration
  prepare_compose_environment
  prepare_script_mount_permissions
  verify_assistant_runtime_mount
  compose build assistant-runtime
  compose run --rm --no-deps assistant-runtime \
    pwsh -NoLogo -NoProfile -File scripts/v8-identity-review.ps1 -Mode Apply
}

background_unit_name() {
  local suffix
  suffix="$(printf '%s' "${project_root}" | sha256sum | cut -c1-12)"
  printf 'moodle-consolidation-%s' "${suffix}"
}

background_running() {
  local unit pid
  if [[ -s reports/background-unit.txt ]]; then
    unit="$(sed -n '1p' reports/background-unit.txt)"
    if [[ "${unit}" =~ ^moodle-consolidation-[a-f0-9]{12}$ ]] &&
        systemctl --user is-active --quiet "${unit}.service" 2>/dev/null; then
      return 0
    fi
  fi
  if [[ -s reports/background.pid ]]; then
    pid="$(sed -n '1p' reports/background.pid)"
    if [[ "${pid}" =~ ^[0-9]+$ ]] && kill -0 "${pid}" 2>/dev/null; then
      return 0
    fi
  fi
  return 1
}

start_background() {
  verify_engine_complete
  require_docker
  require_configuration
  prepare_compose_environment
  mkdir -p reports
  if background_running; then
    die "Ya existe una ejecución del consolidador en segundo plano."
  fi

  local unit log_path linger_value current_user
  unit="$(background_unit_name)"
  log_path="${project_root}/reports/consolidacion-segundo-plano.log"
  current_user="$(id -un)"
  if command -v systemd-run >/dev/null 2>&1 &&
      systemctl --user show-environment >/dev/null 2>&1; then

    if ! command -v loginctl >/dev/null 2>&1; then
      die "SEGUNDO_PLANO_PERSISTENCIA_NO_VERIFICABLE: falta loginctl; no se iniciará una ejecución que pueda depender de la sesión SSH."
    fi

    linger_value="$(
      loginctl show-user "${current_user}" -p Linger --value 2>/dev/null ||
        true
    )"

    if [[ "${linger_value}" != "yes" ]]; then
      printf 'SEGUNDO_PLANO_BLOQUEADO reason=linger_required user=%s linger=%s\n' \
        "${current_user}" "${linger_value:-unknown}" >&2
      printf 'Habilite persistencia con: sudo loginctl enable-linger %q\n' \
        "${current_user}" >&2
      die "Linger debe ser yes antes de iniciar la consolidación con systemd --user."
    fi

    if systemd-run --user \
        --unit="${unit}" \
        --collect \
        --property=Restart=no \
        --setenv="CONSOLIDATION_WORKERS=${CONSOLIDATION_WORKERS}" \
        --working-directory="${project_root}" \
        /usr/bin/env bash \
          "${project_root}/scripts/run-background-with-progress.sh" \
          "--workers=${CONSOLIDATION_WORKERS}"; then
      printf '%s\n' "${unit}" > reports/background-unit.txt
      : > reports/background.pid
      printf 'SEGUNDO_PLANO_OK runner=systemd unit=%s linger=yes\n' "${unit}.service"
      printf 'Estado: ./ESTADO.sh\n'
      printf 'Eventos: reports/asistente-consolidacion.log\n'
      printf 'Log completo: journalctl --user -u %s.service -f\n' "${unit}"
      return 0
    fi
    printf 'ADVERTENCIA: systemd-run falló; se usará nohup.\n' >&2
  fi

  nohup /usr/bin/env bash \
    "${project_root}/scripts/run-background-with-progress.sh" \
    "--workers=${CONSOLIDATION_WORKERS}" \
    >>"${log_path}" 2>&1 </dev/null &
  printf '%s\n' "$!" > reports/background.pid
  : > reports/background-unit.txt
  printf 'SEGUNDO_PLANO_OK runner=nohup pid=%s\n' "$!"
  printf 'Estado: ./ESTADO.sh\n'
  printf 'Log: %s\n' "${log_path}"
}

stop_background() {
  local unit pid
  if [[ -s reports/background-unit.txt ]]; then
    unit="$(sed -n '1p' reports/background-unit.txt)"
    if [[ "${unit}" =~ ^moodle-consolidation-[a-f0-9]{12}$ ]]; then
      systemctl --user stop "${unit}.service" 2>/dev/null || true
    fi
  fi
  if [[ -s reports/background.pid ]]; then
    pid="$(sed -n '1p' reports/background.pid)"
    if [[ "${pid}" =~ ^[0-9]+$ ]] && kill -0 "${pid}" 2>/dev/null; then
      kill -TERM "${pid}"
    fi
  fi
}

status() {
  require_docker
  require_configuration
  compose --profile live ps --all
  printf '\nEjecución del asistente:\n'
  if background_running; then
    printf '  segundo plano: activo\n'
  else
    printf '  segundo plano: inactivo o finalizado\n'
  fi
  if [[ -f reports/assistant-state.json ]]; then
    printf '\nÚltimo estado del asistente:\n'
    sed -n '1,120p' reports/assistant-state.json
  fi
}

show_logs() {
  require_docker
  require_configuration
  compose --profile live logs --tail=150 db moodle-target moodle-cron
  if [[ -f reports/asistente-consolidacion.log ]]; then
    printf '\nÚltimos eventos del asistente:\n'
    tail -n 80 reports/asistente-consolidacion.log
  fi
  if [[ -f reports/consolidacion-segundo-plano.log ]]; then
    printf '\nÚltimo log del runner nohup:\n'
    tail -n 100 reports/consolidacion-segundo-plano.log
  fi
  if [[ -s reports/background-unit.txt ]]; then
    local unit
    unit="$(sed -n '1p' reports/background-unit.txt)"
    if [[ "${unit}" =~ ^moodle-consolidation-[a-f0-9]{12}$ ]]; then
      journalctl --user -u "${unit}.service" -n 100 --no-pager 2>/dev/null || true
    fi
  fi
}

stop_services() {
  require_docker
  require_configuration
  stop_background
  local id labels container_project container_service container_oneoff removed=0
  local container_output
  local -a assistant_containers=()
  container_output="$(docker ps --all --quiet --no-trunc \
    --filter "label=com.docker.compose.project=${consolidation_compose_project}" \
    --filter 'label=com.docker.compose.service=assistant-runtime')" ||
    die 'No se pudieron inventariar los contenedores auxiliares del proyecto.'
  if [[ -n "${container_output}" ]]; then
    mapfile -t assistant_containers <<< "${container_output}"
  fi
  for id in "${assistant_containers[@]}"; do
    [[ "${id}" =~ ^[a-fA-F0-9]{12,64}$ ]] ||
      die "Docker devolvió un ID de contenedor inválido: ${id}."
    labels="$(docker inspect --format \
      '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "com.docker.compose.oneoff"}}' \
      "${id}" 2>/dev/null)" || continue
    IFS='|' read -r container_project container_service container_oneoff <<< "${labels}"
    [[ "${container_project}" == "${consolidation_compose_project}" &&
        "${container_service}" == assistant-runtime &&
        "${container_oneoff,,}" == true ]] || continue
    if docker rm --force "${id}" >/dev/null 2>&1; then
      removed=$((removed + 1))
    elif docker inspect "${id}" >/dev/null 2>&1; then
      die "No fue posible retirar el contenedor auxiliar ${id}."
    fi
  done
  compose --profile live stop moodle-cron moodle-target db
  printf 'SERVICIOS_DETENIDOS assistant_oneoff_retirados=%s volúmenes_y_resultados_conservados=1\n' "${removed}"
}

publish_site() {
  require_docker
  require_configuration
  [[ -f exports/phase7/closure_summary.json ]] ||
    die "La consolidación todavía no tiene un cierre aprobado de fase 7."
  grep -Eq '"closure_status"[[:space:]]*:[[:space:]]*"evidence_consolidated"' \
    exports/phase7/closure_summary.json ||
    die "El cierre de fase 7 no está en estado evidence_consolidated."
  [[ -f exports/phase4/verification.json ]] ||
    die "Falta la verificación de usuarios y accesos OAuth2."
  grep -Eq '"oauth2_links_failed"[[:space:]]*:[[:space:]]*0' \
    exports/phase4/verification.json ||
    die "La verificación de usuarios conserva vínculos OAuth2 pendientes."
  [[ -f exports/phase8/site_package_summary.json &&
      -f exports/phase8/paquete-sitio-consolidado.zip &&
      -f exports/phase8/paquete-sitio-consolidado.sha256.txt ]] ||
    die "La copia integral de fase 8 todavía no está completa."
  grep -Eq '"status"[[:space:]]*:[[:space:]]*"sealed"' \
    exports/phase8/site_package_summary.json ||
    die "La copia integral de fase 8 no está sellada."
  (
    cd exports/phase8
    sha256sum --check paquete-sitio-consolidado.sha256.txt
  ) || die "El SHA-256 de la copia integral no coincide."
  ./GESTIONAR-CONFIG.sh verificar ||
    die "La configuración administrada no superó la verificación previa a publicación."
  wait_for_target
  compose run --rm --no-deps assistant-runtime \
    pwsh -NoLogo -NoProfile -File scripts/oauth2-validate.ps1 \
      -Quiet -LiveCheck ||
    die "OAuth2 dejó de estar operativo; no se publicará el sitio."
  compose --profile live up -d --no-deps moodle-cron
  printf 'SITIO_PUBLICADO cron_normal_activo=1 url=%s\n' "${MOODLE_PUBLIC_URL}"
}

verify_files() {
  [[ -f FILES.sha256 ]] || die "Falta FILES.sha256."
  sha256sum --check FILES.sha256
  verify_engine_complete
  verify_script_mount_permissions
  printf 'INTEGRIDAD_OK\n'
}

usage() {
  cat <<'EOF'
Uso: ./moodle-consolidation.sh COMANDO [--workers=auto|1|2|3|4]

  preflight    Verifica Docker, Compose, permisos y espacio.
  configurar   Crea .env de producción sin sobrescribir uno existente.
  preparar     Construye e instala el Moodle 5.2.1 nuevo en este servidor.
  iniciar      Inicia o reanuda interactivamente las 16 etapas.
  aplicar-revision-identidades
               Importa el CSV fuzzy dentro de assistant-runtime (sin pwsh en el host).
  iniciar-segundo-plano
               Ejecuta automáticamente con systemd-run o nohup.
  estado       Muestra contenedores y checkpoint del asistente.
  logs         Muestra los últimos logs operativos.
  publicar     Activa el cron normal después del cierre aprobado.
  config       Muestra la ayuda del gestor autónomo de config.php.
  detener      Detiene servicios y conserva volúmenes/resultados.
  verificar    Valida la integridad de los archivos distribuidos.

Workers:
  auto          Considera CPU y memoria (aprox. 2 GiB por worker), máximo 4.
  1..4          Fuerza el número de restauraciones de cursos simultáneas.
EOF
}

command_name="${1:-ayuda}"
if (($#)); then
  shift
fi
parse_runtime_options "$@"
case "${command_name}" in
  preflight) preflight ;;
  configurar) configure ;;
  preparar) prepare_target ;;
  iniciar|reanudar) run_assistant interactive ;;
  aplicar-revision-identidades) apply_identity_review ;;
  iniciar-segundo-plano|reanudar-segundo-plano) start_background ;;
  ejecutar-automatico) run_assistant automatic ;;
  estado) status ;;
  logs) show_logs ;;
  publicar) publish_site ;;
  config) ./GESTIONAR-CONFIG.sh ayuda ;;
  detener) stop_services ;;
  verificar) verify_files ;;
  ayuda|-h|--help) usage ;;
  *) usage; die "Comando desconocido: ${command_name}" ;;
esac
