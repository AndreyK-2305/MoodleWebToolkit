#!/usr/bin/env bash
set -euo pipefail
umask 007

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
consolidator_dir=""
run_slug=""

usage() {
  cat <<'EOF'
Uso:
  sudo ./REVALIDAR-CIERRE.sh \
    --consolidador-dir=/ruta/Consolidador \
    --run=identificador-del-paquete

Regenera solamente final-report.json mediante verificaciones de solo lectura.
No restaura cursos, no crea usuarios y no activa mantenimiento.
EOF
}

fail() {
  echo "INCREMENTAL_REVALIDATION_ERROR $*" >&2
  exit 1
}

while (( $# > 0 )); do
  case "$1" in
    --consolidador-dir=*) consolidator_dir="${1#*=}" ;;
    --run=*) run_slug="${1#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) fail "Opción no reconocida: $1" ;;
  esac
  shift
done

(( EUID == 0 )) || fail "Ejecute este comando con sudo."
[[ -n "$consolidator_dir" ]] || fail "Falta --consolidador-dir."
[[ -n "$run_slug" ]] || fail "Falta --run."
[[ "$run_slug" =~ ^[a-z0-9][a-z0-9_-]{0,39}$ ]] || \
  fail "--run contiene caracteres no permitidos."
[[ -d "$consolidator_dir" ]] || fail "No existe $consolidator_dir."
consolidator_dir="$(cd "$consolidator_dir" && pwd -P)"
export ASSISTANT_PROJECT_ROOT="$consolidator_dir"

version="$(head -n 1 "$consolidator_dir/VERSION.txt" 2>/dev/null || true)"
[[ "$version" =~ ^7\.3\.0-linux(-rc4)?$ ]] || \
  fail "El destino no corresponde a Consolidador 7.3.0-linux/rc4."
[[ -r "$consolidator_dir/.env" && -f "$consolidator_dir/compose.yaml" ]] || \
  fail "El destino no conserva compose.yaml y .env."

for command_name in docker python3 cp date timeout; do
  command -v "$command_name" >/dev/null 2>&1 || \
    fail "Falta el comando $command_name."
done

