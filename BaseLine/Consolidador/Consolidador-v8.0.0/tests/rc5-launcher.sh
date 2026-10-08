#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

python3 - <<'PY'
from pathlib import Path
import yaml
assistant = yaml.safe_load(Path('compose.yaml').read_text())['services']['assistant-runtime']
assert assistant['profiles'] == ['tools'], 'RC8_PROFILE_FAILED: perfil real diferente'
assert assistant['working_dir'].startswith('${ASSISTANT_PROJECT_ROOT:'), 'RC8_PROFILE_FAILED: working_dir'
assert assistant['environment']['ASSISTANT_PROJECT_ROOT'] == '${ASSISTANT_PROJECT_ROOT}'
assert '${ASSISTANT_PROJECT_ROOT}:${ASSISTANT_PROJECT_ROOT}' in assistant['volumes']
PY

fixture="$(mktemp -d -t rc5-launcher-XXXXXXXX)"
cleanup() {
  rm -rf -- "${fixture}"
}
trap cleanup EXIT

# La copia conserva el árbol exacto del paquete que ejecuta verify-package.sh.
# La infraestructura simulada llega al pwsh real sin tocar Docker ni Moodle.
cp -a . "${fixture}/Consolidador"
project="${fixture}/Consolidador"

# El paquete distribuido contiene config.yaml. Este fixture prueba
# explícitamente el arranque limpio sin configuración generada, por lo que
# elimina únicamente la copia aislada del fixture.
rm -f "${project}/config.yaml"

mkdir -p "${fixture}/bin" "${project}/copias"
cat > "${project}/.env" <<'ENV'
MOODLE_PUBLIC_URL=https://moodle.example.edu
MOODLE_INTERNAL_HEALTH_URL=http://127.0.0.1:8090
MOODLE_ADMIN_PASSWORD=ValidPassword123@
CONSOLIDATION_EMAIL_ENABLED=0
ENV
touch "${project}/copias/origen1.zip" "${project}/copias/origen2.zip"
cat > "${project}/scripts/import-source-packages.ps1" <<'PS'
Write-Host 'RC5_LAUNCHER_PHASE1_ENTERED'
throw 'RC5_LAUNCHER_STOP_TEST'
PS
cat > "${fixture}/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "${1:-}" == info ]]; then exit 0; fi
if [[ "${1:-}" == inspect ]]; then
  case "${3:-}" in
    *Health*) printf 'healthy\n' ;;
    *) printf 'running\n' ;;
  esac
  exit 0
fi
[[ "${1:-}" == compose ]] || exit 78
shift
if [[ "${1:-}" == version ]]; then exit 0; fi
[[ "${1:-}" == --project-name ]] || exit 78
shift 2
case "${1:-}" in
  --profile)
    [[ "${2:-}" == tools ]] || exit 78
    shift 2
    [[ "${1:-}" == config && "${2:-}" == --format && "${3:-}" == json ]] || exit 78
    root="${ASSISTANT_PROJECT_ROOT}"
    source="${root}"
    target="${root}"
    working_dir="${root}"
    environment_root="${root}"
    case "${MOCK_MOUNT_CASE:-correct}" in
      correct) ;;
      bad_source) source="${root}/different" ;;
      bad_target) target="${root}/different" ;;
      bad_workdir) working_dir="${root}/different" ;;
      bad_env) environment_root="${root}/different" ;;
      missing)
        printf '{"services":{"db":{},"moodle-target":{}}}\n'
        printf '%s\n' 'tools-config' >> "${MOCK_RUNTIME_CALLS}"
        exit 0
        ;;
      *) exit 78 ;;
    esac
    printf '{"services":{"assistant-runtime":{"working_dir":"%s","environment":{"ASSISTANT_PROJECT_ROOT":"%s"},"volumes":[{"type":"bind","source":"%s","target":"%s"}]}}}\n' \
      "${working_dir}" "${environment_root}" "${source}" "${target}"
    printf '%s\n' "${root}" > "${MOCK_ASSISTANT_ROOT_LOG}"
    printf '%s\n' 'tools-config' >> "${MOCK_RUNTIME_CALLS}"
    ;;
  config)
    [[ "${2:-}" == --format && "${3:-}" == json ]] || exit 78
    printf '{"services":{"db":{},"moodle-target":{}}}\n'
    printf '%s\n' 'default-config' >> "${MOCK_RUNTIME_CALLS}"
    ;;
  ps) printf 'rc5-target-container\n' ;;
  build) printf '%s\n' build >> "${MOCK_RUNTIME_CALLS}"; exit 0 ;;
  run)
    printf '%s\n' run >> "${MOCK_RUNTIME_CALLS}"
    if [[ " $* " == *' assistant-runtime sh -lc pwd '* ]]; then
      printf '%s\n' "${ASSISTANT_PROJECT_ROOT}"
      exit 0
    fi
    while (($#)); do
      if [[ "$1" == pwsh ]]; then exec "$@"; fi
      shift
    done
    exit 78
    ;;
  *) exit 78 ;;
esac
MOCK
chmod +x "${fixture}/bin/docker"

# Este runtime de pruebas no permite crear sockets Unix. Solo en la copia del
# fixture se acepta un archivo regular para superar la comprobación del socket;
# toda la ruta del entrypoint, Compose simulado y el pwsh real sigue ejecutándose.
socket="${fixture}/mock-docker.sock"
touch "${socket}"
python3 - "${project}/moodle-consolidation.sh" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
old = '[[ -S "${socket_path}" ]] ||'
if text.count(old) != 1:
    raise SystemExit('RC5_LAUNCHER_FAILED falta el control de socket conocido')
path.write_text(text.replace(old, '[[ -e "${socket_path}" ]] ||', 1))
PY
[[ -f "${socket}" && ! -e "${project}/config.yaml" ]] ||
  { printf 'RC5_LAUNCHER_FAILED fixture sin arranque limpio\n' >&2; exit 1; }

MOCK_RUNTIME_CALLS="${fixture}/plain-profile.log" \
  "${fixture}/bin/docker" compose --project-name moodle-consolidation-production \
    config --format json | python3 -c '
import json,sys
assert "assistant-runtime" not in json.load(sys.stdin)["services"]
' || { printf 'RC8_PROFILE_FAILED modelo base inesperado\n' >&2; exit 1; }
runtime_pwd="$(ASSISTANT_PROJECT_ROOT="${project}" \
  MOCK_RUNTIME_CALLS="${fixture}/pwd-calls.log" \
  "${fixture}/bin/docker" compose --project-name moodle-consolidation-production \
    run --rm assistant-runtime sh -lc pwd)"
[[ "${runtime_pwd}" == "${project}" ]] ||
  { printf 'RC8_MOUNT_FAILED pwd del runtime diferente\n' >&2; exit 1; }
for scenario in bad_source bad_target bad_workdir bad_env missing; do
  : > "${fixture}/calls-${scenario}.log"
  set +e
  (
    cd "${project}"
    printf 'continuar\n' | ASSISTANT_PROJECT_ROOT="${fixture}/Consolidador-rc5" \
      MOCK_MOUNT_CASE="${scenario}" MOCK_RUNTIME_CALLS="${fixture}/calls-${scenario}.log" \
      MOCK_ASSISTANT_ROOT_LOG="${fixture}/root-${scenario}.log" \
      DOCKER_HOST="unix://${socket}" PATH="${fixture}/bin:${PATH}" \
      ./INICIAR-CONSOLIDACION.sh
  ) > "${fixture}/output-${scenario}.log" 2>&1
  status="$?"
  set -e
  case "${scenario}" in
    bad_source|bad_target) expected='ASSISTANT_PROJECT_ROOT: bind source/target' ;;
    bad_workdir) expected='ASSISTANT_PROJECT_ROOT: working_dir' ;;
    bad_env) expected='ASSISTANT_PROJECT_ROOT: variable del contenedor' ;;
    missing) expected='ASSISTANT_RUNTIME_MISSING:' ;;
  esac
  if [[ "${status}" -eq 0 ]] ||
      ! grep -Eq "${expected}" "${fixture}/output-${scenario}.log" ||
      ! grep -Eq '^tools-config$' "${fixture}/calls-${scenario}.log" ||
      grep -Eq '^(build|run|default-config)$' "${fixture}/calls-${scenario}.log" ||
      grep -Eq 'RC5_LAUNCHER_PHASE1_ENTERED' "${fixture}/output-${scenario}.log"; then
    cat "${fixture}/output-${scenario}.log" >&2
    printf 'RC8_MOUNT_FAILED escenario=%s estado=%s\n' "${scenario}" "${status}" >&2
    exit 1
  fi
