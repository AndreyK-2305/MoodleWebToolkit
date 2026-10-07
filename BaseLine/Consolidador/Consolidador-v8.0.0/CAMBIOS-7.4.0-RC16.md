# Consolidador 7.4.0-linux-rc16

RC16 parte de RC15 y corrige de forma localizada la población de
`relations.files` reconstruida desde el MBZ en Fase 5. Continúa siendo una
candidata técnica, no una versión estable.

## Corrección

- `files.xml.contextid` debe coincidir con el contexto directo de un
  `module.xml` inventariado para que la fila pertenezca a `relations.files`.
- `inforef.xml` corrobora la asociación o detecta inconsistencias, pero ya no
  amplía por sí solo la población de archivos de módulo.
- Un contexto directo sin `inforef` se incluye; contexto e `inforef`
  coincidentes se incluyen una sola vez; si contradicen, el plan bloquea con
  `MODULE_FILE_EVIDENCE_CONFLICT`.
- Archivos de otros contextos —incluidos H5P libraries, user o course—
  permanecen intactos en el MBZ y solo quedan fuera de esta relación.
- No se introdujo ningún filtro por componente, nombre, `filearea`, tamaño o
  lista específica de H5P.
- Los archivos de tamaño cero con contexto directo de módulo siguen incluidos.
- La reconciliación por multiconjunto RC15, la identidad determinista RC14 y
  la verificación post-restore permanecen estrictas.

## Auditoría

`module_relation_reconciliation.json` y `plan_summary.json` incorporan:

- `files_xml_rows_total`;
- `files_module_context_candidates`;
- `files_matched_by_context`;
- `files_confirmed_by_inforef`;
- `files_inforef_only_excluded`;
- `files_non_module_context_excluded`;
- `files_unattributed_nonmodule_excluded`;
- `files_context_inforef_conflicts`;
- exclusiones regenerables, no resueltos, ambiguos y diferencia de
  multiconjunto.

Las exclusiones de otros contextos se registran como agregados y mediante una
muestra determinista limitada a 50 filas, sin contenido de archivos ni
secretos. No incrementan `files_unresolved`.

## Regresión

`tests/rc16-file-context-population.php` cubre H5P content directo, H5P
libraries indirectas, user/icon, course/section, course/overviewfiles,
contexto sin `inforef`, contexto confirmado, conflicto, file ID duplicado,
tamaño cero, diferencia real, idempotencia e inmutabilidad.

El fixture sintético RC14 conserva todas sus aserciones y únicamente añade
`contextid` a sus `module.xml`, evidencia estructural necesaria para representar
el contrato real corregido. Las regresiones RC14 y RC15 continúan ejecutándose
sin relajar sus resultados.
