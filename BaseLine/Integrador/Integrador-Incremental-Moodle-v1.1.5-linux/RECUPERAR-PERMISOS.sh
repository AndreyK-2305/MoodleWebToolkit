#!/usr/bin/env bash
set -euo pipefail

[[ $# -eq 1 ]] || {
  echo "Uso: sudo ./RECUPERAR-PERMISOS.sh /ruta/Consolidador" >&2
  exit 2
}
(( EUID == 0 )) || {
  echo "Ejecute este comando con sudo." >&2
  exit 1
}
target="$1"
[[ -d "$target/exports/integrator" ]] || {
  echo "No existe $target/exports/integrator." >&2
  exit 1
}
target="$(cd "$target" && pwd -P)"
if [[ -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
  owner_uid="$(id -u "$SUDO_USER")"
  owner_gid="$(id -g "$SUDO_USER")"
else
  owner_uid="$(id -u)"
  owner_gid="$(id -g)"
fi
integrator_root="$target/exports/integrator"
chown -R "$owner_uid:$owner_gid" "$integrator_root"
chmod -R u=rwX,go= "$target/exports/integrator"
chown "$owner_uid:33" "$integrator_root"
chmod 0770 "$integrator_root"
echo "INCREMENTAL_PERMISSIONS_OK owner=$owner_uid:$owner_gid shared_group=33 path=$integrator_root"
