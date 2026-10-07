from pathlib import Path

root = Path(__file__).resolve().parents[1]

launcher = (
    root / "moodle-consolidation.sh"
).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(
            "V8_RC9_BACKGROUND_LINGER_FAILED: " + message
        )


for needle in (
    'local unit log_path linger_value current_user',
    'current_user="$(id -un)"',
    'command -v loginctl',
    'loginctl show-user "${current_user}" -p Linger --value',
    '[[ "${linger_value}" != "yes" ]]',
    'SEGUNDO_PLANO_BLOQUEADO reason=linger_required',
    'sudo loginctl enable-linger %q',
    'SEGUNDO_PLANO_OK runner=systemd unit=%s linger=yes',
):
    require(
        launcher.count(needle) == 1,
        f"contrato ausente o duplicado: {needle}",
    )

linger_pos = launcher.index(
    'loginctl show-user "${current_user}" '
    '-p Linger --value'
)

systemd_pos = launcher.index(
    'if systemd-run --user'
)

require(
    linger_pos < systemd_pos,
    "systemd-run ocurre antes de validar Linger",
)

require(
    "nohup " in launcher,
    "se perdió el fallback histórico nohup",
)

require(
    "--property=Restart=no" in launcher,
    "se alteró configuración de la unidad systemd",
)

print(
    "V8_RC9_BACKGROUND_LINGER_OK "
    "systemd=guarded "
    "linger=required "
    "logout_contract=fail_closed "
    "nohup=preserved"
)
