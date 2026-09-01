#!/usr/bin/env bash
set -euo pipefail
umask 007

readonly tool_version="1.1.5-linux"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
script_path="$script_dir/$(basename "${BASH_SOURCE[0]}")"

usage() {
  cat <<'EOF'
Integrador Incremental Moodle v1.1.5

Uso:
  ./INTEGRAR.sh [opciones] paquete-recolector.zip

Opciones:
  --background                    Continúa mediante una unidad systemd.
  --workers=auto|1|2|3|4         auto=min(CPU disponibles,4). Predeterminado: auto.
  --consolidador-dir=RUTA         Consolidador 7.3.0 que contiene el Moodle destino.
  --prebackup=auto                Copia integral previa dentro de evidencias. Predeterminado.
  --prebackup=existing:RUTA       Usa una copia integral existente indicada explícitamente.
  --internal-run                  Uso interno del lanzador background.
  -h, --help                      Muestra esta ayuda.

Ejemplo recomendado:
  sudo ./INTEGRAR.sh --background --workers=auto \
    --consolidador-dir="$HOME/Descargas/Consolidador-v7.3.0-linux/Consolidador" \
    paquetes/origen-nuevo.zip

El paquete debe ser un ZIP sellado por Recolector 7.4.1. El destino debe haber
sido creado/publicado por Consolidador 7.3.0 estable o RC4 y conservar
compose.yaml/.env.
EOF
}

fail() {
  echo "INCREMENTAL_ERROR $*" >&2
  exit 1
}

mode="foreground"
workers="auto"
consolidator_dir="${MOODLE_CONSOLIDATOR_DIR:-}"
prebackup="auto"
package_input=""

while (( $# > 0 )); do
  case "$1" in
    --background)
      mode="background-launcher"
      ;;
    --internal-run)
      mode="background"
      ;;
    --workers=*)
      workers="${1#*=}"
      ;;
    --consolidador-dir=*)
      consolidator_dir="${1#*=}"
      ;;
    --prebackup=*)
      prebackup="${1#*=}"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      echo "Opción no reconocida: $1" >&2
      usage >&2
      exit 2
      ;;
    *)
      [[ -z "$package_input" ]] || {
        echo "Solo se admite un paquete por ejecución." >&2
        exit 2
      }
      package_input="$1"
      ;;
  esac
  shift
done

[[ "$workers" == "auto" || "$workers" =~ ^[1-4]$ ]] || \
  fail "--workers debe ser auto o un número entre 1 y 4."
if (( BASH_VERSINFO[0] < 5 ||
      (BASH_VERSINFO[0] == 5 && BASH_VERSINFO[1] < 1) )); then
  fail "Se requiere Bash 5.1 o posterior para la cola reanudable."
fi
[[ -n "$package_input" ]] || {
  usage >&2
  exit 2
}
[[ "$package_input" == *.zip ]] || fail "El paquete debe terminar en .zip."
[[ -f "$package_input" && -r "$package_input" ]] || \
  fail "No se puede leer el paquete: $package_input"
[[ -n "$consolidator_dir" ]] || \
  fail "Indique --consolidador-dir=/ruta/al/Consolidador-7.3.0."
[[ -d "$consolidator_dir" ]] || fail "No existe $consolidator_dir."
consolidator_dir="$(cd "$consolidator_dir" && pwd -P)"
export ASSISTANT_PROJECT_ROOT="$consolidator_dir"
package_dir="$(cd "$(dirname "$package_input")" && pwd -P)"
package_path="$package_dir/$(basename "$package_input")"
[[ -f "$consolidator_dir/compose.yaml" && -f "$consolidator_dir/.env" ]] || \
  fail "El directorio no contiene compose.yaml y .env configurado."
consolidator_version="$(head -n 1 "$consolidator_dir/VERSION.txt" 2>/dev/null || true)"
[[ "$consolidator_version" =~ ^7\.3\.0-linux(-rc4)?$ ]] || \
  fail "El destino debe corresponder a Consolidador 7.3.0-linux o 7.3.0-linux-rc4."
