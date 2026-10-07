#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

fixture="$(mktemp -d -t rc6-stop-XXXXXXXX)"
trap 'rm -rf -- "${fixture}"' EXIT
mkdir -p "${fixture}/Consolidador" "${fixture}/bin"
cp moodle-consolidation.sh DETENER.sh "${fixture}/Consolidador/"
# El runtime de tests impide crear sockets Unix; en la copia del fixture se
# simula únicamente esa comprobación ambiental. stop_services queda idéntico.
touch "${fixture}/mock-docker.sock"
python3 - "${fixture}/Consolidador/moodle-consolidation.sh" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
old = '[[ -S "${socket_path}" ]] ||'
if text.count(old) != 1:
    raise SystemExit('RC6_STOP_FAILED control de socket inesperado')
path.write_text(text.replace(old, '[[ -e "${socket_path}" ]] ||', 1))
PY
cat > "${fixture}/Consolidador/.env" <<'ENV'
MOODLE_PUBLIC_URL=https://moodle.example.edu
MOODLE_INTERNAL_HEALTH_URL=http://127.0.0.1:8090
MOODLE_ADMIN_PASSWORD=ValidPassword123@
ENV
cat > "${fixture}/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail
printf '%s\n' "docker $*" >> "${RC6_DOCKER_CALLS}"
case "${1:-}" in
  info) exit 0 ;;
  compose)
    if [[ "${2:-}" == version ]]; then exit 0; fi
    [[ "${2:-}" == --project-name &&
        "${3:-}" == moodle-consolidation-production ]] || exit 90
    [[ "$*" == *' --profile live stop moodle-cron moodle-target db' ]] || exit 90
    exit 0
    ;;
  ps)
    [[ "$*" == *'label=com.docker.compose.project=moodle-consolidation-production'* &&
        "$*" == *'label=com.docker.compose.service=assistant-runtime'* ]] || exit 90
    printf '%s\n' aaaaaaaaaaaa bbbbbbbbbbbb cccccccccccc dddddddddddd
    ;;
  inspect)
    [[ "${2:-}" == --format ]] || exit 90
    case "${4:-}" in
      aaaaaaaaaaaa) printf 'moodle-consolidation-production|assistant-runtime|True\n' ;;
      bbbbbbbbbbbb) printf 'moodle-consolidation-production|assistant-runtime|False\n' ;;
      cccccccccccc) printf 'otro-proyecto|assistant-runtime|True\n' ;;
      dddddddddddd) printf 'moodle-consolidation-production|moodle-target|True\n' ;;
      *) exit 90 ;;
    esac
    ;;
  rm)
    [[ "${2:-}" == --force && "${3:-}" == aaaaaaaaaaaa ]] || exit 90
    ;;
  *) exit 90 ;;
esac
MOCK
chmod +x "${fixture}/bin/docker"

(
  cd "${fixture}/Consolidador"
  DOCKER_HOST="unix://${fixture}/mock-docker.sock" \
    RC6_DOCKER_CALLS="${fixture}/docker-calls.log" \
    PATH="${fixture}/bin:${PATH}" ./DETENER.sh
) > "${fixture}/stop.log" 2>&1 || {
  cat "${fixture}/stop.log" >&2
  exit 1
}
[[ "$(grep -c '^docker rm ' "${fixture}/docker-calls.log")" == 1 ]] ||
  { printf 'RC6_STOP_FAILED retiró contenedores ajenos\n' >&2; exit 1; }
grep -q 'assistant_oneoff_retirados=1' "${fixture}/stop.log" ||
  { printf 'RC6_STOP_FAILED no retiró el one-off\n' >&2; exit 1; }
grep -q '^docker compose --project-name moodle-consolidation-production --profile live stop moodle-cron moodle-target db$' \
  "${fixture}/docker-calls.log" ||
  { printf 'RC6_STOP_FAILED no detuvo servicios del proyecto\n' >&2; exit 1; }
printf 'RC6_STOP_OK own_oneoff=removed regular=preserved other_project=preserved other_service=preserved\n'
