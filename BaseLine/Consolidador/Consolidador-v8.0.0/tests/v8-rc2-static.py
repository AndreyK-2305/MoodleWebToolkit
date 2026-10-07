#!/usr/bin/env python3
"""Regresiones estáticas del contrato operativo V8 RC2."""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]


def source(name: str) -> str:
    return (root / name).read_text(encoding="utf-8-sig")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit("V8_RC2_STATIC_FAILED: " + message)


config_access = source("scripts/ConfigAccess.ps1")
common = source("scripts/Common.ps1")
wizard = source("scripts/consolidation-wizard.ps1")
plugins = source("scripts/audit-package-plugins.ps1")
enable = source("scripts/target-enable-plugin.php")
pin_helper = source("scripts/target-plugin-pin.php")
readiness = source("scripts/v8-readiness.ps1")
themes = source("scripts/v8-course-themes.ps1")
launcher = source("moodle-consolidation.sh")
prepare = source("scripts/phase6-package-prepare.ps1")
apply = source("scripts/phase6-apply.ps1")
course = source("scripts/phase6-apply-course.php")
degradation = source("scripts/phase6-degradation.php")
worker = source("scripts/phase6-course-worker.ps1")
phase6lib = source("scripts/phase6-lib.php")

# A: instalación limpia no depende de Common.ps1 para inicializar el lock.
require("function Get-DestinationWriteLockPath" in config_access,
        "write-lock path no está centralizado en ConfigAccess")
require("$DestinationWriteLockPath = Get-DestinationWriteLockPath" in common,
        "Common no consume la ruta central")
require("$DestinationWriteLockPath = Get-DestinationWriteLockPath" in wizard,
        "wizard limpio no inicializa la ruta del lock")
require("Test-Path -LiteralPath $DestinationWriteLockPath" in wizard,
        "wizard perdió el guard monotónico post-write")

# B-D: readiness real, upgrade serializado y habilitación pinned.
for needle in ("DESTINATION_STARTUP_READY", "DESTINATION_UPGRADE_REQUIRED",
               "PLUGIN_UPGRADE_FAILED", "Invoke-SerializedDestinationUpgrade",
               "/run/moodle-upgrade.lock"):
    require(needle in plugins, f"falta evento de upgrade {needle}")
require("PLUGIN_UPGRADE_WAIT_TIMEOUT_SECONDS" in plugins,
        "timeout de upgrade no es configurable")
require("Test-ApprovedAdditionalPlugin" in plugins and
        "TARGET_PLUGIN_PIN_VERIFIED" in plugins,
        "plugin adicional no exige pin exacto")
for field in ("version_disk", "approved_commit", "tree_sha256", "release"):
    require(field in plugins, "pin adicional no comprueba " + field)
require("if ($additional)" in enable and
        "target_plugin_verify_additional_pin(" in enable and
        "'/opt/approved-plugins.json'" in enable and
        "!(method_exists($plugin, 'is_standard') && $plugin->is_standard())" in enable,
        "target-enable-plugin no revalida adicionales contra el manifest runtime")
require("$callerPinClaim" in enable and "caller_claim_ignored" in pin_helper and
        "TARGET_PLUGIN_PIN_REQUIRED" in pin_helper and
        "pin_tree($actualRoot, $children)" in pin_helper,
        "--pin-verified volvió a ser autoridad o no se valida el árbol instalado")
for field in ("TARGET_PLUGIN_PIN_VERSION_MISMATCH",
              "TARGET_PLUGIN_PIN_RELEASE_MISMATCH",
              "TARGET_PLUGIN_PIN_SUBMODULE_TREE_MISMATCH"):
    require(field in pin_helper, "verificación runtime incompleta: " + field)
require(("$" + "{component}:") in plugins and '"$component:"' not in plugins,
        "se reintrodujo ParserError $component:")
require("WAITING_USER_ACTION" in wizard and
        "[R] Revalidar plugins | [S] Salir" in wizard,
        "intervención de plugins cerraría el asistente")

# E: whitelist OAuth derivada, limitada a /exports y sin traversal.
pattern = re.compile(r"^/exports/(phase[1-8]|oauth2(?:-[a-z0-9][a-z0-9_-]*)?)(/[a-zA-Z0-9._-]+)*$")
for accepted in ("/exports/oauth2", "/exports/oauth2-live",
                 "/exports/oauth2-live-pre-phase4"):
    require(pattern.fullmatch(accepted) is not None, "OAuth válido rechazado: " + accepted)
for rejected in ("/exports/oauth2/../phase4", "/tmp/oauth2-live",
                 "/exports/oauth2-live/$bad", "/exports/oauth2--bad"):
    segments = [part for part in rejected.split("/") if part]
    require(pattern.fullmatch(rejected) is None or "." in segments or ".." in segments,
            "ruta arbitraria aceptada: " + rejected)
require(pattern.pattern in common, "Common no contiene el contrato OAuth probado")
require("$segments -contains '..'" in common,
        "Common no bloquea traversal OAuth")

# F: permisos mínimos y prueba con el consumidor real.
for needle in ("Grant-ThemeTransportAccess", "Assert-ThemeTransportAccess",
               "'www-data'", "chmod u=rwx,g=rwx,o= /exports",
               "chmod u=rwx,g=rx,o=", "chmod u=rw,g=r,o="):
    require(needle in common, "transporte theme incompleto: " + needle)
