#!/usr/bin/env bash
set -Eeuo pipefail
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export ASSISTANT_PROJECT_ROOT="${project_root}"
exec "${project_root}/moodle-consolidation.sh" aplicar-revision-identidades "$@"
