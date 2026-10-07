from pathlib import Path

root = Path(__file__).resolve().parents[1]

wizard = (root / "scripts/consolidation-wizard.ps1").read_text(encoding="utf-8")
readiness = (root / "scripts/v8-readiness.ps1").read_text(encoding="utf-8")
review = (root / "scripts/v8-identity-review.ps1").read_text(encoding="utf-8")

def require(condition, message):
    if not condition:
        raise SystemExit("V8_RC7_FLOW_UX_FAILED " + message)

# PREPARE todavía debe detenerse.
require(
    "V8_IDENTITY_REVIEW_REQUIRED: IDENTITY_REVIEW_PREPARED" in readiness,
    "prepare fuzzy dejó de ser intervención manual"
)

# IMPORT válido ya no debe pedir relanzamiento.
require(
    "V8_IDENTITY_REVIEW_IMPORTED: revisión fuzzy válida" in readiness,
    "import fuzzy no emite señal de reinicio interno"
)

require(
    "V8_IDENTITY_REVIEW_REQUIRED: IDENTITY_REVIEW_IMPORTED" not in readiness,
    "permanece el contrato antiguo que obligaba a relanzar"
)

# Wizard reconoce IMPORTED antes de clasificar REQUIRED.
imported = wizard.find("$identityReviewImported = $message.StartsWith(")
required = wizard.find("$workflowRestartRequired = $message.StartsWith(")

require(imported >= 0, "wizard no reconoce import fuzzy válido")
require(required > imported, "import fuzzy se evalúa después del bloqueo manual")

require(
    'return $true' in wizard[imported:required],
    "wizard no retorna al bucle principal después del import"
)

require(
    "IDENTITY_REVIEW_IMPORTED_OK" in wizard,
    "falta diagnóstico visible del reinicio automático"
)

# Los IDs internos permanecen.
for stage_id in (
    '03b-oauth2-final',
    '05b-readiness',
    '09b-themes-piloto',
    '13b-themes-lote',
):
    require(stage_id in wizard, f"se alteró ID interno {stage_id}")

# Pero los títulos visibles quedan numerados.
for title in (
    "3. Validar compatibilidad e instalar plugins requeridos",
    "3.1. Confirmar OAuth y proxy antes de conciliar identidades",
    "5.1. Preparar y sellar la ejecución V8",
    "9.1. Aplicar y verificar themes del curso piloto",
    "13.1. Aplicar y verificar themes de los cursos",
):
    require(title in wizard, f"falta título visible: {title}")

require(
    "Decisiones fuzzy importadas y validadas." in review,
    "importador conserva mensaje de relanzamiento manual"
)

print(
    "V8_RC7_FLOW_UX_OK "
    "fuzzy=auto-restart prepare=manual "
    "stage_ids=stable numbering=visible"
)
