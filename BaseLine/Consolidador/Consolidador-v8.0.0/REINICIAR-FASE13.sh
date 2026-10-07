#!/usr/bin/env bash
set -Eeuo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${project_root}"

die() {
  printf 'FASE13_RESTART_ERROR: %s\n' "$*" >&2
  exit 1
}

[[ "${1:-}" == "ARCHIVAR-FASE13" ]] || {
  printf '%s\n' \
    'Uso: ./REINICIAR-FASE13.sh ARCHIVAR-FASE13' \
    'Archiva únicamente logs y estados transitorios del coordinador de Fase 13.' \
    'Conserva MBZ, planes, jobs, manifiestos, inventarios, apply-states y checkpoints.'
  exit 2
}

command -v flock >/dev/null 2>&1 || die 'se requiere flock.'
command -v sha256sum >/dev/null 2>&1 || die 'se requiere sha256sum.'
mkdir -p reports
exec {phase13_lock_fd}>reports/assistant-run.lock
flock -n "${phase13_lock_fd}" ||
  die 'hay una ejecución del asistente activa; no se archivó nada.'

[[ ! -e exports/phase6/batch_apply_summary.json ]] ||
  die 'el lote ya tiene batch_apply_summary.json; no se reinicia una aplicación sellada.'
[[ -f exports/phase6/course_plan.csv ]] ||
  die 'falta exports/phase6/course_plan.csv.'
[[ -f exports/phase6/batch_manifest.json ]] ||
  die 'falta exports/phase6/batch_manifest.json.'

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="reports/phase13-restarts/${timestamp}"
if [[ -e "${archive}" ]]; then
  archive="${archive}-$$"
fi
umask 077
mkdir -p "${archive}"
manifest="${archive}/ARCHIVO-FASE13.tsv"
printf 'sha256\tbytes\toriginal\tarchived\n' > "${manifest}"
moved=0

archive_path() {
  local source="$1" destination hash bytes
  [[ -f "${source}" && ! -L "${source}" ]] || return 0
  destination="${archive}/${source}"
  mkdir -p "$(dirname "${destination}")"
  hash="$(sha256sum -- "${source}" | awk '{print $1}')"
  bytes="$(stat -c '%s' -- "${source}")"
  mv -- "${source}" "${destination}"
  printf '%s\t%s\t%s\t%s\n' \
    "${hash}" "${bytes}" "${source}" "${destination#${archive}/}" >> "${manifest}"
  moved=$((moved + 1))
}

archive_path reports/fase-6-aplicacion-lote.txt
archive_path reports/fase-6-workers-status.json
archive_path reports/assistant-state.json

if [[ -d reports/fase-6-workers && ! -L reports/fase-6-workers ]]; then
  while IFS= read -r -d '' worker_log; do
    archive_path "${worker_log}"
  done < <(find reports/fase-6-workers -maxdepth 1 -type f -print0 | sort -z)
  rmdir reports/fase-6-workers 2>/dev/null || true
fi

if [[ -d exports/phase6/course-worker-states &&
      ! -L exports/phase6/course-worker-states ]]; then
  while IFS= read -r -d '' worker_state; do
    archive_path "${worker_state}"
  done < <(find exports/phase6/course-worker-states -maxdepth 1 \
    -type f -name 'state-*.json' -print0 | sort -z)
fi

printf 'FASE13_RESTART_ARCHIVED moved=%s archive=%s\n' "${moved}" "${archive}"
printf '%s\n' \
  'PRESERVED packages=1 plans=1 jobs=1 manifests=1 inventories=1 apply_states=1 checkpoints=1'
printf '%s\n' \
  'Ejecute ./INICIAR-CONSOLIDACION.sh para reanudar desde el primer curso pendiente.'
