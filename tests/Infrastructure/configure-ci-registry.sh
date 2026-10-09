#!/usr/bin/env bash
set -euo pipefail

# Run as a step on the empty Ubuntu GitHub-hosted runner, before any build.
# This configures Google's documented Docker Hub cache without changing images.
fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

[[ "${GITHUB_ACTIONS:-}" == 'true' ]] || fail 'This step requires GitHub Actions.'
[[ "${RUNNER_OS:-}" == 'Linux' ]] || fail 'This step requires a Linux runner.'
[[ "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]] || fail 'This step requires a GitHub-hosted runner.'
[[ -n "${RUNNER_TEMP:-}" && -d "$RUNNER_TEMP" ]] || fail 'RUNNER_TEMP must exist.'
for task_command in docker dockerd jq sudo systemctl mktemp install; do
    command -v "$task_command" >/dev/null || fail "Required command missing: $task_command"
done

task_docker() {
    # Inspect the same local system daemon that systemctl will restart.
    env -u DOCKER_HOST -u DOCKER_CONTEXT docker --host unix:///var/run/docker.sock "$@"
}

task_containers="$(task_docker ps --all --quiet)"
[[ -z "$task_containers" ]] || fail 'Docker must have no containers before this step.'

umask 077
task_config="$(mktemp "${RUNNER_TEMP%/}/ci-docker-registry.XXXXXX")"
trap 'rm -f -- "$task_config"' EXIT
task_daemon_config='/etc/docker/daemon.json'
task_mirror='https://mirror.gcr.io'

# Preserve every field and the order of existing mirrors. Add Google only once.
# Never print the daemon configuration, which may contain proxy credentials.
if sudo -n test -e "$task_daemon_config"; then
    sudo -n test -f "$task_daemon_config" || fail 'Docker configuration must be a regular file.'
    sudo -n cat "$task_daemon_config"
else
    printf '{}\n'
fi | jq --arg mirror "$task_mirror" '
    if type != "object" then
        error("Docker configuration must be a JSON object")
    elif has("registry-mirrors") and (.["registry-mirrors"] | type) != "array" then
        error("registry-mirrors must be an array")
    elif (((.["registry-mirrors"] // []) | all(.[]; type == "string")) | not) then
        error("registry-mirrors entries must be strings")
    else
        .["registry-mirrors"] = (
            (.["registry-mirrors"] // []) as $configured
            | if any($configured[]; rtrimstr("/") == $mirror) then
                $configured
              else
                $configured + [$mirror]
              end
        )
    end
' > "$task_config"

sudo -n dockerd --validate --config-file "$task_config"
sudo -n mkdir -p /etc/docker
sudo -n install -o root -g root -m 0600 "$task_config" "$task_daemon_config"
sudo -n systemctl restart docker
sudo -n systemctl is-active --quiet docker

task_docker info --format '{{json .RegistryConfig.Mirrors}}' \
    | jq --exit-status --arg mirror "$task_mirror" \
        'any(.[]; rtrimstr("/") == $mirror)' >/dev/null
printf '%s\n' 'Docker daemon confirms the Google Docker Hub cache is configured.'
