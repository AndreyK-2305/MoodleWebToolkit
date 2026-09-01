#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
consolidator_dir="${MOODLE_CONSOLIDATOR_DIR:-}"
package_name=""
while (( $# > 0 )); do
  case "$1" in
    --consolidador-dir=*) consolidator_dir="${1#*=}" ;;
    -h|--help)
      echo "Uso: ./ESTADO.sh --consolidador-dir=RUTA nombre-paquete-sin-zip"
      exit 0
      ;;
    --*) echo "Opción no reconocida: $1" >&2; exit 2 ;;
    *) package_name="$1" ;;
  esac
  shift
done
[[ -n "$consolidator_dir" && -n "$package_name" ]] || {
  echo "Uso: ./ESTADO.sh --consolidador-dir=RUTA nombre-paquete-sin-zip" >&2
  exit 2
}
consolidator_dir="$(cd "$consolidator_dir" && pwd -P)"
slug="$(printf '%s' "$package_name" | tr '[:upper:]' '[:lower:]' | \
  sed -E 's/[^a-z0-9_-]+/-/g; s/^-+//; s/-+$//')"
slug="${slug:0:40}"
[[ -n "$slug" ]] || {
  echo "El nombre del paquete no produce un identificador válido." >&2
  exit 2
}
work="$consolidator_dir/exports/integrator/run-$slug"
unit="moodle-integrador-$slug.service"

echo "Unidad: $unit"
systemctl status "$unit" --no-pager -l 2>/dev/null || true
echo
echo "Estado JSON: $work/status.json"
[[ -r "$work/status.json" ]] && cat "$work/status.json" || echo "Aún no existe."
echo
echo "Checkpoints:"
if [[ -d "$work/checkpoints" ]]; then
  find "$work/checkpoints" -maxdepth 1 -type f -name 'checkpoint-*.json' | wc -l
else
  echo 0
fi
echo "Log: $work/integrador.log"
