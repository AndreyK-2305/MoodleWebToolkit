#!/usr/bin/env bash
set -Eeuo pipefail
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config-manager/config-manager.sh" "$@"