[[ "$prebackup" == "auto" || "$prebackup" == existing:* ]] || \
  fail "--prebackup admite auto o existing:RUTA."
if [[ "$prebackup" == existing:* ]]; then
  existing_candidate="${prebackup#existing:}"
  [[ -f "$existing_candidate" && -r "$existing_candidate" ]] || \
    fail "No se puede leer la copia previa indicada: $existing_candidate"
  existing_candidate="$(cd "$(dirname "$existing_candidate")" && pwd -P)/$(basename "$existing_candidate")"
  prebackup="existing:$existing_candidate"
fi

for command_name in docker python3 sha256sum gzip tar timeout; do
  command -v "$command_name" >/dev/null 2>&1 || \
    fail "No se encontró el comando requerido: $command_name"
done
docker compose version >/dev/null 2>&1 || fail "Se requiere Docker Compose v2."
docker info >/dev/null 2>&1 || fail "Docker no está iniciado o el usuario no tiene acceso."
(( EUID == 0 )) || \
  fail "Ejecute el Integrador con sudo; al terminar devolverá los archivos al usuario invocante."

package_slug="$(basename "${package_path%.zip}" | tr '[:upper:]' '[:lower:]' | \
  sed -E 's/[^a-z0-9_-]+/-/g; s/^-+//; s/-+$//')"
[[ -n "$package_slug" ]] || package_slug="paquete"
package_slug="${package_slug:0:40}"
run_id="run-$package_slug"
unit_name="moodle-integrador-$package_slug"
integrator_host_root="$consolidator_dir/exports/integrator"
integrator_container_root="/exports/integrator"
work_host="$integrator_host_root/$run_id"
work_container="/exports/integrator/$run_id"
input_host="$work_host/input/source.zip"
input_container="$work_container/input/source.zip"
log_host="$work_host/integrador.log"
status_host="$work_host/status.json"

