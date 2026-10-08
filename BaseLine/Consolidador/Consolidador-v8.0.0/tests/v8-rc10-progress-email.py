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
            "V8_RC10_PROGRESS_EMAIL_FAILED: " +
            message
        )


launcher = text("moodle-consolidation.sh")
phase1 = text("scripts/import-source-packages.ps1")
phase13 = text("scripts/phase6-apply.ps1")
notifier = text("scripts/progress-notifier.ps1")
wrapper = text("scripts/run-background-with-progress.sh")
env_example = text(".env.example")

require(
    "scripts/progress-notifier.ps1" in launcher,
    "notifier no es archivo requerido"
)

require(
    "scripts/run-background-with-progress.sh" in launcher,
    "wrapper no es archivo requerido"
)

require(
    launcher.count(
        "run-background-with-progress.sh"
    ) == 3,
    "referencias al wrapper inesperadas"
)

require(
    "systemd-run --user" in launcher,
    "se perdió systemd"
)

require(
    "nohup " in launcher,
    "se perdió fallback nohup"
)

require(
    "CONSOLIDATION_PROGRESS_EMAIL_ENABLED"
    in launcher,
    "configurador no escribe enabled"
)

require(
    "CONSOLIDATION_PROGRESS_EMAIL_INTERVAL_MINUTES"
    in launcher,
    "configurador no escribe intervalo"
)

require(
    "CONSOLIDATION_PROGRESS_EMAIL_INTERVAL_MINUTES=15"
    in env_example,
    "default no es 15 minutos"
)

require(
    "docker compose" in wrapper,
    "notifier no usa runtime Docker"
)

require(
    "assistant-runtime" in wrapper,
    "notifier no usa assistant-runtime"
)

require(
    "pwsh" in wrapper,
    "PowerShell no corre dentro del runtime"
)

require(
    "Send-ConsolidationNotification" in notifier,
    "notifier no reutiliza Notifications.ps1"
)

require(
    "'01-importar-paquetes'" in notifier,
    "Fase 1 ausente"
)

require(
    "'13-aplicar-lote'" in notifier,
    "Fase 13 ausente"
)

require(
    "fase-1-progress.json" in notifier,
    "snapshot de Fase 1 ausente"
)

require(
    "fase-6-workers-status.json" in notifier,
    "snapshot de Fase 13 ausente"
)

require(
    "reports\\fase-1-progress.json"
    in phase1,
    "Fase 1 no publica progreso"
)

require(
    "WORKERS_HEARTBEAT completed="
    in phase13,
    "heartbeat académico original ausente"
)

require(
    "Write-WorkerSnapshot"
    in phase13,
    "snapshot académico original ausente"
)

digest = hashlib.sha256(
    (root / "scripts/phase6-apply.ps1").read_bytes()
).hexdigest()

require(
    digest ==
    "e4650c0e171edb29ffa2802d605f4eb14e8bd06fd1de2ec7a45e28d7ef611430",
    "phase6-apply.ps1 fue modificado"
)

print(
    "V8_RC10_PROGRESS_EMAIL_OK "
    "phase1=snapshot_only "
    "phase13=unchanged "
    "notifier=assistant_runtime "
    "systemd=yes "
    "nohup=yes "
    "interval=15m"
)
