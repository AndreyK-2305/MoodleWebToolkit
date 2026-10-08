#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

fixture="$(mktemp -d -t v8-theme-policy-XXXXXXXX)"
trap 'rm -rf -- "${fixture}"' EXIT
mkdir -p "${fixture}/project/config-manager" "${fixture}/project/docker" \
  "${fixture}/bin" "${fixture}/managed"
cp config-manager/config-manager.sh config-manager/default-settings.json \
  "${fixture}/project/config-manager/"
cp docker/managed-config.php "${fixture}/project/docker/"
touch "${fixture}/mock-docker.sock"
python3 - "${fixture}/project/config-manager/config-manager.sh" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
old = '[[ -S "${socket_path}" ]] ||'
if text.count(old) != 1:
    raise SystemExit('V8_THEME_POLICY_TEST_FAILED control de socket inesperado')
path.write_text(text.replace(old, '[[ -e "${socket_path}" ]] ||', 1))
PY
cat > "${fixture}/project/.env" <<ENV
MOODLE_MANAGED_CONFIG_DIR=${fixture}/managed
MOODLE_TARGET_IMAGE=moodle-consolidation-target:5.2.1-v8.0.0
CONSOLIDATION_EMAIL_ENABLED=0
ENV
cat > "${fixture}/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -Eeuo pipefail
case "${1:-}" in
  info) exit 0 ;;
  image)
    [[ "${2:-}" == inspect ]] || exit 90
    exit 0
    ;;
  inspect)
    format="${3:-}"
    if [[ "${format}" == *Health* ]]; then
      printf 'healthy\n'
    else
      printf 'running\n'
    fi
    ;;
  compose)
    if [[ "${2:-}" == version ]]; then exit 0; fi
    shift
    [[ "${1:-}" == --project-name &&
        "${2:-}" == moodle-consolidation-production ]] || exit 90
    shift 2
    [[ "${1:-}" == --profile ]] && shift 2
    case "${1:-}" in
      ps)
        service="${@: -1}"
        [[ "${service}" == moodle-target ]] && printf 'target-id\n'
        exit 0
        ;;
      run)
        args=("$@")
        tool_index=-1
        for i in "${!args[@]}"; do
          if [[ "${args[$i]}" == /usr/local/lib/moodle-managed-config.php ]]; then
            tool_index="$i"
            break
          fi
        done
        [[ "${tool_index}" -ge 0 ]] || exit 90
        translated=()
        for arg in "${args[@]:$((tool_index + 1))}"; do
          translated+=("${arg//\/run\/moodle-config/${V8_TEST_MANAGED}}")
        done
        exec php "${V8_TEST_PROJECT}/docker/managed-config.php" "${translated[@]}"
        ;;
      up|stop) exit 0 ;;
      exec)
        expected_theme=""
        expected_allow=""
        for arg in "$@"; do
          case "${arg}" in
            V8_EXPECTED_THEME=*) expected_theme="${arg#*=}" ;;
            V8_EXPECTED_ALLOWCOURSETHEMES=*) expected_allow="${arg#*=}" ;;
          esac
        done
        if [[ -n "${expected_theme}" ]]; then
          if [[ "${V8_TEST_EFFECTIVE_MISMATCH:-0}" == 1 ]]; then
            printf 'THEME_POLICY_EFFECTIVE_MISMATCH simulated=1\n' >&2
            exit 1
          fi
          actual_theme="$(php -r '
            $d=json_decode(file_get_contents($argv[1]),true);
            echo $d["settings"]["theme"] ?? "";
          ' "${V8_TEST_MANAGED}/active/settings.json")"
          actual_allow="$(php -r '
            $d=json_decode(file_get_contents($argv[1]),true);
            echo (int)($d["settings"]["allowcoursethemes"] ?? 0);
          ' "${V8_TEST_MANAGED}/active/settings.json")"
          [[ "${actual_theme}" == "${expected_theme}" &&
              "${actual_allow}" == "${expected_allow}" ]] || exit 1
          printf 'THEME_POLICY_EFFECTIVE_OK theme=%s allowcoursethemes=%s\n' \
            "${actual_theme}" "${actual_allow}"
        fi
        exit 0
        ;;
      *) exit 90 ;;
    esac
    ;;
  *) exit 90 ;;
esac
MOCK
chmod +x "${fixture}/bin/docker" "${fixture}/project/config-manager/config-manager.sh"

run_manager() {
  (
    cd "${fixture}/project"
    DOCKER_HOST="unix://${fixture}/mock-docker.sock" \
      V8_TEST_PROJECT="${fixture}/project" \
      V8_TEST_MANAGED="${fixture}/managed" \
      PATH="${fixture}/bin:${PATH}" \
      ./config-manager/config-manager.sh "$@"
  )
}

output="$(run_manager aplicar-politica-theme --theme academi \
  --allow-course-themes 1 --motivo 'prueba Academi')"
grep -q 'THEME_POLICY_OK theme=academi allowcoursethemes=1' <<<"${output}"
php -r '
  $d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
  if (($d["settings"]["theme"] ?? null) !== "academi" ||
      ($d["settings"]["allowcoursethemes"] ?? null) !== 1) exit(1);
' "${fixture}/managed/active/settings.json"

history_before="$(find "${fixture}/managed/history" -mindepth 1 -maxdepth 1 -type d | wc -l)"
output="$(run_manager aplicar-politica-theme --theme academi \
  --allow-course-themes 1 --motivo 'prueba idempotencia')"
history_after="$(find "${fixture}/managed/history" -mindepth 1 -maxdepth 1 -type d | wc -l)"
[[ "${history_before}" == "${history_after}" ]]
grep -q 'sin_cambios=1' <<<"${output}"

run_manager aplicar-politica-theme --theme boost --allow-course-themes 1 \
  --motivo 'Boost explícito' >/dev/null
previous="$(readlink "${fixture}/managed/active")"
if V8_TEST_EFFECTIVE_MISMATCH=1 run_manager aplicar-politica-theme \
    --theme classic --allow-course-themes 1 --motivo 'forzar rollback' \
    >"${fixture}/rollback.log" 2>&1; then
  echo 'V8_THEME_POLICY_TEST_FAILED no revirtió mismatch efectivo' >&2
  exit 1
fi
[[ "$(readlink "${fixture}/managed/active")" == "${previous}" ]]
grep -q 'revirtiendo' "${fixture}/rollback.log"

if grep -En 'Read-Host|read[[:space:]]+-r' \
    scripts/v8-course-themes.php scripts/v8-course-themes.ps1 >/dev/null; then
  echo 'V8_THEME_POLICY_TEST_FAILED prompt de theme después de READY' >&2
  exit 1
fi
grep -Eq "'--mode=theme-status'" scripts/v8-readiness.ps1
grep -Eq "'aplicar-politica-theme'" scripts/v8-readiness.ps1

printf '%s\n' 'V8_THEME_POLICY_OK academi=applied boost=explicit idempotent=1 effective=verified rollback=1 background_prompt=0'
