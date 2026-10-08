from pathlib import Path
import hashlib

root = Path(__file__).resolve().parents[1]


def text(relative):
    return (root / relative).read_text(
        encoding="utf-8-sig"
    )


def require(ok, message):
    if not ok:
        raise SystemExit(
            "V8_RC11_PROGRESS_LIFECYCLE_FAILED: " +
            message
        )


launcher = text("moodle-consolidation.sh")
wrapper = text(
    "scripts/run-background-with-progress.sh"
)

start = launcher.index("run_assistant() {")
end = launcher.index(
    "\n}\n\napply_identity_review()",
    start,
)

run = launcher[start:end]

require(
    'if [[ "${execution_mode}" == "interactive" ]]; then'
    in run,
    "notifier interactivo ausente",
)

require(
    "./scripts/progress-notifier.ps1" in run,
    "run_assistant no ejecuta notifier",
)

require(
    "progress-notifier.active" in run,
    "run_assistant no controla active file",
)

require(
    'progress_notifier_pid=$!' in run,
    "PID del notifier interactivo ausente",
)

require(
    'wait "${progress_notifier_pid}" '
    '2>/dev/null || true' in run,
    "notifier interactivo no se espera al cerrar",
)

start_pos = run.index(
    'if [[ "${execution_mode}" == "interactive" ]]; then'
)

wizard_pos = run.index(
    'if [[ "${execution_mode}" == "automatic" ]]; then'
)

cleanup_pos = run.index(
    'rm -f -- "${progress_active_file}"'
)

handoff_pos = run.index(
    'if [[ ${assistant_exit} -eq 24'
)

require(
    start_pos < wizard_pos,
    "notifier empieza después del wizard",
)

require(
    cleanup_pos < handoff_pos,
    "notifier no termina antes del handoff background",
)

require(
    run.count(
        "./scripts/progress-notifier.ps1"
    ) == 1,
    "notifier interactivo duplicado",
)

require(
    "./scripts/progress-notifier.ps1" in wrapper,
    "wrapper background perdió notifier",
)

require(
    'ejecutar-automatico' in wrapper,
    "wrapper background perdió ejecución automática",
)

require(
    launcher.count(
        "run-background-with-progress.sh"
    ) == 3,
    "rutas background inesperadas",
)

phase13 = root / "scripts/phase6-apply.ps1"

actual = hashlib.sha256(
    phase13.read_bytes()
).hexdigest()

expected = (
    "e4650c0e171edb29ffa2802d605f4eb"
    "14e8bd06fd1de2ec7a45e28d7ef611430"
)

require(
    actual == expected,
    "phase6-apply.ps1 fue modificado",
)

print(
    "V8_RC11_PROGRESS_LIFECYCLE_OK "
    "interactive=covered "
    "handoff=ordered "
    "background=covered "
    "duplicate=blocked "
    "phase13=unchanged"
)
