#!/usr/bin/env bash
set -euo pipefail
umask 077

readonly collector_version="7.4.1-linux"
readonly default_moodle_config="/var/www/html/config.php"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
script_path="$script_dir/$(basename "${BASH_SOURCE[0]}")"

usage() {
  cat <<'EOF'
Uso:
  ./VALIDAR-PAQUETE.sh [--background] /ruta/paquete.zip [/ruta/moodle/config.php]

Ejemplos:
  ./VALIDAR-PAQUETE.sh salidas/campus-norte.zip
  ./VALIDAR-PAQUETE.sh salidas/campus-norte.zip /srv/moodle/config.php
  sudo ./VALIDAR-PAQUETE.sh --background salidas/campus-norte.zip

Recalcula el SHA-256 del ZIP y de cada artefacto interno, comprueba la
estructura y genera el reporte, el estado y el log de la auditoría.

config.php solo se usa para cargar PHPMailer cuando SMTP está habilitado.
Si se omite, se intenta usar /var/www/html/config.php.
EOF
}

fail_usage() {
  echo "$1" >&2
  usage >&2
  exit 2
}

execution_mode="foreground"
case "${1:-}" in
  --background)
    execution_mode="background-launcher"
    shift
    ;;
  --internal-run)
    execution_mode="background"
    shift
    ;;
  -h|--help)
    usage
    exit 0
    ;;
  --*)
    fail_usage "Opción no reconocida: $1"
    ;;
esac

