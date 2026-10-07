#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "${V8_RUN_LIVE_INTEGRATION:-0}" != "1" ]]; then
  printf '%s\n' 'V8_LIVE_INTEGRATION_NOT_RUN reason=V8_RUN_LIVE_INTEGRATION_not_1'
  exit 0
fi

command -v docker >/dev/null 2>&1 || {
  printf '%s\n' 'V8_LIVE_INTEGRATION_FAILED: docker no disponible' >&2
  exit 1
}
docker compose version >/dev/null

runner="${V8_LIVE_SCENARIO_RUNNER:-}"
[[ -n "${runner}" && -x "${runner}" ]] || {
  printf '%s\n' \
    'V8_LIVE_INTEGRATION_FAILED: defina V8_LIVE_SCENARIO_RUNNER con el runner de fixtures reales' >&2
  exit 1
}

output="$("${runner}")"
printf '%s\n' "${output}"

required=(
  'PLUGIN_UPGRADE_SERIALIZED_OK'
  'upgrade_concurrency=1'
  'restart_count=0'
  'qtype_coderunner version_db=registered'
  'mod_chat resolved=true'
  'Moodle healthy=true'
  'IDENTITY_REVIEW_IMPORTED merge=3 ignore=81'
  'MBZ_DEEP_OPEN_COUNT=0'
  'COURSE_1_START'
  'COURSE_1_RESTORE_START'
  'LEGACY_RANDOM_QUESTION_NORMALIZED'
  'RESTORE_OK'
  'VERIFY_QUIZ_OK'
  'STRUCTURAL_OBSERVATION'
  'VERIFY_FORUM_OK'
)
for marker in "${required[@]}"; do
  [[ "${output}" == *"${marker}"* ]] || {
    printf 'V8_LIVE_INTEGRATION_FAILED: falta marker %s\n' "${marker}" >&2
    exit 1
  }
done

printf '%s\n' 'V8_LIVE_INTEGRATION_OK docker=real mariadb=real plugins=real fuzzy=real random=real forum=real'
