#!/usr/bin/env python3
"""Contratos de las correcciones puntuales posteriores a RC2."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]


def text(relative: str) -> str:
    return (root / relative).read_text(encoding="utf-8-sig")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit("V8_RC2_PLAN_CONTRACT_FAILED: " + message)


entrypoint = text("docker/entrypoint.sh")
compose = text("compose.yaml")
plugins = text("scripts/audit-package-plugins.ps1")
enable = text("scripts/target-enable-plugin.php")
wizard = text("scripts/consolidation-wizard.ps1")
readiness = text("scripts/v8-readiness.ps1")
review = text("scripts/v8-identity-review.ps1")
launcher = text("moodle-consolidation.sh")
prepare = text("scripts/phase6-package-prepare.ps1")
apply = text("scripts/phase6-apply.ps1")
course = text("scripts/phase6-apply-course.php")
phase6lib = text("scripts/phase6-lib.php")
degradation = text("scripts/phase6-degradation.php")

# El healthcheck solo declara healthy después del upgrade controlado por entrypoint.
require("STARTUP_READY=/run/moodle-startup-ready" in entrypoint and
        'rm -f "${STARTUP_READY}"' in entrypoint,
        "entrypoint no invalida readiness al comenzar")
require('touch "${STARTUP_READY}"' in entrypoint,
        "entrypoint no publica readiness después del upgrade")
require("/run/moodle-startup-ready" in compose and
        compose.index("/run/moodle-startup-ready") < compose.index('require "/var/www/html/config.php"'),
        "healthcheck no exige marker y config.php")
require("/run/moodle-upgrade.lock" in entrypoint and "flock" in entrypoint,
        "upgrade de startup no está serializado")
for needle in ("Wait-DestinationStartupReady", "Test-DestinationUpgradeRequired",
               "Invoke-SerializedDestinationUpgrade", "PLUGIN_UPGRADE_FAILED"):
    require(needle in plugins, "auditor carece de " + needle)
for field in ("first_error", "component", "exit_code", "stdout", "stderr",
              "restart_count", "moodle_state"):
    require(field in plugins, "diagnóstico de upgrade carece de " + field)

# Un core retirado deja de bloquear solo cuando el replacement exacto está operativo.
for event in ("PLUGIN_REPLACEMENT_FOUND", "TARGET_PLUGIN_PIN_VERIFIED",
              "TARGET_PLUGIN_ENABLE_OK", "REMOVED_CORE_COMPONENT_RESOLVED"):
    require(event in plugins, "falta evento de replacement " + event)
require("Test-RemovedCoreComponentResolution" in plugins,
        "no existe validación explícita del replacement retirado")
require("caller_claim_ignored" in text("scripts/target-plugin-pin.php") and
        "target_plugin_verify_additional_pin" in enable,
        "se relajó el pin defensivo del ejecutor")

# La revisión fuzzy se opera con comandos públicos de Linux, sin pwsh del host.
require((root / "APLICAR-REVISION-IDENTIDADES.sh").is_file(),
        "falta wrapper público de revisión fuzzy")
require("aplicar-revision-identidades" in launcher,
        "launcher no expone importación de revisión")
require("[P] Preparar archivo de revisión" in readiness and
        "[I] Importar revisión completada" in readiness and
        "WAITING_USER_ACTION" in readiness,
        "wizard fuzzy no separa preparar/importar/salir")
require("pwsh scripts/v8-identity-review.ps1" not in review + text("README.md"),
        "la UX todavía exige pwsh en el host")

# Fase 12 solo crea y valida el manifiesto ligero.
require("phase6-prepare-package-course.php" not in prepare,
        "Fase 12 todavía prepara cada MBZ")
require("phase6-analyze-degradations.php" not in prepare,
        "Fase 12 todavía analiza profundamente todos los MBZ")
require("PHASE12_READY" in prepare and "BATCH_READY" in prepare,
        "Fase 12 no publica readiness ligero")
require("MBZ_DEEP_OPEN_COUNT=0" in prepare,
        "Fase 12 no audita que no abrió MBZ en profundidad")
require("phase6-prepare-package-course.php" in
        text("scripts/phase6-course-worker.ps1"),
        "la preparación específica no se movió al worker")

# qtype=random híbrido se normaliza; foros estructurales siguen delegados.
require("p6_normalize_legacy_random_questions($directory)" in phase6lib and
        "LEGACY_RANDOM_QUESTION_NORMALIZED" in phase6lib and
        "DELEGATED_TO_MOODLE_NATIVE_RESTORE" not in phase6lib,
        "random questions no se normalizan antes del restore")
for relation in ("forum_discussions", "forum_posts"):
    require(relation in degradation, "falta relación estructural " + relation)
require("STRUCTURAL_OBSERVATION" in degradation and
        "delegate_to_moodle_native_restore" in degradation,
        "mismatch estructural no se delega al restore nativo")
require("'blocking' => false" in degradation,
        "observación estructural sigue siendo bloqueante")

# Un curso aislado queda pendiente y los workers restantes continúan.
for status in ("SUCCESS", "WARNING", "WAITING_MANUAL", "FATAL_SYSTEMIC"):
    require(status in apply, "workers carecen de estado " + status)
for category in ("SOURCE_DATA_DEFECT", "MOODLE_RESTORE_INCOMPATIBILITY",
                 "PRECONDITION_BUG", "TOOL_INTERNAL_ERROR"):
    require(category in apply + course, "workers carecen de categoría " + category)
require("$stopAssigning = $true" not in apply,
        "un fallo individual todavía detiene globalmente la cola")
require("STOP_ASSIGNING" in apply,
        "fallo sistémico no conserva parada explícita")

# Los guards ya aprobados permanecen presentes.
require("Get-DestinationWriteLockPath" in text("scripts/ConfigAccess.ps1") and
        "$postWriteStarted = Test-Path -LiteralPath $DestinationWriteLockPath" in wizard,
        "regresión del lock monotónico post-write")
for guard in ("Test-PluginAudit", "Test-OAuth2LiveReady",
              "Test-IdentityReconciliation", "Test-V8Readiness"):
    require("-not $postWriteStarted" in wizard and guard in wizard,
            "regresión del guard post-write: " + guard)

print("V8_RC2_PLAN_CONTRACTS_OK upgrade=serialized replacement=resolved "
      "fuzzy=linux phase12=lightweight structural=native workers=continue")
