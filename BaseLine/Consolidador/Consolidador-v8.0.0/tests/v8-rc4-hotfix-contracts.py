#!/usr/bin/env python3
"""Regresiones de los hotfix de campo que RC4 porta desde RC2 original."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]


def text(name: str) -> str:
    return (root / name).read_text(encoding="utf-8-sig")


def require(value: bool, message: str) -> None:
    if not value:
        raise SystemExit("V8_RC4_HOTFIX_CONTRACTS_FAILED " + message)


worker = text("scripts/phase6-course-worker.ps1")
apply = text("scripts/phase6-apply-course.php")
analyze = text("scripts/phase6-analyze-course.php")

# HF2: el host no vuelve a abrir el plan generado por www-data.
require("COURSE_PRECHECK_OK\\b.*\\bplan_sha256=" in worker,
        "HF2 no consume plan_sha256 del precheck")
require("Get-FileHash -LiteralPath $planPath" not in worker,
        "HF2 reintrodujo Get-FileHash del host")
require("precheck no devolvió plan_sha256" in worker and
        "TOOL_INTERNAL_ERROR" in worker,
        "HF2 no falla cerrado si falta el hash")

# HF3: la defensa consulta el mismo mapa autoritativo del scheduler.
require("Get-Phase6CourseSourceMap" in worker and
        "Resolve-Phase6CourseSource" in worker and
        "WORKER_SOURCE_CORRECTED" in worker,
        "HF3 no quedó como invariante del worker")

# HF4: el inventario se compara contra el course_job firmado.
require("(string)($bundle['course_job']['source_state_sha256'] ?? '')" in apply,
        "HF4 no usa source_state_sha256 desde course_job")

# HF5: stdout identifica el primer bloqueo sin cambiar su severidad.
require("COURSE_PRECHECK_BLOCKED" in analyze and
        "first_blocking=" in analyze and
        "blocking_errors=" in analyze,
        "HF5 no expone el primer blocker")
require("status=WAITING_MANUAL category=SOURCE_DATA_DEFECT" in analyze,
        "HF5 relajó el bloqueo")

print("V8_RC4_HOTFIX_CONTRACTS_OK hf2=1 hf3=1 hf4=1 hf5=1")
