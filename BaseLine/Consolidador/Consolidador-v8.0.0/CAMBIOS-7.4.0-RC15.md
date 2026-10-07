# Consolidador 7.4.0-linux-rc15

RC15 parte de RC14 y corrige de forma localizada la reconciliación de
relaciones de actividades en Fase 5. Continúa siendo una candidata técnica;
no se declara estable ni apta para benchmark real hasta completar la prueba
de Restore en Moodle 5.2.

## Corrección

- Elimina la correspondencia por posición y el fallback por nombre de
  actividad. La igualdad de conteos ya no se considera evidencia de identidad.
- Reconstruye `activity_key` exclusivamente desde `_source_module_id` obtenido
  de la estructura del MBZ.
- Para archivos, resuelve `file id -> inforef.xml -> source_module_id` y usa
  `contextid -> source_module_id` como segunda vía estructural.
- Detecta y bloquea IDs de archivo o contextos atribuidos a más de un módulo,
  y conflictos entre ambas evidencias.
- Compara inventario y candidatos como multiconjuntos de campos semánticos;
  nombres de archivo, componentes, áreas o usuarios repetidos son válidos.
- Usa el mismo predicado de archivos de módulo y conserva únicamente la
  exclusión aprobada de derivados regenerables `assignfeedback_editpdf`.
- Comprueba por SHA-256 que el MBZ crudo permanece bit a bit intacto durante
  la normalización.

## Auditoría e integridad

`module_relation_reconciliation.json` registra el resultado y las diferencias
semánticas sin contenido de archivos ni secretos. El artefacto queda incluido
en `artifacts_sha256` y se verifica antes de Apply/Restore. El resumen añade:

- `files_inventory_rows`;
- `files_backup_candidates`;
- `files_matched_by_file_id`;
- `files_matched_by_context`;
- `files_unresolved`;
- `files_ambiguous`;
- `files_multiset_difference`.

Cualquier relación no resuelta, ambigua o semánticamente distinta bloquea el
plan. No se modificó `phase5-verify.php` ni se agregó una excepción genérica
para archivos.

## Regresión

`tests/rc15-relation-reconciliation.php` cubre arreglos reordenados, archivos
repetidos legítimos, resolución por ID y por contexto, ambigüedades, diferencia
real de población, filtro compartido, idempotencia e inmutabilidad. La prueba
RC14 continúa validando label, resource, BigBlueButton, assign, forum, quiz,
completion y el escenario de 545 módulos con 545 identificadores efectivos
únicos.

Este entorno de construcción no dispone de Docker, por lo que la prueba de
Restore real en Moodle 5.2 sigue pendiente y debe realizarse antes del
benchmark institucional.
