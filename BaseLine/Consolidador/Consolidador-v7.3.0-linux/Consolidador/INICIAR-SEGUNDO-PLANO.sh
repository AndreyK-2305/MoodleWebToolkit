#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
exec ./moodle-consolidation.sh iniciar-segundo-plano "$@"