if [[ -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
  owner_uid="$(id -u "$SUDO_USER")"
  owner_gid="$(id -g "$SUDO_USER")"
else
  owner_uid="$(id -u)"
  owner_gid="$(id -g)"
fi

compose=(docker compose
  --env-file "$consolidator_dir/.env"
  --project-directory "$consolidator_dir"
  -f "$consolidator_dir/compose.yaml")

integrator_container_root="/exports/integrator"
work_container="$integrator_container_root/run-$run_slug"
work_host="$consolidator_dir/exports/integrator/run-$run_slug"
[[ -r "$work_host/plan.json" ]] || fail "Falta $work_host/plan.json."
[[ -d "$work_host/checkpoints" && -d "$work_host/inventories" ]] || \
  fail "La ejecución no conserva checkpoints e inventarios."

permissions_restored=0
target_container=""
restore_permissions() {
  local exit_code=$?
  trap - EXIT INT TERM
  set +e
  if [[ -n "$target_container" ]] && \
      docker inspect "$target_container" >/dev/null 2>&1; then
    timeout --foreground 30s docker exec -u root "$target_container" sh -lc \
      "chown -R $owner_uid:$owner_gid '$work_container'; chmod -R u=rwX,go= '$work_container'; chown $owner_uid:www-data '$integrator_container_root'; chmod 0770 '$integrator_container_root'" \
      >/dev/null 2>&1
  fi
  chown -R "$owner_uid:$owner_gid" "$work_host" >/dev/null 2>&1
  permissions_restored=1
  exit "$exit_code"
}
trap restore_permissions EXIT
trap 'exit 130' INT TERM

"${compose[@]}" up -d db moodle-target
target_container="$("${compose[@]}" ps -q moodle-target)"
[[ -n "$target_container" ]] || fail "No se encontró moodle-target."

maintenance_before="$(docker exec -u www-data "$target_container" php -r \
  'define("CLI_SCRIPT",true); require "/var/www/html/config.php"; echo (int)get_config("core","maintenance_enabled");')"
[[ "$maintenance_before" == "0" || "$maintenance_before" == "1" ]] || \
  fail "No se pudo leer el estado de mantenimiento."

timeout --foreground 60s docker exec -u root "$target_container" sh -lc \
  'rm -rf /opt/integrator-v1 && install -d -m 0755 /opt/integrator-v1'
timeout --foreground 60s docker cp "$script_dir/scripts/." "$target_container:/opt/integrator-v1/"
timeout --foreground 60s docker exec -u root "$target_container" sh -lc \
  'find /opt/integrator-v1 -type d -exec chmod 0755 {} +; find /opt/integrator-v1 -type f -exec chmod 0644 {} +'

timeout --foreground 60s docker exec -u root "$target_container" sh -lc \
  "install -d -o $owner_uid -g www-data -m 0770 '$integrator_container_root'; chown $owner_uid:www-data '$integrator_container_root'; chmod 0770 '$integrator_container_root'; chown -R www-data:www-data '$work_container'; find '$work_container' -type d -exec chmod 0770 {} +; find '$work_container' -type f -exec chmod 0660 {} +"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
if [[ -f "$work_host/final-report.json" ]]; then
  cp -a "$work_host/final-report.json" \
    "$work_host/final-report.before-v1.1.5-$stamp.json"
  docker exec -u root "$target_container" chown \
    www-data:www-data \
    "$work_container/final-report.before-v1.1.5-$stamp.json"
fi

docker exec -u www-data "$target_container" php \
  /opt/integrator-v1/final-verify.php "--workdir=$work_container"

maintenance_after="$(docker exec -u www-data "$target_container" php -r \
  'define("CLI_SCRIPT",true); require "/var/www/html/config.php"; echo (int)get_config("core","maintenance_enabled");')"
[[ "$maintenance_after" == "$maintenance_before" ]] || \
  fail "La revalidación alteró inesperadamente el mantenimiento."

python3 - "$work_host/final-report.json" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    report = json.load(handle)

required = {
    "status": "completed_hidden",
    "existing_target_courses_modified": False,
    "reused_target_profiles_modified": False,
    "site_admins_added": 0,
    "publication_performed": False,
}
problems = [
    f"{key}={report.get(key)!r}"
    for key, expected in required.items()
    if report.get(key) != expected
]
courses = report.get("courses", [])
if len(courses) < 1:
    problems.append("courses vacío")
if report.get("category_map_integrity") not in {
    "sealed_sha256",
    "legacy_unsigned_live_revalidation",
}:
    problems.append(
        f"category_map_integrity={report.get('category_map_integrity')!r}"
    )
for course in courses:
    if course.get("content_reverified") is not True:
        problems.append(f"curso {course.get('target_course_id')} sin content_reverified")
    if course.get("file_content_hashes_verified") is not True:
        problems.append(f"curso {course.get('target_course_id')} sin hashes verificados")
    if course.get("physical_file_hashes_recomputed") is not True:
        problems.append(
            f"curso {course.get('target_course_id')} sin rehash físico"
        )
    if int(course.get("physical_files_checked", 0)) < 1:
        problems.append(
            f"curso {course.get('target_course_id')} sin archivos físicos comprobados"
        )
if problems:
    raise SystemExit("INCREMENTAL_REVALIDATION_REPORT_ERROR " + "; ".join(problems))
print(
    "INCREMENTAL_REVALIDATION_REPORT_OK",
    f"courses={len(courses)}",
    "content_reverified=1",
    "file_hashes_verified=1",
    "physical_file_hashes_recomputed=1",
    f"category_map_integrity={report['category_map_integrity']}",
)
PY

echo "INCREMENTAL_REVALIDATION_OK run=$run_slug maintenance_unchanged=$maintenance_before report=$work_host/final-report.json"
