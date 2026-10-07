#!/usr/bin/env bash

set -uo pipefail

project_root="$(
  cd "$(dirname "${BASH_SOURCE[0]}")/.." &&
  pwd
)"

cd "${project_root}" || false

export ASSISTANT_PROJECT_ROOT="${project_root}"
export ASSISTANT_UID="$(id -u)"
export ASSISTANT_GID="$(id -g)"
export DOCKER_SOCKET_PATH="${DOCKER_SOCKET_PATH:-/var/run/docker.sock}"

if [[ -S "${DOCKER_SOCKET_PATH}" ]]; then
  export DOCKER_GID="$(
    stat -c '%g' "${DOCKER_SOCKET_PATH}" 2>/dev/null
  )"
fi

mkdir -p reports

active_file="${project_root}/reports/progress-notifier.active"

: > "${active_file}"

cleanup_progress_notifier() {
  rm -f -- "${active_file}"
}

trap cleanup_progress_notifier EXIT INT TERM

docker compose \
  --profile tools \
  run \
  --rm \
  --no-deps \
  -T \
  assistant-runtime \
  pwsh \
  -NoLogo \
  -NoProfile \
  -File ./scripts/progress-notifier.ps1 \
  -Loop \
  -PollSeconds 15 \
  >> reports/progress-notifier.log \
  2>&1 &

notifier_runner_pid=$!

"${project_root}/moodle-consolidation.sh" \
  ejecutar-automatico \
  "$@"

main_status=$?

rm -f -- "${active_file}"

wait "${notifier_runner_pid}" 2>/dev/null || true

if [[ "${main_status}" -eq 0 ]]; then
  true
else
  false
fi