owner_uid="${INTEGRATOR_OWNER_UID:-}"
owner_gid="${INTEGRATOR_OWNER_GID:-}"
if [[ ! "$owner_uid" =~ ^[0-9]+$ || ! "$owner_gid" =~ ^[0-9]+$ ]]; then
  if [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    owner_uid="$(id -u "$SUDO_USER")"
    owner_gid="$(id -g "$SUDO_USER")"
  else
    owner_uid="$(id -u)"
    owner_gid="$(id -g)"
  fi
fi

if [[ "$mode" == "background-launcher" ]]; then
  (( EUID == 0 )) || fail "Repita --background con sudo."
  command -v systemd-run >/dev/null 2>&1 || fail "No se encontró systemd-run."
  if systemctl is-active --quiet "$unit_name.service"; then
    fail "Ya existe una integración activa: $unit_name.service"
  fi
  args=(
    "$script_path"
    --internal-run
    "--workers=$workers"
    "--consolidador-dir=$consolidator_dir"
    "--prebackup=$prebackup"
    "$package_path"
  )
  systemd-run \
    --unit="$unit_name" \
    --description="Integrador Incremental Moodle $package_slug" \
    --property="WorkingDirectory=$script_dir" \
    --setenv="INTEGRATOR_OWNER_UID=$owner_uid" \
    --setenv="INTEGRATOR_OWNER_GID=$owner_gid" \
    --collect \
    --service-type=exec \
    "${args[@]}"
  echo "INCREMENTAL_STARTED unit=$unit_name.service"
  echo "Seguimiento: sudo journalctl -u $unit_name.service -f"
  echo "Estado:      $status_host"
  exit 0
fi

mkdir -p "$work_host/input"
touch "$log_host"
exec > >(tee -a "$log_host") 2>&1

compose() {
  docker compose \
    --env-file "$consolidator_dir/.env" \
    --project-directory "$consolidator_dir" \
    -f "$consolidator_dir/compose.yaml" \
    "$@"
}

started_epoch="$(date +%s)"
current_stage="preflight"
maintenance_changed=0
maintenance_was="0"
cron_was_running=0
integrity_uncertain=0
target_container=""
db_container=""

write_status() {
  local state="$1" stage="$2" message="${3:-}" exit_code="${4:-0}"
  local tmp="$status_host.tmp.$$" duration
  duration=$(( $(date +%s) - started_epoch ))
  python3 - "$tmp" "$tool_version" "$state" "$stage" "$message" \
    "$exit_code" "$duration" "$package_path" "$work_host" "$log_host" \
    "$workers" <<'PY'
import json, os, sys, datetime
path, version, state, stage, message, code, duration, package, work, log, workers = sys.argv[1:]
data = {
    "schema_version": "1.0",
    "tool_version": version,
    "state": state,
    "stage": stage,
    "message": message,
    "exit_code": int(code),
    "duration_seconds": int(duration),
    "package": package,
    "work_directory": work,
    "log_file": log,
    "workers_requested": workers,
    "updated_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(data, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
os.chmod(path, 0o660)
PY
  mv -f "$tmp" "$status_host"
}

restore_runtime() {
  local allow_restore="$1"
  set +e
  if (( maintenance_changed == 1 )) && [[ -n "$target_container" ]] && \
      docker inspect "$target_container" >/dev/null 2>&1; then
    if (( allow_restore == 1 )); then
      if [[ "$maintenance_was" == "0" ]]; then
        timeout --foreground 30s docker exec -u www-data "$target_container" \
          php /var/www/html/admin/cli/maintenance.php --disable >/dev/null 2>&1
      fi
      if (( cron_was_running == 1 )); then
        compose --profile live up -d moodle-cron >/dev/null 2>&1
      fi
      echo "INCREMENTAL_RUNTIME_RESTORED maintenance_original=$maintenance_was cron_restarted=$cron_was_running"
      python3 - "$work_host/runtime-state.json" <<'PY' 2>/dev/null || true
import datetime, json, os, sys
path = sys.argv[1]
try:
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    data["restored"] = True
    data["restored_at_utc"] = datetime.datetime.now(datetime.timezone.utc).isoformat()
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    os.replace(tmp, path)
except Exception:
    pass
PY
    else
      echo "INCREMENTAL_RUNTIME_HELD maintenance=1 reason=integrity_uncertain" >&2
    fi
  fi
  if [[ -n "$target_container" ]] && \
      docker inspect "$target_container" >/dev/null 2>&1; then
    timeout --foreground 30s docker exec -u root "$target_container" sh -lc \
      "chown -R $owner_uid:$owner_gid '$work_container' && chmod -R u=rwX,go= '$work_container'; chown $owner_uid:www-data '$integrator_container_root'; chmod 0770 '$integrator_container_root'" \
      >/dev/null 2>&1 || true
  fi
  chown -R "$owner_uid:$owner_gid" "$work_host" >/dev/null 2>&1 || true
}

finish() {
  local exit_code=$?
  trap - EXIT INT TERM
  if (( exit_code == 0 )); then
    restore_runtime 1
    write_status "completed" "completed" \
      "Integración verificada; cursos y categoría permanecen ocultos." 0
  else
    local safe=1
    if [[ -d "$work_host/diagnostics" ]]; then
      safe="$(python3 - "$work_host/diagnostics" <<'PY'
import glob, json, os, sys
safe = True
for path in glob.glob(os.path.join(sys.argv[1], "*.json")):
    try:
        with open(path, encoding="utf-8") as handle:
            if not json.load(handle).get("safe_to_retry", False):
                safe = False
    except Exception:
        safe = False
print(1 if safe else 0)
PY
)"
    fi
    if (( integrity_uncertain == 1 || safe == 0 )); then
      restore_runtime 0
    else
      restore_runtime 1
    fi
    write_status "error" "$current_stage" \
      "La ejecución falló; revise el log y repita exactamente el mismo comando." \
      "$exit_code"
  fi
  chown "$owner_uid:$owner_gid" "$status_host" "$log_host" \
    >/dev/null 2>&1 || true
  exit "$exit_code"
}
trap finish EXIT
trap 'exit 130' INT TERM

echo "INCREMENTAL_START version=$tool_version mode=$mode package=$package_path"
echo "INCREMENTAL_PATHS consolidator=$consolidator_dir work=$work_host log=$log_host"
write_status "running" "$current_stage" "Iniciando preflight." 0

current_stage="target-ready"
compose up -d db moodle-target
target_container="$(compose ps -q moodle-target)"
db_container="$(compose ps -q db)"
[[ -n "$target_container" ]] || fail "No se encontró el contenedor moodle-target."
[[ -n "$db_container" ]] || fail "No se encontró el contenedor db."
for attempt in $(seq 1 90); do
  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}running{{end}}' \
    "$target_container" 2>/dev/null || true)"
  [[ "$health" == "healthy" || "$health" == "running" ]] && break
  [[ "$health" != "unhealthy" ]] || fail "moodle-target está unhealthy."
  (( attempt < 90 )) || fail "moodle-target no quedó listo."
  sleep 4
