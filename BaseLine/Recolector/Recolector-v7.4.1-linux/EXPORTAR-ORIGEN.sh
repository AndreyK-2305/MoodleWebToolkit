#!/usr/bin/env bash
set -euo pipefail
umask 077

readonly collector_version="7.4.1-linux"
readonly default_moodle_config="/var/www/html/config.php"
readonly auto_workers_cap=4
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
script_path="$script_dir/$(basename "${BASH_SOURCE[0]}")"
invocation_dir="$(pwd -P)"

usage() {
  cat <<'EOF'
Uso:
  ./EXPORTAR-ORIGEN.sh [opciones] nombre.zip [/ruta/moodle/config.php]

Opciones:
  --background              Ejecutar mediante systemd-run y continuar al cerrar SSH.
  --workers=auto|1|2|3|4   Respaldos simultáneos. Predeterminado: auto (CPU, máximo 4).
  --notify-every=MINUTOS    Correo de progreso cada N minutos. 0 lo desactiva. Pred.: 10.
  --output-dir=RUTA         Directorio final del ZIP, SHA, estado y logs.
  --temp-dir=RUTA           Temporal rápido opcional. Si se omite, Moodle usa el habitual.
  --reuse-backups=RUTA      Busca MBZ compatibles existentes en esta ruta.
  --reuse-only              Exige un MBZ compatible por curso; nunca genera faltantes.
  --restart                 Descarta el trabajo parcial del mismo origen e inicia de cero.
  -h, --help                Mostrar esta ayuda.

Ejemplos:
  ./EXPORTAR-ORIGEN.sh campus-norte.zip
  ./EXPORTAR-ORIGEN.sh campus-norte.zip /srv/moodle/config.php
  ./EXPORTAR-ORIGEN.sh --workers=2 --output-dir=/datos/copias campus-norte.zip
  ./EXPORTAR-ORIGEN.sh --temp-dir=/mnt/nvme/tmp --reuse-backups=/datos/mbz campus-norte.zip
  ./EXPORTAR-ORIGEN.sh --reuse-backups=/datos/mbz --reuse-only campus-norte.zip
  sudo ./EXPORTAR-ORIGEN.sh --background --workers=auto campus-norte.zip

Si se omite config.php se usa /var/www/html/config.php.
El nombre, sin .zip, se usa como identificador interno del origen.
EOF
}

fail_usage() {
  echo "$1" >&2
  usage >&2
  exit 2
}

