#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

[[ -x ./APLICAR-REVISION-IDENTIDADES.sh ]] || {
  printf '%s\n' 'V8_IDENTITY_WRAPPER_FAILED: wrapper ausente/no ejecutable' >&2
  exit 1
}

wrapper="$(cat ./APLICAR-REVISION-IDENTIDADES.sh)"
[[ "${wrapper}" == *'BASH_SOURCE[0]'* ]] || {
  printf '%s\n' 'V8_IDENTITY_WRAPPER_FAILED: root no deriva de BASH_SOURCE' >&2
  exit 1
}
[[ "${wrapper}" == *'moodle-consolidation.sh" aplicar-revision-identidades'* ]] || {
  printf '%s\n' 'V8_IDENTITY_WRAPPER_FAILED: no delega al runtime público' >&2
  exit 1
}
[[ "${wrapper}" != *'pwsh '* ]] || {
  printf '%s\n' 'V8_IDENTITY_WRAPPER_FAILED: intenta usar pwsh del host' >&2
  exit 1
}

printf '%s\n' 'FUZZY_LINUX_FLOW_OK wrapper=public host_pwsh=0'
