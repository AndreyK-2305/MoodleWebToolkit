#!/usr/bin/env bash
set -Eeuo pipefail

python3 - <<'PY'
def permitted(mode, owner, group, uid, groups, operation):
    bit = {'read': 4, 'write': 2, 'execute': 1}[operation]
    if uid == owner:
        return bool((mode >> 6) & bit)
    if group in groups:
        return bool((mode >> 3) & bit)
    return bool(mode & bit)

assistant = 1000
www_data = 33

# RC1: 1000:1000 0750. www-data no puede atravesar el directorio.
assert not permitted(0o750, assistant, assistant, www_data, {www_data}, 'execute')

# RC2: /exports 1000:33 2770, inputs 2750/0640, output en /exports.
assert permitted(0o2770, assistant, www_data, www_data, {www_data}, 'execute')
assert permitted(0o2770, assistant, www_data, www_data, {www_data}, 'write')
assert permitted(0o2750, assistant, www_data, www_data, {www_data}, 'execute')
assert permitted(0o640, assistant, www_data, www_data, {www_data}, 'read')
assert not (0o2770 & 0o007) and not (0o2750 & 0o007) and not (0o640 & 0o007)
PY
printf '%s\n' 'V8_RC2_THEME_TRANSPORT_OK rc1=blocked read=www-data write=www-data mode=minimal'