require("Grant-ThemeTransportAccess" in readiness and
        "Assert-ThemeTransportAccess" in readiness,
        "readiness no prueba themes como www-data")
require("Grant-ThemeTransportAccess" in themes and
        "Assert-ThemeTransportAccess" in themes,
        "aplicación de themes no revalida transporte")
require("chmod 777" not in common, "permisos themes usan 777")

# G-J: Fase 12 ligera; el worker hace precheck y delega estructuras a Moodle.
for needle in ("p6_build_course_degradation_plan", "omit_known_missing",
               "STRUCTURAL_OBSERVATION", "delegate_to_moodle_native_restore",
               "p6_load_course_degradation_plan"):
    require(needle in degradation, "contrato de degradación incompleto: " + needle)
require("phase6-analyze-degradations.php" not in prepare and
        "phase6-prepare-package-course.php" not in prepare,
        "Fase 12 todavía abre/analiza todos los cursos")
for needle in ("BATCH_READY", "PHASE12_READY", "MBZ_DEEP_OPEN_COUNT=0"):
    require(needle in prepare, "Fase 12 ligera no publica " + needle)
require(worker.index("phase6-prepare-package-course.php") <
        worker.index("phase6-analyze-course.php") <
        worker.index("phase6-apply-course.php"),
        "el worker no prepara, analiza y restaura un curso en orden")
require("p6_load_course_degradation_plan" in course and
        "p6_apply_degradation_plan_to_extracted_backup" in phase6lib,
        "Fase 13 no consume el plan por curso sellado")
require("p6_normalize_legacy_random_questions($directory)" in phase6lib and
        "DELEGATED_TO_MOODLE_NATIVE_RESTORE" not in phase6lib,
        "el camino activo no normaliza random questions de forma fail-closed")
require("WORKER_WARNING" in apply and "completed_with_warnings" in apply,
        "warnings aprobados todavía parecen WORKER_FAILED")
require("STRUCTURAL_OBSERVATION" in course and
        "COURSE_RESTORE_WARNING" in course and
        "missing_payloads_omitted=" in course,
        "Fase 13 no emite observaciones auditables")
for needle in ("SOURCE_DATA_DEFECT", "MOODLE_RESTORE_INCOMPATIBILITY",
               "PRECONDITION_BUG", "TOOL_INTERNAL_ERROR"):
    require(needle in worker, "clasificación de worker incompleta: " + needle)
require("$stopAssigning = $true" not in apply and "STOP_ASSIGNING" in apply,
        "un fallo aislado detiene la asignación global")

# K-M: elección tardía y runner desacoplado, con estado auditable.
require("¿Cómo desea ejecutar la consolidación masiva?" in wizard,
        "no existe elección foreground/background")
require(wizard.index("12-preparar-lote") <
        wizard.index("¿Cómo desea ejecutar la consolidación masiva?") <
        wizard.index("13-aplicar-lote"),
        "elección de modo no está entre Fase 12 y Fase 13")
for needle in ("BACKGROUND_HANDOFF_REQUESTED", "exit 24",
               "CONSOLIDATION_MASS_EXECUTION_MODE=background"):
    require(needle in wizard + launcher, "handoff background incompleto: " + needle)
require("systemd-run --user" in launcher and "nohup" in launcher,
        "background no sobrevive desconexión SSH")
require("flock -n" in launcher and "background_running" in launcher,
        "background permite ejecuciones duplicadas")
require("WAITING_USER_ACTION" in wizard and "Send-ConsolidationNotification" in wizard,
        "intervención background no deja estado/notificación")

# N: guards pre-write siguen condicionados por el lock.
for needle in (
    "-not $postWriteStarted -and\n            (-not (Test-PluginAudit)",
    "-not $postWriteStarted -and -not (Test-OAuth2LiveReady)",
    "-not $postWriteStarted -and -not (Test-IdentityReconciliation)",
    "-not $postWriteStarted -and -not (Test-V8Readiness)",
):
    require(needle in wizard, "guard monotónico perdido: " + needle)

require(source("VERSION.txt").splitlines()[0] == "8.0.0-linux-rc12",
        "VERSION.txt no es RC11")
changes = source("CAMBIOS-8.0.0-RC2.md")
testdoc = source("PRUEBA-V8-RC2.md")
for needle in ("Ajustes posteriores a revisión RC2",
               "course_resolution_sha256", "known_degraded",
               "expected_complete", "pin-verified", "BATCH_READY",
               "delegate_to_moodle_native_restore"):
    require(needle in changes, "changelog RC2 incompleto: " + needle)
for needle in ("MBZ_DEEP_OPEN_COUNT=0", "reconstruye únicamente el afectado",
               "Pregrado", "Posgrados", "claim simulado `--pin-verified=1`",
               "STRUCTURAL_OBSERVATION"):
    require(needle in testdoc, "protocolo RC2 incompleto: " + needle)
print("V8_RC2_STATIC_OK tests=A-N oauth=derived themes=www-data "
      "phase12=lightweight phase13=worker-precheck pins=runtime-verified "
      "incremental=course-scoped background=detached")