done
echo "INCREMENTAL_TARGET_READY target_container=$target_container db_container=$db_container health=$health"

current_stage="install-runtime-once"
write_status "running" "$current_stage" "Instalando el runtime mediante Docker directo." 0
echo "INCREMENTAL_RUNTIME_INSTALL_START container=$target_container"
timeout --foreground 60s docker exec -u root "$target_container" sh -lc \
  'rm -rf /opt/integrator-v1 && install -d -m 0755 /opt/integrator-v1'
timeout --foreground 60s docker cp "$script_dir/scripts/." "$target_container:/opt/integrator-v1/"
timeout --foreground 60s docker exec -u root "$target_container" sh -lc \
  'find /opt/integrator-v1 -type d -exec chmod 0755 {} +; find /opt/integrator-v1 -type f -exec chmod 0644 {} +'
timeout --foreground 60s docker exec -u root "$target_container" sh -lc \
  "install -d -o $owner_uid -g www-data -m 0770 '$integrator_container_root'; chown $owner_uid:www-data '$integrator_container_root'; chmod 0770 '$integrator_container_root'; install -d -o www-data -g www-data -m 0770 '$work_container' '$work_container/input'; chown -R www-data:www-data '$work_container'; find '$work_container' -type d -exec chmod 0770 {} +; find '$work_container' -type f -exec chmod 0660 {} +"

timeout --foreground 60s docker exec -u www-data "$target_container" php -r \
  '$path=$argv[1]; if (!is_dir($path) || !is_writable($path)) { fwrite(STDERR, "INCREMENTAL_WORKDIR_NOT_WRITABLE $path\n"); exit(1); }' \
  "$work_container"
echo "INCREMENTAL_RUNTIME_INSTALL_OK container=$target_container"

resume_ready="$(python3 - "$work_host" "$package_path" <<'PY'
import hashlib, json, os, sys
root, source = sys.argv[1:]
def file_sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()
try:
    with open(os.path.join(root, "validation.json"), encoding="utf-8") as handle:
        validation = json.load(handle)
    with open(os.path.join(root, "plan.json"), encoding="utf-8") as handle:
        plan = json.load(handle)
    with open(os.path.join(root, "package", "manifest.json"), encoding="utf-8") as handle:
        manifest = json.load(handle)
    stat = os.stat(source)
    staged = os.path.join(root, "input", "source.zip")
    outer_zip = validation.get("outer_zip", {})
    expected_package_sha = outer_zip.get("sha256", "")
    expected_package_bytes = int(outer_zip.get("bytes", -1))
    plan_for_hash = dict(plan)
    expected_plan_hash = plan_for_hash.pop("plan_sha256", "")
    calculated_plan_hash = hashlib.sha256(json.dumps(
        plan_for_hash,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")).hexdigest()
    ready = (
        validation.get("result") == "ok"
        and plan.get("status") == "applicable"
        and len(expected_plan_hash) == 64
        and expected_plan_hash == calculated_plan_hash
        and len(expected_package_sha) == 64
        and expected_package_bytes >= 0
        and plan.get("package_sha256") == expected_package_sha
        and manifest.get("collector_version") == "7.4.1-linux"
        and stat.st_size == expected_package_bytes
        and file_sha256(source) == expected_package_sha
        and os.path.isfile(staged)
        and os.path.getsize(staged) == expected_package_bytes
        and file_sha256(staged) == expected_package_sha
        and file_sha256(os.path.join(root, "target-snapshot.json")) ==
            plan.get("target_snapshot_sha256")
    )
except Exception:
    ready = False
print(1 if ready else 0)
PY
)"

if [[ "$resume_ready" != "1" && ( -f "$work_host/plan.json" ||
      -f "$work_host/category-map.json" || -d "$work_host/states" ||
      -d "$work_host/checkpoints" ) ]]; then
  previous_sha="$(python3 - "$work_host/validation.json" <<'PY'
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        print(json.load(handle).get("outer_zip", {}).get("sha256", ""))
except Exception:
    print("")
PY
)"
  current_sha="$(sha256sum "$package_path" | awk '{print $1}')"
  if [[ "$previous_sha" =~ ^[a-f0-9]{64}$ && "$current_sha" != "$previous_sha" ]]; then
    fail "Este nombre de ZIP ya está ligado a otro paquete. Use otro nombre o restaure el ZIP original."
  fi
  fail "El directorio de reanudación existe pero perdió integridad; no se iniciará otro lote sobre él."