(( $# >= 1 && $# <= 2 )) || \
  fail_usage "Se requiere el ZIP y, opcionalmente, config.php."

zip_input="$1"
moodle_config="${2:-$default_moodle_config}"
[[ "$zip_input" == *.zip ]] || \
  fail_usage "El paquete debe tener extensión .zip en minúsculas."
[[ -f "$zip_input" && -r "$zip_input" ]] || {
  echo "No se puede leer el paquete: $zip_input" >&2
  exit 2
}

zip_dir="$(cd "$(dirname "$zip_input")" && pwd -P)"
zip_path="$zip_dir/$(basename "$zip_input")"
sidecar_path="$zip_path.sha256"
package_id="$(basename "${zip_path%.zip}")"
report_path="${zip_path%.zip}.validacion.json"
status_file="${zip_path%.zip}.validacion.status.json"

# El exportador ya produce identificadores válidos. Esta normalización permite
# auditar también ZIP antiguos con espacios o mayúsculas sin rechazar el archivo.
notification_id="$(
  printf '%s' "$package_id" |
    tr '[:upper:]' '[:lower:]' |
    sed -E 's/[^a-z0-9_-]+/-/g; s/^-+//; s/-+$//'
)"
if [[ ! "$notification_id" =~ ^[a-z] ]]; then
  notification_id="p-$notification_id"
fi
notification_id="${notification_id:0:63}"
[[ "$notification_id" =~ ^[a-z][a-z0-9_-]{0,62}$ ]] || \
  notification_id="paquete"

logs_dir="$zip_dir/logs"
mkdir -p "$logs_dir"
run_token="$(date -u +%Y%m%dT%H%M%SZ)-$$"
log_file="${MOODLE_COLLECTOR_LOG_FILE:-$logs_dir/$notification_id-validacion-$run_token.log}"
unit_name="moodle-recolector-validacion-$notification_id"
started_epoch="$(date +%s)"
current_stage="preflight"

if [[ -r "$moodle_config" ]]; then
  moodle_config="$(cd "$(dirname "$moodle_config")" && pwd -P)/$(basename "$moodle_config")"
fi

smtp_config="${MOODLE_COLLECTOR_SMTP_CONFIG:-$script_dir/smtp-config.json}"
if [[ "$smtp_config" != /* ]]; then
  smtp_config="$script_dir/$smtp_config"
fi

json_escape() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "$value"
}

write_status() {
  local state="$1"
  local exit_code="$2"
  local stage="$3"
  local ended_at="$4"
  local now duration temporary
  now="$(date +%s)"
  duration=$(( now - started_epoch ))
  temporary="$status_file.tmp.$$"
  printf '{\n' > "$temporary"
  printf '  "schema_version": "1.0",\n' >> "$temporary"
  printf '  "collector_version": "%s",\n' "$(json_escape "$collector_version")" >> "$temporary"
  printf '  "operation": "validation",\n' >> "$temporary"
  printf '  "package_id": "%s",\n' "$(json_escape "$package_id")" >> "$temporary"
  printf '  "state": "%s",\n' "$(json_escape "$state")" >> "$temporary"
  printf '  "stage": "%s",\n' "$(json_escape "$stage")" >> "$temporary"
  printf '  "execution_mode": "%s",\n' "$(json_escape "$execution_mode")" >> "$temporary"
  printf '  "exit_code": %d,\n' "$exit_code" >> "$temporary"
  printf '  "duration_seconds": %d,\n' "$duration" >> "$temporary"
  printf '  "package": "%s",\n' "$(json_escape "$zip_path")" >> "$temporary"
  printf '  "report_file": "%s",\n' "$(json_escape "$report_path")" >> "$temporary"
  printf '  "log_file": "%s",\n' "$(json_escape "$log_file")" >> "$temporary"
  printf '  "updated_at_utc": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$temporary"
  printf '  "ended_at_utc": "%s"\n' "$(json_escape "$ended_at")" >> "$temporary"
  printf '}\n' >> "$temporary"
  mv -f "$temporary" "$status_file"
}

runtime_preflight() {
  command -v php >/dev/null 2>&1 || {
    echo "No se encontró PHP CLI." >&2
    return 1
  }
  php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || {
    echo "El PHP CLI no tiene disponible la extensión zip/ZipArchive." >&2
    return 1
  }
}

send_notification() {
  local result="$1"
  local exit_code="$2"
  local stage="$3"
  local duration hard_timeout
  command -v php >/dev/null 2>&1 || return 0
  duration=$(( $(date +%s) - started_epoch ))
  hard_timeout="${MOODLE_COLLECTOR_SMTP_HARD_TIMEOUT:-20}"
  [[ "$hard_timeout" =~ ^[0-9]+$ ]] || hard_timeout=20
  (( hard_timeout >= 5 && hard_timeout <= 60 )) || hard_timeout=20
  if command -v timeout >/dev/null 2>&1; then
    timeout "${hard_timeout}s" php "$script_dir/scripts/notify-smtp.php" \
      "--moodleconfig=$moodle_config" \
      "--smtpconfig=$smtp_config" \
      "--sourceid=$notification_id" \
      "--operation=validation" \
      "--result=$result" \
      "--exitcode=$exit_code" \
      "--stage=$stage" \
      "--duration=$duration" \
      "--outputzip=$zip_path" \
      "--reportfile=$report_path" \
      "--logfile=$log_file" || \
        echo "SMTP_WARNING La notificación excedió el tiempo máximo o no pudo ejecutarse." >&2
  else
    php "$script_dir/scripts/notify-smtp.php" \
      "--moodleconfig=$moodle_config" \
      "--smtpconfig=$smtp_config" \
      "--sourceid=$notification_id" \
      "--operation=validation" \
      "--result=$result" \
      "--exitcode=$exit_code" \
      "--stage=$stage" \
      "--duration=$duration" \
      "--outputzip=$zip_path" \
      "--reportfile=$report_path" \
      "--logfile=$log_file" || true
  fi
}

finish_run() {
  local exit_code=$?
  local result ended_at final_stage
  trap - EXIT
  set +e
  ended_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  if (( exit_code == 0 )); then
    result="success"
    final_stage="completed"
  else
    result="error"
    final_stage="$current_stage"
  fi
  write_status "$result" "$exit_code" "$final_stage" "$ended_at"
  send_notification "$result" "$exit_code" "$final_stage"
  exit "$exit_code"
}

launch_background() {
  local run_user run_group
  (( EUID == 0 )) || {
    echo "El modo --background usa una unidad systemd del sistema." >&2
    echo "Repita el comando con sudo para que continúe al cerrar SSH." >&2
    return 1
  }
  command -v systemd-run >/dev/null 2>&1 || {
    echo "No se encontró systemd-run." >&2
    return 1
  }
  command -v systemctl >/dev/null 2>&1 || {
    echo "No se encontró systemctl." >&2
    return 1
  }
  if systemctl is-active --quiet "$unit_name.service"; then
    echo "Ya existe una validación activa: $unit_name.service" >&2
    return 1
  fi

  if [[ -n "${MOODLE_COLLECTOR_RUN_AS_USER:-}" ]]; then
    run_user="$MOODLE_COLLECTOR_RUN_AS_USER"
    id "$run_user" >/dev/null 2>&1 || {
      echo "Usuario de ejecución inexistente: $run_user" >&2
      return 1
    }
    run_group="$(id -gn "$run_user")"
  elif [[ -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
    run_user="$SUDO_USER"
    run_group="$(id -gn "$run_user")"
  else
    run_user="root"
    run_group="root"
  fi

  touch "$log_file"
  if [[ "$run_user" != "root" ]]; then
    command -v runuser >/dev/null 2>&1 || {
      echo "No se encontró runuser para validar el usuario de ejecución." >&2
      return 1
    }
    chown "$run_user:$run_group" "$logs_dir" "$log_file"
    if ! runuser -u "$run_user" -- test -r "$zip_path"; then
      echo "El usuario $run_user no puede leer $zip_path." >&2
      return 1
    fi
    if ! runuser -u "$run_user" -- test -w "$zip_dir"; then
      echo "El usuario $run_user no puede escribir reportes en $zip_dir." >&2
      return 1
    fi
    if [[ -e "$report_path" ]] && ! runuser -u "$run_user" -- test -w "$report_path"; then
      echo "El usuario $run_user no puede actualizar $report_path." >&2
      return 1
    fi
    if [[ -e "$status_file" ]] && ! runuser -u "$run_user" -- test -w "$status_file"; then
      echo "El usuario $run_user no puede actualizar $status_file." >&2
      return 1
    fi
  fi

  systemd-run \
    --unit="$unit_name" \
    --collect \
    --service-type=exec \
    --uid="$run_user" \
    --gid="$run_group" \
    --working-directory="$script_dir" \
    --setenv="PATH=$PATH" \
    --setenv="MOODLE_COLLECTOR_SMTP_CONFIG=$smtp_config" \
    --setenv="MOODLE_COLLECTOR_LOG_FILE=$log_file" \
    --property="StandardOutput=append:$log_file" \
    --property="StandardError=append:$log_file" \
    "$script_path" --internal-run "$zip_path" "$moodle_config"

  echo "VALIDADOR_BACKGROUND_OK package=$package_id unit=$unit_name.service"
  echo "Estado: systemctl status $unit_name.service"
  echo "Log:    tail -f $log_file"
  echo "Resumen: $status_file"
  echo "Reporte: $report_path"
}

run_validation() {
  set -euo pipefail
  trap finish_run EXIT
  write_status "running" 0 "$current_stage" ""

  runtime_preflight
  current_stage="audit"
  write_status "running" 0 "$current_stage" ""

  echo "VALIDACION_INICIO version=$collector_version paquete=$zip_path mode=$execution_mode"
  php "$script_dir/scripts/validate-package.php" \
    "--zip=$zip_path" \
    "--sidecar=$sidecar_path" \
    "--report=$report_path"
}

if [[ "$execution_mode" == "background-launcher" ]]; then
  runtime_preflight
  launch_background
  exit 0
fi

if [[ "$execution_mode" == "foreground" ]]; then
  touch "$log_file"
  if command -v tee >/dev/null 2>&1; then
    set +e
    run_validation 2>&1 | tee -a "$log_file"
    validation_exit="${PIPESTATUS[0]}"
    set -e
    exit "$validation_exit"
  fi
fi

run_validation