absolute_directory() {
  local requested="$1"
  if [[ "$requested" != /* ]]; then
    requested="$invocation_dir/$requested"
  fi
  mkdir -p -- "$requested"
  (cd -- "$requested" && pwd -P)
}

absolute_file_path() {
  local requested="$1"
  local parent filename
  if [[ "$requested" != /* ]]; then
    requested="$invocation_dir/$requested"
  fi
  parent="$(absolute_directory "$(dirname -- "$requested")")"
  filename="$(basename -- "$requested")"
  printf '%s/%s\n' "$parent" "$filename"
}

absolute_existing_directory() {
  local requested="$1"
  if [[ "$requested" != /* ]]; then
    requested="$invocation_dir/$requested"
  fi
  [[ -d "$requested" ]] || return 1
  (cd -- "$requested" && pwd -P)
}

detect_cpu_threads() {
  local detected=1 quota period limited
  if command -v nproc >/dev/null 2>&1; then
    detected="$(nproc 2>/dev/null || printf '1')"
  elif command -v getconf >/dev/null 2>&1; then
    detected="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '1')"
  fi
  [[ "$detected" =~ ^[0-9]+$ ]] || detected=1
  (( detected >= 1 )) || detected=1

  if [[ -r /sys/fs/cgroup/cpu.max ]]; then
    read -r quota period < /sys/fs/cgroup/cpu.max || true
    if [[ "${quota:-}" =~ ^[0-9]+$ && "${period:-}" =~ ^[0-9]+$ ]] &&
        (( period > 0 )); then
      limited=$(( (quota + period - 1) / period ))
      (( limited >= 1 )) || limited=1
      if (( limited < detected )); then
        detected="$limited"
      fi
    fi
  elif [[ -r /sys/fs/cgroup/cpu/cpu.cfs_quota_us &&
          -r /sys/fs/cgroup/cpu/cpu.cfs_period_us ]]; then
    quota="$(< /sys/fs/cgroup/cpu/cpu.cfs_quota_us)"
    period="$(< /sys/fs/cgroup/cpu/cpu.cfs_period_us)"
    if [[ "$quota" =~ ^[0-9]+$ && "$period" =~ ^[0-9]+$ ]] &&
        (( quota > 0 && period > 0 )); then
      limited=$(( (quota + period - 1) / period ))
      (( limited >= 1 )) || limited=1
      if (( limited < detected )); then
        detected="$limited"
      fi
    fi
  fi
  printf '%d\n' "$detected"
}

execution_mode="foreground"
workers_requested="${MOODLE_COLLECTOR_WORKERS:-auto}"
notify_every="${MOODLE_COLLECTOR_NOTIFY_EVERY:-10}"
output_dir_option=""
temp_dir_option="${MOODLE_COLLECTOR_TEMP_DIR:-}"
reuse_backups_option="${MOODLE_COLLECTOR_REUSE_BACKUPS:-}"
reuse_only_requested="${MOODLE_COLLECTOR_REUSE_ONLY:-0}"
restart_requested="${MOODLE_COLLECTOR_RESTART:-0}"
positionals=()

while (( $# > 0 )); do
  case "$1" in
    --background)
      [[ "$execution_mode" == "foreground" ]] || \
        fail_usage "El modo de ejecución fue indicado más de una vez."
      execution_mode="background-launcher"
      ;;
    --internal-run)
      [[ "$execution_mode" == "foreground" ]] || \
        fail_usage "El modo de ejecución fue indicado más de una vez."
      execution_mode="background"
      ;;
    --workers=*)
      workers_requested="${1#*=}"
      ;;
    --notify-every=*)
      notify_every="${1#*=}"
      ;;
    --output-dir=*)
      output_dir_option="${1#*=}"
      ;;
    --temp-dir=*)
      temp_dir_option="${1#*=}"
      ;;
    --reuse-backups=*)
      reuse_backups_option="${1#*=}"
      ;;
    --reuse-only)
      reuse_only_requested=1
      ;;
    --restart)
      restart_requested=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      fail_usage "Opción no reconocida: $1"
      ;;
    *)
      positionals+=("$1")
      ;;
  esac
  shift
done

(( ${#positionals[@]} >= 1 && ${#positionals[@]} <= 2 )) || \
  fail_usage "Se requiere el nombre del ZIP y, opcionalmente, config.php."
[[ "$workers_requested" == "auto" || "$workers_requested" =~ ^[1-4]$ ]] || \
  fail_usage "--workers debe ser auto o un número entre 1 y 4."
[[ "$notify_every" =~ ^[0-9]+$ ]] || \
  fail_usage "--notify-every debe ser un número entero entre 0 y 1440."
(( notify_every >= 0 && notify_every <= 1440 )) || \
  fail_usage "--notify-every debe estar entre 0 y 1440 minutos."
[[ -z "$output_dir_option" || -n "${output_dir_option//[[:space:]]/}" ]] || \
  fail_usage "--output-dir no puede estar vacío."
[[ -z "$temp_dir_option" || -n "${temp_dir_option//[[:space:]]/}" ]] || \
  fail_usage "--temp-dir no puede estar vacío."
[[ -z "$reuse_backups_option" || -n "${reuse_backups_option//[[:space:]]/}" ]] || \
  fail_usage "--reuse-backups no puede estar vacío."
[[ "$reuse_only_requested" == "0" || "$reuse_only_requested" == "1" ]] || \
  fail_usage "MOODLE_COLLECTOR_REUSE_ONLY debe ser 0 o 1."
[[ "$restart_requested" == "0" || "$restart_requested" == "1" ]] || \
  fail_usage "MOODLE_COLLECTOR_RESTART debe ser 0 o 1."
[[ "$reuse_only_requested" == "0" || -n "$reuse_backups_option" ]] || \
  fail_usage "--reuse-only requiere --reuse-backups=RUTA."

zip_name="${positionals[0]}"
moodle_config="${positionals[1]:-$default_moodle_config}"
[[ "$zip_name" != */* && "$zip_name" != "." && "$zip_name" != ".." ]] || \
  fail_usage "Indique solo el nombre del ZIP, sin directorios."
source_id="${zip_name%.zip}"
[[ "$source_id" =~ ^[a-z][a-z0-9_-]{0,62}$ ]] || \
  fail_usage "El nombre debe iniciar en minúscula y usar solo a-z, 0-9, _ o -."
[[ "$zip_name" == "$source_id" || "$zip_name" == "$source_id.zip" ]] || \
  fail_usage "La única extensión permitida es .zip en minúsculas."

source_name="$source_id"
cpu_threads="$(detect_cpu_threads)"
if [[ "$workers_requested" == "auto" ]]; then
  workers_effective="$cpu_threads"
  if (( workers_effective > auto_workers_cap )); then
    workers_effective="$auto_workers_cap"
  fi
else
  workers_effective="$workers_requested"
fi

if [[ -n "$output_dir_option" ]]; then
  output_dir_requested="$output_dir_option"
elif [[ -n "${MOODLE_COLLECTOR_OUTPUT_DIR:-}" ]]; then
  output_dir_requested="$MOODLE_COLLECTOR_OUTPUT_DIR"
else
  output_dir_requested="$script_dir/salidas"
fi
output_dir_created=0
[[ -d "$output_dir_requested" ]] || output_dir_created=1
output_dir="$(absolute_directory "$output_dir_requested")"
output_zip="$output_dir/$source_id.zip"

work_dir_requested="${MOODLE_COLLECTOR_WORKDIR:-$output_dir/.moodle-collector-work-$source_id}"
work_dir="$(absolute_directory "$work_dir_requested")"
logs_dir="$(absolute_directory "$output_dir/logs")"

temp_dir=""
temp_dir_created=0
if [[ -n "$temp_dir_option" ]]; then
  [[ -d "$temp_dir_option" ]] || temp_dir_created=1
  temp_dir="$(absolute_directory "$temp_dir_option")"
fi

reuse_backups=""
if [[ -n "$reuse_backups_option" ]]; then
  reuse_backups="$(absolute_existing_directory "$reuse_backups_option")" || \
    fail_usage "No existe el directorio indicado en --reuse-backups."
fi

if [[ "$moodle_config" != /* ]]; then
  moodle_config="$invocation_dir/$moodle_config"
fi
if [[ -r "$moodle_config" ]]; then
  moodle_config="$(cd "$(dirname -- "$moodle_config")" && pwd -P)/$(basename -- "$moodle_config")"
fi

smtp_config="${MOODLE_COLLECTOR_SMTP_CONFIG:-$script_dir/smtp-config.json}"
if [[ "$smtp_config" != /* ]]; then
  smtp_config="$script_dir/$smtp_config"
fi

run_token="$(date -u +%Y%m%dT%H%M%SZ)-$$"
if [[ -n "${MOODLE_COLLECTOR_LOG_FILE:-}" ]]; then
  log_file="$(absolute_file_path "$MOODLE_COLLECTOR_LOG_FILE")"
else
  log_file="$logs_dir/$source_id-$run_token.log"
fi
status_file="$output_dir/$source_id.status.json"
progress_file="$work_dir/export-progress.json"
unit_name="moodle-recolector-$source_id"
started_epoch="$(date +%s)"
current_stage="preflight"

write_status() {
  local state="$1" exit_code="$2" stage="$3" ended_at="$4"
  php "$script_dir/scripts/write-status.php" \
    "--statusfile=$status_file" "--progressfile=$progress_file" \
    "--collectorversion=$collector_version" "--sourceid=$source_id" \
    "--state=$state" "--stage=$stage" "--executionmode=$execution_mode" \
    "--exitcode=$exit_code" "--startedepoch=$started_epoch" \
    "--workersrequested=$workers_requested" "--workerseffective=$workers_effective" \
    "--cputhreads=$cpu_threads" "--autoworkerscap=$auto_workers_cap" \
    "--notifyevery=$notify_every" "--outputdir=$output_dir" \
    "--outputzip=$output_zip" "--workdir=$work_dir" \
    "--tempdir=$temp_dir" "--reusebackups=$reuse_backups" \
    "--reuseonly=$reuse_only_requested" \
    "--logfile=$log_file" "--endedat=$ended_at" || true
}

reset_progress() {
  local temporary="$progress_file.tmp.$$"
  printf '%s\n' \
    '{"schema_version":"1.0","stage":"preflight","total_courses":0,"completed_courses":0,"created_courses":0,"reused_courses":0,"resumed_courses":0,"adopted_courses":0,"failed_courses":0,"pending_courses":0,"active_workers":0,"percent_complete":0,"workers":[]}' \
    > "$temporary"
  mv -f "$temporary" "$progress_file"
}

runtime_preflight() {
  command -v php >/dev/null 2>&1 || { echo "No se encontró PHP CLI." >&2; return 1; }
  [[ -r "$moodle_config" ]] || { echo "No se puede leer config.php: $moodle_config" >&2; return 1; }
  php -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || {
    echo "El PHP CLI no tiene disponible la extensión zip/ZipArchive." >&2
    return 1
  }
  php -r 'exit(class_exists("DOMDocument") ? 0 : 1);' || {
    echo "El PHP CLI no tiene disponible la extensión DOM." >&2
    return 1
  }
  [[ -z "$temp_dir" || -w "$temp_dir" ]] || {
    echo "No se puede escribir en el almacenamiento temporal: $temp_dir" >&2
    return 1
  }
  [[ -z "$reuse_backups" || ( -r "$reuse_backups" && -x "$reuse_backups" ) ]] || {
    echo "No se puede recorrer el directorio de respaldos existentes: $reuse_backups" >&2
    return 1
  }
}

send_notification() {
  local result="$1" exit_code="$2" stage="$3" duration hard_timeout
  command -v php >/dev/null 2>&1 || return 0
  duration=$(( $(date +%s) - started_epoch ))
  hard_timeout="${MOODLE_COLLECTOR_SMTP_HARD_TIMEOUT:-20}"
  [[ "$hard_timeout" =~ ^[0-9]+$ ]] || hard_timeout=20
  (( hard_timeout >= 5 && hard_timeout <= 60 )) || hard_timeout=20
  local -a notify_command=(php "$script_dir/scripts/notify-smtp.php"
    "--moodleconfig=$moodle_config" "--smtpconfig=$smtp_config"
    "--sourceid=$source_id" "--operation=export" "--result=$result"
    "--exitcode=$exit_code" "--stage=$stage" "--duration=$duration"
    "--outputzip=$output_zip" "--logfile=$log_file"
    "--progressfile=$progress_file" "--workers=$workers_effective")
  if command -v timeout >/dev/null 2>&1; then
    timeout "${hard_timeout}s" "${notify_command[@]}" || \
      echo "SMTP_WARNING La notificación excedió el tiempo máximo o no pudo ejecutarse." >&2
  else
    "${notify_command[@]}" || true
  fi
}

finish_run() {
  local exit_code=$? result ended_at final_stage progress_stage
  trap - EXIT
  set +e
  ended_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  if (( exit_code == 0 )); then result="success"; final_stage="completed"
  else
    result="error"
    final_stage="$current_stage"
    if [[ "$current_stage" == "export" && -r "$progress_file" ]]; then
      progress_stage="$(php -r '
        $value = json_decode((string)@file_get_contents($argv[1]), true);
        echo is_array($value) ? (string)($value["stage"] ?? "") : "";
      ' "$progress_file" 2>/dev/null || true)"
      if [[ "$progress_stage" =~ ^[a-z][a-z0-9_-]*$ ]]; then
        final_stage="$progress_stage"
      fi
    fi
  fi
  write_status "$result" "$exit_code" "$final_stage" "$ended_at"
  send_notification "$result" "$exit_code" "$final_stage"
  exit "$exit_code"
}

launch_background() {
  local run_user run_group writable
  local -a internal_command
  (( EUID == 0 )) || {
    echo "El modo --background usa una unidad systemd del sistema." >&2
    echo "Repita el comando con sudo para que continúe al cerrar SSH." >&2
    return 1
  }
  command -v systemd-run >/dev/null 2>&1 || { echo "No se encontró systemd-run." >&2; return 1; }
  command -v systemctl >/dev/null 2>&1 || { echo "No se encontró systemctl." >&2; return 1; }
  if systemctl is-active --quiet "$unit_name.service"; then
    echo "Ya existe una recolección activa: $unit_name.service" >&2
    return 1
  fi
  if [[ -n "${MOODLE_COLLECTOR_RUN_AS_USER:-}" ]]; then
    run_user="$MOODLE_COLLECTOR_RUN_AS_USER"
    id "$run_user" >/dev/null 2>&1 || { echo "Usuario inexistente: $run_user" >&2; return 1; }
    run_group="$(id -gn "$run_user")"
  elif [[ -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
    run_user="$SUDO_USER"; run_group="$(id -gn "$run_user")"
  else
    run_user="root"; run_group="root"
  fi

  touch "$log_file"
  if [[ "$run_user" != "root" ]]; then
    command -v runuser >/dev/null 2>&1 || { echo "No se encontró runuser." >&2; return 1; }
    if (( output_dir_created == 1 )); then
      chown "$run_user:$run_group" "$output_dir"
    fi
    if [[ -n "$temp_dir" && "$temp_dir_created" == "1" ]]; then
      chown "$run_user:$run_group" "$temp_dir"
    fi
    chown "$run_user:$run_group" "$work_dir" "$logs_dir" "$log_file"
    if ! runuser -u "$run_user" -- test -r "$moodle_config"; then
      echo "El usuario $run_user no puede leer $moodle_config." >&2
      echo "Use MOODLE_COLLECTOR_RUN_AS_USER con el usuario correcto o ajuste permisos." >&2
      return 1
    fi
    for writable in "$output_dir" "$work_dir" "$logs_dir" ${temp_dir:+"$temp_dir"}; do
      if ! runuser -u "$run_user" -- test -w "$writable"; then
        echo "El usuario $run_user no puede escribir en $writable." >&2
        echo "Ajuste permisos o use --output-dir con una ruta escribible." >&2
        return 1
      fi
    done
    if [[ -n "$reuse_backups" ]] &&
        { ! runuser -u "$run_user" -- test -r "$reuse_backups" ||
          ! runuser -u "$run_user" -- test -x "$reuse_backups"; }; then
      echo "El usuario $run_user no puede recorrer $reuse_backups." >&2
      return 1
    fi
  fi

  internal_command=("$script_path" --internal-run
    "--workers=$workers_requested" "--notify-every=$notify_every"
    "--output-dir=$output_dir")
  [[ -z "$temp_dir" ]] || internal_command+=("--temp-dir=$temp_dir")
  [[ -z "$reuse_backups" ]] || internal_command+=("--reuse-backups=$reuse_backups")
  [[ "$reuse_only_requested" == "0" ]] || internal_command+=(--reuse-only)
  [[ "$restart_requested" == "0" ]] || internal_command+=(--restart)
  internal_command+=("$source_id.zip" "$moodle_config")

  systemd-run --unit="$unit_name" --collect --service-type=exec \
    --uid="$run_user" --gid="$run_group" --working-directory="$script_dir" \
    --setenv="PATH=$PATH" --setenv="MOODLE_COLLECTOR_OUTPUT_DIR=$output_dir" \
    --setenv="MOODLE_COLLECTOR_WORKDIR=$work_dir" \
    --setenv="MOODLE_COLLECTOR_SMTP_CONFIG=$smtp_config" \
    --setenv="MOODLE_COLLECTOR_LOG_FILE=$log_file" \
    --property="StandardOutput=append:$log_file" \
    --property="StandardError=append:$log_file" \
    "${internal_command[@]}"

  echo "RECOLECTOR_BACKGROUND_OK source=$source_id unit=$unit_name.service"
  echo "Workers: requested=$workers_requested effective=$workers_effective cpu_threads=$cpu_threads cap=$auto_workers_cap"
  echo "Temporal: ${temp_dir:-Moodle predeterminado}"
  echo "Respaldos existentes: ${reuse_backups:-desactivado}"
  echo "Reutilización obligatoria: $reuse_only_requested"
  echo "Estado: systemctl status $unit_name.service"
  echo "Log:    tail -f $log_file"
  echo "Resumen: $status_file"
  echo "Salida:  $output_zip"
}

if [[ "$execution_mode" == "background-launcher" ]]; then
  runtime_preflight
  launch_background
  exit 0
fi

run_export() {
  set -euo pipefail
  local hash_sidecar hash_line final_sha separator final_name
  local -a hash_lines export_command
  trap finish_run EXIT
  runtime_preflight
  reset_progress
  write_status "running" 0 "$current_stage" ""
  echo "RECOLECTOR_INICIO version=$collector_version source=$source_id mode=$execution_mode"
  echo "RECOLECTOR_CONFIG workers_requested=$workers_requested workers_effective=$workers_effective cpu_threads=$cpu_threads auto_cap=$auto_workers_cap notify_every_minutes=$notify_every reuse_only=$reuse_only_requested"
  echo "RECOLECTOR_PATH output_dir=$output_dir output_zip=$output_zip work_dir=$work_dir temp_dir=${temp_dir:-moodle-default} reuse_backups=${reuse_backups:-disabled} log=$log_file"
  send_notification "started" 0 "preflight"

  current_stage="export"
  write_status "running" 0 "$current_stage" ""
  export_command=(php "$script_dir/scripts/source-export.php" \
    "--config=$moodle_config" "--sourceid=$source_id" "--sourcename=$source_name" \
    "--outputdir=$work_dir" "--outputzip=$output_zip" "--scope=all" \
    "--workers=$workers_effective" "--workersrequested=$workers_requested" \
    "--cputhreads=$cpu_threads" "--autoworkerscap=$auto_workers_cap" \
    "--notifyevery=$notify_every" "--smtpconfig=$smtp_config" \
    "--statusfile=$status_file" "--progressfile=$progress_file" \
    "--executionmode=$execution_mode" "--logfile=$log_file" \
    "--startedepoch=$started_epoch" "--collectorversion=$collector_version" \
    "--reuseonly=$reuse_only_requested" \
    "--restart=$restart_requested")
  [[ -z "$temp_dir" ]] || export_command+=("--tempdir=$temp_dir")
  [[ -z "$reuse_backups" ]] || export_command+=("--reusebackups=$reuse_backups")
  "${export_command[@]}"

  current_stage="final-hash"
  write_status "running" 0 "$current_stage" ""
  hash_sidecar="$output_zip.sha256"
  [[ -r "$hash_sidecar" ]] || { echo "No se generó el SHA-256 externo: $hash_sidecar" >&2; return 1; }
  mapfile -t hash_lines < "$hash_sidecar"
  (( ${#hash_lines[@]} == 1 )) || { echo "El archivo SHA-256 externo no tiene el formato esperado." >&2; return 1; }
  hash_line="${hash_lines[0]}"; final_sha="${hash_line:0:64}"
  separator="${hash_line:64:2}"; final_name="${hash_line:66}"
  [[ "$final_sha" =~ ^[a-f0-9]{64}$ && "$separator" == "  " &&
        "$final_name" == "$source_id.zip" ]] || {
    echo "El archivo SHA-256 externo no corresponde al ZIP generado." >&2; return 1;
  }
  printf '%s\n' "$hash_line"
  echo "RECOLECTOR_OK source=$source_id output=$output_zip"
  echo "El ZIP contiene datos institucionales sensibles; restrinja su acceso."
}

if [[ "$execution_mode" == "foreground" ]]; then
  touch "$log_file"
  if command -v tee >/dev/null 2>&1; then
    set +e
    run_export 2>&1 | tee -a "$log_file"
    export_exit="${PIPESTATUS[0]}"
    set -e
    exit "$export_exit"
  fi
fi
run_export
