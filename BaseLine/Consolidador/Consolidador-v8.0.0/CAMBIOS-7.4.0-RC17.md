# Consolidador 7.4.0-linux-rc17

RC17 parte exactamente de RC16 y corrige de forma localizada la resolución
del contexto de módulo utilizada por la reconciliación de archivos en Fase 5.
Continúa siendo una candidata técnica; no se declara estable ni apta para el
benchmark real hasta completar esa ejecución.

## Corrección

- El `contextid` de cada actividad se obtiene de su XML primario
  `activities/<modname>_<moduleid>/<modname>.xml`; ya no se espera en
  `module.xml`, porque los MBZ reales de Moodle 4.5 no lo incluyen allí.
- Se aceptan `contextid` y `context_id` como atributo de la raíz o como hijo
  directo. La forma principal validada es el atributo raíz `contextid`.
- Cuando el XML primario declara `moduleid`/`module_id` o
  `modulename`/`modname`, se comprueba que coincidan con el módulo fuente.
- Contextos ausentes, cero, ambiguos o duplicados bloquean antes de continuar,
  con códigos diagnósticos explícitos.
- El mapeo `source_module_id:contextid` se ordena y sella con SHA-256 para
  demostrar resolución determinista.

## Contratos preservados

- Solo el contexto directo de módulo define la población de
  `relations.files`; `inforef.xml` corrobora o detecta conflicto.
- No se filtra por H5P, componente, nombre, tamaño ni `filearea`.
- Los archivos de tamaño cero con contexto directo siguen incluidos.
- Únicamente `assignfeedback_editpdf/combined`, `pages` y `partial` son
  regenerables. `readonlypages`, `stamps` y las demás áreas siguen estrictas.
- Se conservan la identidad determinista RC14, el multiconjunto semántico y
  el orden independiente RC15, la política de archivos RC16 y la verificación
  post-restore sin relajaciones.

## Auditoría

`module_relation_reconciliation.json` y `plan_summary.json` incorporan:

- `module_contexts_total`;
- `module_contexts_resolved`;
- `module_contexts_missing`;
- `module_contexts_ambiguous`;
- `module_context_duplicates`;
- diferencia de firmas y diferencia de filas del multiconjunto, conservando
  el campo histórico `files_multiset_difference`.

## Regresión

- `tests/rc17-activity-contexts.php` cubre `module.xml` sin contexto, las
  formas admitidas del XML primario, identidad discordante, ausencia, cero,
  ambigüedad, duplicado e idempotencia.
- `tests/rc17-editpdf-contract.php` fija la lista exacta de derivados
  regenerables y conserva estrictos `readonlypages`, `stamps` y tamaño cero.
- RC14, RC15 y RC16 continúan ejecutándose y conservan sus aserciones.

