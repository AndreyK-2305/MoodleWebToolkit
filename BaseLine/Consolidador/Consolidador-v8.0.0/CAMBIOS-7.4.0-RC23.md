# Consolidador 7.4.0-linux-rc23

RC23 parte del ZIP oficial y limpio de RC22. Sigue siendo una candidata técnica:
no se declara estable ni aprobada para producción hasta completar el benchmark
controlado posterior.

## Correcciones localizadas

- Fase 6 agrupa residuos `qtype=random` por `stamp`, conserva la revisión de
  todos los IDs físicos y valida la cardinalidad lógica por cada `quiz.xml`.
  Acepta únicamente los contratos moderno y legacy documentados para
  `filtercondition`; toda semántica desconocida continúa bloqueando.
- La única extracción usada por restore recibe los `effective_idnumber` reales
  en cada `module.xml`. El inventario efectivo usa esas mismas identidades.
- Las relaciones históricas pueden resolver un alias unívoco `modname|name`.
  Los aliases ambiguos requieren evidencia estructural suficiente o bloquean.
  Las poblaciones académicas fuente no se sustituyen globalmente por el MBZ.
- Solo `relations.files` usa `files.xml` como verdad de transporte en Fase 6.
  Se verifican metadata, existencia, tamaño y SHA-1 de cada payload comparable.
  `assignfeedback_editpdf/tmp_jpg_to_pdf` se trata como derivado regenerable.
- Los archivos técnicos de módulos qbank generados por Moodle 5.2 se excluyen
  únicamente cuando el propio módulo qbank ya fue clasificado como técnico.
- El diagnóstico de `quiz_attempts` deriva su firma de los campos realmente
  presentes en la fuente. Los inventarios históricos comparan usuario,
  actividad, estado y nota como multiconjunto; los campos fuertes presentes de
  manera consistente continúan siendo estrictos. Ausente, `null` y cero no se
  confunden.

## Auditoría y regresión

La auditoría de normalización registra filas random físicas, stamps lógicos,
cardinalidad por quiz, formato de filtro, identidades de módulo, aliases,
fallbacks y evidencia de transporte de archivos. Los reportes de intentos
registran el contrato, los campos de firma y las inconsistencias de esquema.

Se agregan:

- `tests/rc23-random-normalization.php`;
- `tests/rc23-phase6-effective-inventory.php`;
- `tests/rc23-quiz-attempt-contract.php`;
- `tests/rc23-runtime-tags.py`.

La suite completa RC1–RC22 continúa siendo obligatoria. No se incorpora la
excepción temporal de laboratorio para Pregrado, no se modifica AWS y no se
relajan los contratos aprobados de Fase 5.
