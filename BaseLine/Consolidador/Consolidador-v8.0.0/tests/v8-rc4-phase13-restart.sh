#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT

cp "${root}/REINICIAR-FASE13.sh" "${temporary}/REINICIAR-FASE13.sh"
chmod 0755 "${temporary}/REINICIAR-FASE13.sh"
mkdir -p \
  "${temporary}/exports/packages/source/cursos" \
  "${temporary}/exports/phase6/course-worker-states" \
  "${temporary}/exports/phase6/apply-states" \
  "${temporary}/exports/phase6/apply-checkpoints" \
  "${temporary}/exports/phase6/course-jobs" \
  "${temporary}/exports/phase6/course-degradation-plans" \
  "${temporary}/reports/fase-6-workers"
printf 'course_key,source,action,blocking_reason\nCOURSE-A,source,restore_new,\n' \
  > "${temporary}/exports/phase6/course_plan.csv"
printf '{"manifest_status":"BATCH_READY"}\n' \
  > "${temporary}/exports/phase6/batch_manifest.json"
printf 'sealed-mbz\n' > "${temporary}/exports/packages/source/cursos/course.mbz"
printf '{"job":true}\n' > "${temporary}/exports/phase6/course-jobs/job.json"
printf '{"plan":true}\n' \
  > "${temporary}/exports/phase6/course-degradation-plans/plan.json"
printf '{"state":"verification_failed","target_course_id":12}\n' \
  > "${temporary}/exports/phase6/apply-states/state-a.json"
printf '{"checkpoint_status":"applied","target_course_id":9}\n' \
  > "${temporary}/exports/phase6/apply-checkpoints/checkpoint-b.json"
printf '{"status":"WAITING_MANUAL"}\n' \
  > "${temporary}/exports/phase6/course-worker-states/state-a.json"
printf 'old report\n' > "${temporary}/reports/fase-6-aplicacion-lote.txt"
printf 'old worker\n' > "${temporary}/reports/fase-6-workers/worker-1.log"

mbz_before="$(sha256sum "${temporary}/exports/packages/source/cursos/course.mbz" | awk '{print $1}')"
(
  cd "${temporary}"
  ./REINICIAR-FASE13.sh ARCHIVAR-FASE13
)

archive="$(find "${temporary}/reports/phase13-restarts" -mindepth 1 -maxdepth 1 -type d -print -quit)"
[[ -n "${archive}" && -f "${archive}/ARCHIVO-FASE13.tsv" ]]
[[ -f "${archive}/reports/fase-6-aplicacion-lote.txt" ]]
[[ -f "${archive}/reports/fase-6-workers/worker-1.log" ]]
[[ -f "${archive}/exports/phase6/course-worker-states/state-a.json" ]]
[[ ! -e "${temporary}/reports/fase-6-aplicacion-lote.txt" ]]
[[ ! -e "${temporary}/exports/phase6/course-worker-states/state-a.json" ]]

for preserved in \
  exports/packages/source/cursos/course.mbz \
  exports/phase6/course_plan.csv \
  exports/phase6/batch_manifest.json \
  exports/phase6/course-jobs/job.json \
  exports/phase6/course-degradation-plans/plan.json \
  exports/phase6/apply-states/state-a.json \
  exports/phase6/apply-checkpoints/checkpoint-b.json; do
  [[ -f "${temporary}/${preserved}" ]] || {
    printf 'V8_RC4_PHASE13_RESTART_FAILED perdió %s\n' "${preserved}" >&2
    exit 1
  }
done
[[ "$(sha256sum "${temporary}/exports/packages/source/cursos/course.mbz" | awk '{print $1}')" == "${mbz_before}" ]]

printf '{"apply_status":"applied_pending_batch_verification"}\n' \
  > "${temporary}/exports/phase6/batch_apply_summary.json"
if (cd "${temporary}" && ./REINICIAR-FASE13.sh ARCHIVAR-FASE13 >/dev/null 2>&1); then
  printf '%s\n' 'V8_RC4_PHASE13_RESTART_FAILED aceptó un lote ya sellado' >&2
  exit 1
fi

printf '%s\n' \
  'V8_RC4_PHASE13_RESTART_OK archived=transient preserved=packages,contracts,states,checkpoints sealed=blocked'