done

set +e
(
  cd "${project}"
  printf 'continuar\nsalir\n' | ASSISTANT_PROJECT_ROOT="${fixture}/Consolidador-rc5" \
    MOCK_ASSISTANT_ROOT_LOG="${fixture}/assistant-root.log" \
    MOCK_RUNTIME_CALLS="${fixture}/positive-calls.log" DOCKER_HOST="unix://${socket}" \
    PATH="${fixture}/bin:${PATH}" ./INICIAR-CONSOLIDACION.sh
) > "${fixture}/stdout.log" 2>&1
status="$?"
set -e
if [[ "${status}" -ne 0 ]] ||
    ! grep -Eq 'DESTINO_LISTO' "${fixture}/stdout.log" ||
    ! grep -Eq 'RC5_LAUNCHER_PHASE1_ENTERED' "${fixture}/stdout.log" ||
    grep -Eq 'No se encontró config.yaml' "${fixture}/stdout.log" ||
    [[ -e "${project}/config.yaml" ]]; then
  cat "${fixture}/stdout.log" >&2
  printf 'RC5_LAUNCHER_FAILED status=%s\n' "${status}" >&2
  exit 1
fi
[[ "$(cat "${fixture}/assistant-root.log")" == "${project}" ]] ||
  { printf 'RC8_PROJECT_ROOT_FAILED runtime heredó otro proyecto\n' >&2; exit 1; }
[[ "$(grep -Ec '^tools-config$' "${fixture}/positive-calls.log")" == 1 &&
    "$(grep -Ec '^build$' "${fixture}/positive-calls.log")" == 1 &&
    "$(grep -Ec '^run$' "${fixture}/positive-calls.log")" == 1 ]] &&
    ! grep -Eq '^default-config$' "${fixture}/positive-calls.log" ||
  { printf 'RC8_PROFILE_FAILED entrada a Fase 1 sin montar perfil tools\n' >&2; exit 1; }
python3 - "${project}/reports/assistant-state.json" <<'PY'
import json, sys
state = json.load(open(sys.argv[1], encoding='utf-8'))
if state['stage'] != '01-importar-paquetes' or state['status'] != 'paused':
    raise SystemExit('RC5_LAUNCHER_FAILED estado de Fase 1 incorrecto')
PY
printf 'RC5_LAUNCHER_OK entrypoint=1 target_mock=healthy phase1_without_config=1\n'
printf 'RC8_MOUNT_OK tools=1 default_omits=1 inherited_root=overwritten source_target_workdir_env=blocked missing=explicit phase1=entered\n'
