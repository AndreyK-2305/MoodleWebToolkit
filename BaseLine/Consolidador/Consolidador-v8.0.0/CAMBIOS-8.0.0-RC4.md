# Consolidador 8.0.0-linux-rc4

**Candidata para auditoría de Fase 13. No es una versión estable.** Se construye
desde la RC2 original e incorpora de forma trazable los hotfixes validados en
la ejecución denominada RC3. No requiere regenerar paquetes, inventarios,
planes, jobs, manifiestos ni resultados de fases anteriores.

## Hotfixes RC3 portados

- HF2: `phase6-course-worker.ps1` consume el `plan_sha256` de
  `COURSE_PRECHECK_OK`; si falta o no tiene 64 hexadecimales, termina como
  `FATAL_SYSTEMIC/TOOL_INTERNAL_ERROR`. El host no vuelve a leer el plan.
- HF3: el worker contrasta `SourceId` con `course_plan.csv`, registra
  `WORKER_SOURCE_CORRECTED` ante una violación y usa el valor planificado.
- HF4: la verificación académica usa `source_state_sha256` del `course_job`
  firmado, no el alias opcional del manifiesto ligero.
- HF5: `COURSE_PRECHECK_BLOCKED` incluye el primer blocker en JSON compacto,
  sin modificar la severidad ni omitir el reporte completo.

## Correcciones RC4

### Rutas reales de plugins

El inventario usa `core_plugin_manager` y el `rootdir` efectivo de cada plugin.
El verificador de pins acepta rutas de repositorio `public/...` cuando
`$CFG->dirroot` ya apunta a `.../public`, sin duplicar ese segmento. Distingue
`installed_localizable`, `declared_missing`, `core_component` y
`unknown_component`; una ausencia real continúa bloqueando el preflight.

### SourceId por curso

El scheduler construye una asociación autoritativa `course_key -> source`
desde el `course_plan.csv` existente, valida que el manifiesto ligero no la
contradiga y pasa el valor resuelto explícitamente a cada worker. El worker
conserva HF3 solo como defensa; una ejecución normal debe producir cero
`WORKER_SOURCE_CORRECTED`.

### Residuos `qtype=random`

La extracción temporal de cada curso pasa por el normalizador conservador
antes del restore. Solo se retiran entradas legacy demostrablemente huérfanas
o redundantes. Referencias activas, cardinalidad discordante, semántica
contradictoria, IDs/stamps inválidos o estructura ambigua bloquean el curso.
No se fabrican `question_set_references`, no se modifica el MBZ sellado y se
conservan SHA-256, métricas e inventario completo de la normalización.

## Reinicio controlado de Fase 13

`REINICIAR-FASE13.sh` archiva únicamente logs y estados transitorios del
coordinador. Conserva `exports/packages`, `course_plan.csv`, manifiestos,
jobs, inventarios, planes de degradación, `apply-states` y
`apply-checkpoints`. Se niega a actuar si hay una ejecución activa o si el
lote ya posee `batch_apply_summary.json`.

## Alcance preservado

Permanecen intactos el pool dinámico, largest-first, checkpoints por curso,
resume, reuse/cleanup de cursos retenidos, `WAITING_MANUAL`,
`target_course_id`, auditorías, pertenencia al lote y todas las verificaciones
académicas heredadas. La normalización nunca modifica paquetes fuente.