fi

current_stage="stage-package"
if [[ "$resume_ready" == "1" ]]; then
  echo "INCREMENTAL_FAST_RESUME_OK validation=trusted extraction=reused plan=reused"
elif [[ ! -f "$input_host" ]] || ! cmp -s "$package_path" "$input_host"; then
  temporary_input="$input_host.tmp.$$"
  cp --reflink=auto "$package_path" "$temporary_input"
  chmod 0660 "$temporary_input"
  mv -f "$temporary_input" "$input_host"
fi
if [[ "$resume_ready" != "1" ]]; then
  python3 - "$work_host/input/source-metadata.json" "$package_path" <<'PY'
import json, os, sys
path, source = sys.argv[1:]
stat = os.stat(source)
data = {
    "schema_version": "1.0",
    "source_path": source,
    "source_bytes": stat.st_size,
    "source_mtime_ns": stat.st_mtime_ns,
}
tmp = path + ".tmp"
with open(tmp, "w", encoding="utf-8") as handle:
    json.dump(data, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
os.replace(tmp, path)
PY
fi
docker exec -u root "$target_container" chown www-data:www-data "$input_container"

if [[ "$resume_ready" != "1" ]]; then
  current_stage="validate-package-once"
  write_status "running" "$current_stage" "Validando una vez el paquete sellado." 0
  sidecar_arg=()
  if [[ -r "$package_path.sha256" ]]; then
    sidecar_hash="$(awk 'NR == 1 {print $1}' "$package_path.sha256")"
    [[ "$sidecar_hash" =~ ^[a-f0-9]{64}$ ]] || \
      fail "El sidecar SHA-256 externo tiene formato inválido."
    printf '%s  source.zip\n' "$sidecar_hash" > "$work_host/input/source.zip.sha256"
    docker exec -u root "$target_container" chown www-data:www-data \
      "$input_container.sha256"
    sidecar_arg=("--sidecar=$input_container.sha256")
  fi
  docker exec -u www-data "$target_container" php \
    /opt/integrator-v1/validate-package.php \
    "--zip=$input_container" \
    "--report=$work_container/validation.json" \
    "${sidecar_arg[@]}"

  current_stage="single-package-extraction"
  docker exec -u www-data "$target_container" php \
    /opt/integrator-v1/extract-package.php \
    "--zip=$input_container" \
    "--validation=$work_container/validation.json" \
    "--destination=$work_container/package"

  current_stage="target-bulk-snapshot"
  docker exec -u www-data "$target_container" php \
    /opt/integrator-v1/target-snapshot.php \
    "--output=$work_container/target-snapshot.json" \
    --targetid=target

  current_stage="build-plan"
  docker exec -u www-data "$target_container" php \
    /opt/integrator-v1/build-plan.php \
    "--workdir=$work_container" \
    "--package=$work_container/package" \
    "--snapshot=$work_container/target-snapshot.json" \
    --targetid=target
fi

effective_workers="$workers"
if [[ "$workers" == "auto" ]]; then
  available_cpu="$(docker exec "$target_container" sh -lc 'nproc 2>/dev/null || getconf _NPROCESSORS_ONLN')"
  [[ "$available_cpu" =~ ^[0-9]+$ ]] || available_cpu=1
  (( available_cpu > 4 )) && available_cpu=4
  (( available_cpu < 1 )) && available_cpu=1
  effective_workers="$available_cpu"
fi
echo "INCREMENTAL_WORKERS requested=$workers effective=$effective_workers max=4"

if [[ -f "$work_host/prebackup/manifest.json" ]]; then
  python3 - "$work_host/prebackup/manifest.json" "$prebackup" <<'PY'
import hashlib, json, os, sys
manifest_path, requested = sys.argv[1:]
with open(manifest_path, encoding="utf-8") as handle:
    manifest = json.load(handle)
if requested == "auto":
    if manifest.get("type") != "mandatory-preintegration-backup":
        raise SystemExit("El lote ya está ligado a una copia previa de otro modo.")
else:
    external = requested.removeprefix("existing:")
    if manifest.get("type") != "operator-supplied-preintegration-backup" or \
            manifest.get("external_path") != external:
        raise SystemExit("El lote ya está ligado a otra copia previa externa.")
    if not os.path.isfile(external) or os.path.getsize(external) != int(manifest.get("bytes", -1)):
        raise SystemExit("La copia previa externa falta o cambió de tamaño.")
    digest = hashlib.sha256()
    with open(external, "rb") as source:
        for chunk in iter(lambda: source.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    if digest.hexdigest() != manifest.get("sha256"):
        raise SystemExit("La copia previa externa perdió integridad.")
print("INCREMENTAL_PREBACKUP_BINDING_OK")
PY
fi

current_maintenance="$(docker exec -u www-data "$target_container" php -r \
  'define("CLI_SCRIPT",true); require "/var/www/html/config.php"; echo (int)get_config("core","maintenance_enabled");')"
[[ "$current_maintenance" == "0" || "$current_maintenance" == "1" ]] || \
  fail "No se pudo determinar el estado de mantenimiento."
current_cron=0
if [[ -n "$(compose --profile live ps --status running -q moodle-cron 2>/dev/null || true)" ]]; then
  current_cron=1
fi
runtime_resume="$(python3 - "$work_host/runtime-state.json" <<'PY'
import json, os, sys
try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        data = json.load(handle)
    valid = data.get("schema_version") == "1.0" and data.get("restored") is False
except Exception:
    valid = False
print(1 if valid else 0)
PY
)"
if [[ "$runtime_resume" == "1" ]]; then
  read -r maintenance_was cron_was_running < <(python3 - "$work_host/runtime-state.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    data = json.load(handle)
print(int(bool(data["original_maintenance"])), int(bool(data["original_cron_running"])))
PY
)
  echo "INCREMENTAL_RUNTIME_RESUME original_maintenance=$maintenance_was original_cron=$cron_was_running"
else
  maintenance_was="$current_maintenance"
  cron_was_running="$current_cron"
  python3 - "$work_host/runtime-state.json" "$maintenance_was" "$cron_was_running" <<'PY'
import datetime, json, os, sys
path, maintenance, cron = sys.argv[1:]
data = {
    "schema_version": "1.0",
    "tool_version": "1.1.5-linux",
    "captured_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "original_maintenance": bool(int(maintenance)),
    "original_cron_running": bool(int(cron)),
    "restored": False,
}
tmp = path + ".tmp"
with open(tmp, "w", encoding="utf-8") as handle:
    json.dump(data, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
os.replace(tmp, path)
PY
fi
if (( current_cron == 1 )); then
  compose --profile live stop moodle-cron
fi
current_stage="maintenance"
if [[ "$current_maintenance" == "0" ]]; then
  docker exec -u www-data "$target_container" \
    php /var/www/html/admin/cli/maintenance.php --enable
fi
maintenance_changed=1
echo "INCREMENTAL_MAINTENANCE_OK previous=$maintenance_was cron_was_running=$cron_was_running"

current_stage="mandatory-prebackup"
write_status "running" "$current_stage" "Generando copia previa obligatoria." 0
mkdir -p "$work_host/prebackup"
prebackup_reused=0
if [[ "$prebackup" == "auto" ]]; then
  if [[ ! -f "$work_host/prebackup/manifest.json" ]]; then
    find "$work_host/prebackup" -maxdepth 1 -type f -name '*.tmp.*' -delete
    db_tmp="$work_host/prebackup/database.sql.gz.tmp.$$"
    data_tmp="$work_host/prebackup/moodledata.tar.gz.tmp.$$"
    code_tmp="$work_host/prebackup/moodle-code.tar.gz.tmp.$$"
    docker exec "$db_container" sh -lc \
      'exec mariadb-dump --single-transaction --quick --routines --events --triggers -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
      | gzip -1 > "$db_tmp"
    docker exec "$target_container" tar \
      --exclude='./cache' --exclude='./localcache' --exclude='./temp' \
      --exclude='./trashdir' --exclude='./sessions' \
      -czf - -C /var/www/moodledata . > "$data_tmp"
    docker exec "$target_container" tar \
      --exclude='./config.php' -czf - -C /var/www/html . > "$code_tmp"
    mv -f "$db_tmp" "$work_host/prebackup/database.sql.gz"
    mv -f "$data_tmp" "$work_host/prebackup/moodledata.tar.gz"
    mv -f "$code_tmp" "$work_host/prebackup/moodle-code.tar.gz"
    python3 - "$work_host/prebackup" <<'PY'
import datetime, hashlib, json, os, sys
root = sys.argv[1]
files = {}
for name in ("database.sql.gz", "moodledata.tar.gz", "moodle-code.tar.gz"):
    path = os.path.join(root, name)
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(8 * 1024 * 1024), b""):
            h.update(chunk)
    files[name] = {"sha256": h.hexdigest(), "bytes": os.path.getsize(path)}
data = {
    "schema_version": "1.0",
    "tool_version": "1.1.5-linux",
    "generated_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "type": "mandatory-preintegration-backup",
    "files": files,
    "config_php_included": False,
    "regenerable_moodledata_caches_excluded": True,
    "status": "complete",
}
tmp = os.path.join(root, "manifest.json.tmp")
with open(tmp, "w", encoding="utf-8") as handle:
    json.dump(data, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
os.replace(tmp, os.path.join(root, "manifest.json"))
PY
  else
    prebackup_reused=1
    echo "INCREMENTAL_PREBACKUP_REUSED path=$work_host/prebackup/manifest.json"
  fi
else
  existing_path="${prebackup#existing:}"
  if [[ ! -f "$work_host/prebackup/manifest.json" ]]; then
    existing_sha="$(sha256sum "$existing_path" | awk '{print $1}')"
    python3 - "$work_host/prebackup/manifest.json" "$existing_path" "$existing_sha" <<'PY'
import datetime, json, os, sys
path, existing, digest = sys.argv[1:]
data = {
    "schema_version": "1.0",
    "tool_version": "1.1.5-linux",
    "generated_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "type": "operator-supplied-preintegration-backup",
    "external_path": existing,
    "sha256": digest,
    "bytes": os.path.getsize(existing),
    "status": "complete",
}
tmp = path + ".tmp"
with open(tmp, "w", encoding="utf-8") as handle:
    json.dump(data, handle, ensure_ascii=False, indent=2)
    handle.write("\n")
os.replace(tmp, path)
PY
  else
    echo "INCREMENTAL_PREBACKUP_REUSED path=$work_host/prebackup/manifest.json"
  fi
fi
if (( prebackup_reused == 1 )); then
  python3 - "$work_host/prebackup" <<'PY'
import hashlib, json, os, sys

root = sys.argv[1]
manifest_path = os.path.join(root, "manifest.json")
with open(manifest_path, encoding="utf-8") as handle:
    manifest = json.load(handle)
if manifest.get("status") != "complete" or \
        manifest.get("type") != "mandatory-preintegration-backup":
    raise SystemExit("La copia previa reutilizada no tiene un manifiesto completo.")
expected = manifest.get("files")
if not isinstance(expected, dict) or set(expected) != {
        "database.sql.gz", "moodledata.tar.gz", "moodle-code.tar.gz"}:
    raise SystemExit("El manifiesto de la copia previa no contiene los tres artefactos.")
for name, metadata in expected.items():
    path = os.path.join(root, name)
    if not os.path.isfile(path) or os.path.getsize(path) != int(metadata.get("bytes", -1)):
        raise SystemExit(f"La copia previa cambió de tamaño o falta: {name}")
    digest = hashlib.sha256()
    with open(path, "rb") as source:
        for chunk in iter(lambda: source.read(8 * 1024 * 1024), b""):
            digest.update(chunk)
    if digest.hexdigest() != metadata.get("sha256"):
        raise SystemExit(f"La copia previa perdió integridad: {name}")
print("INCREMENTAL_PREBACKUP_VERIFY_OK files=3")
PY
fi
echo "INCREMENTAL_PREBACKUP_OK mode=${prebackup%%:*}"

current_stage="apply-base"
docker exec -u root "$target_container" sh -lc \
  "chown -R www-data:www-data '$work_container' && chmod -R u=rwX,go= '$work_container'"
docker exec -u www-data "$target_container" php \
  /opt/integrator-v1/apply-base.php "--workdir=$work_container"

mapfile -t course_keys < <(python3 - "$work_host/plan.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    plan = json.load(handle)
for course in plan["courses"]:
    print(course["course_key"])
PY
)
(( ${#course_keys[@]} > 0 )) || fail "El plan no contiene cursos."

run_course() {
  local course_key="$1" course_log
  course_log="$work_host/course-${course_key,,}.log"
  (
    set -o pipefail
    docker exec -u www-data "$target_container" php \
      /opt/integrator-v1/apply-course.php \
      "--workdir=$work_container" \
      "--coursekey=$course_key" 2>&1 | tee -a "$course_log"
  )
}

current_stage="pilot-course"
write_status "running" "$current_stage" "Procesando el curso piloto pesado." 0
echo "INCREMENTAL_PILOT_START course_key=${course_keys[0]}"
run_course "${course_keys[0]}"
echo "INCREMENTAL_PILOT_OK course_key=${course_keys[0]}"

current_stage="parallel-courses"
write_status "running" "$current_stage" "Procesando la cola dinámica." 0
declare -A active_pids=()
failed=0
for course_key in "${course_keys[@]:1}"; do
  while (( ${#active_pids[@]} >= effective_workers )); do
    completed_pid=""
    if wait -n -p completed_pid "${!active_pids[@]}"; then
      echo "INCREMENTAL_WORKER_SLOT_OK course_key=${active_pids[$completed_pid]} active_before=${#active_pids[@]}"
    else
      failed=1
    fi
    [[ -n "$completed_pid" ]] && unset 'active_pids[$completed_pid]'
    (( failed == 0 )) || break
  done
  (( failed == 0 )) || break
  run_course "$course_key" &
  active_pids[$!]="$course_key"
  echo "INCREMENTAL_WORKER_START course_key=$course_key active=${#active_pids[@]}"
done
for pid in "${!active_pids[@]}"; do
  wait "$pid" || failed=1
done
if (( failed != 0 )); then
  fail "Falló uno o más cursos; los checkpoints válidos permanecen."
fi

current_stage="final-verification"
write_status "running" "$current_stage" "Verificando cursos, roles y privilegios." 0
docker exec -u www-data "$target_container" php \
  /opt/integrator-v1/final-verify.php "--workdir=$work_container"

echo "INCREMENTAL_COMPLETE report=$work_host/final-report.json"
echo "Los cursos y la categoría padre permanecen ocultos para revisión/publicación manual."
