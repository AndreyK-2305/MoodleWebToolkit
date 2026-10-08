#!/usr/bin/env python3

from pathlib import Path
import hashlib
import os
import stat
import subprocess
import tempfile


root = Path(__file__).resolve().parents[1]


def require(condition, message):
    if not condition:
        raise SystemExit(
            "V8_RC12_BACKGROUND_WRAPPER_FAILED: "
            + message
        )


launcher = (
    root / "moodle-consolidation.sh"
).read_text(encoding="utf-8-sig")


wrapper_exact = (
    '"${project_root}/scripts/'
    'run-background-with-progress.sh"'
)


# Dos invocaciones reales:
# 1. systemd
# 2. nohup
require(
    launcher.count(wrapper_exact) == 2,
    "cantidad de invocaciones exactas al wrapper != 2",
)


# Tres referencias totales:
# inventario ENGINE_FILES + systemd + nohup.
require(
    launcher.count(
        "scripts/run-background-with-progress.sh"
    ) == 3,
    "cantidad total de referencias al wrapper != 3",
)


systemd_expected = (
    "/usr/bin/env bash \\\n"
    '          "${project_root}/scripts/'
    'run-background-with-progress.sh" \\\n'
    '          "--workers=${CONSOLIDATION_WORKERS}"; then'
)

require(
    systemd_expected in launcher,
    "systemd no invoca wrapper mediante bash",
)


nohup_expected = (
    "nohup /usr/bin/env bash \\\n"
    '    "${project_root}/scripts/'
    'run-background-with-progress.sh" \\\n'
    '    "--workers=${CONSOLIDATION_WORKERS}"'
)

require(
    nohup_expected in launcher,
    "nohup no invoca wrapper mediante bash",
)


# Reproducir la condición exacta que rompió RC11:
# wrapper presente pero sin bit ejecutable.
with tempfile.TemporaryDirectory() as directory:
    script = Path(directory) / "wrapper.sh"

    script.write_text(
        "#!/usr/bin/env bash\n"
        "printf 'RC12_PERMISSION_SMOKE_OK\\n'\n",
        encoding="utf-8",
    )

    os.chmod(script, 0o644)

    require(
        stat.S_IMODE(script.stat().st_mode) == 0o644,
        "fixture no quedó en modo 0644",
    )

    direct_blocked = False

    try:
        direct = subprocess.run(
            [str(script)],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            check=False,
        )

        direct_blocked = direct.returncode != 0

    except PermissionError:
        direct_blocked = True

    require(
        direct_blocked,
        "wrapper 0644 resultó ejecutable directamente",
    )

    via_bash = subprocess.run(
        [
            "/usr/bin/env",
            "bash",
            str(script),
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
    )

    require(
        via_bash.returncode == 0,
        "bash no pudo ejecutar wrapper 0644",
    )

    require(
        "RC12_PERMISSION_SMOKE_OK"
        in via_bash.stdout,
        "smoke test no produjo salida esperada",
    )


# RC12 no puede alterar el motor de Fase 13.
expected_phase13 = (
    "e4650c0e171edb29ffa2802d605f4eb"
    "14e8bd06fd1de2ec7a45e28d7ef611430"
)

actual_phase13 = hashlib.sha256(
    (
        root /
        "scripts/phase6-apply.ps1"
    ).read_bytes()
).hexdigest()

require(
    actual_phase13 == expected_phase13,
    "phase6-apply.ps1 cambió",
)


print(
    "V8_RC12_BACKGROUND_WRAPPER_OK "
    "systemd=bash "
    "nohup=bash "
    "mode0644=accepted "
    "phase13=unchanged"
)
