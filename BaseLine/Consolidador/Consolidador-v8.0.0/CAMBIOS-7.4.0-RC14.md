# Consolidador 7.4.0-linux-rc14

RC14 parte de RC13 y corrige exclusivamente la identidad de actividades de
Fase 5 cuando Moodle permite nombres repetidos y `course_modules.idnumber`
está vacío. Continúa siendo una candidata técnica, no una versión estable.

## Corrección

- Preserva todo `idnumber` institucional no vacío y continúa bloqueando un
  duplicado real.
- Genera para cada módulo sin identificador `MIG-P5-MOD-` seguido de 32
  caracteres hexadecimales en mayúscula. El hash SHA-256 usa `source_id`, ID
  del curso origen, `source_module_id`, tipo e instancia; no usa tiempo,
  orden de ejecución ni IDs del Moodle destino.
- El identificador resultante mide 43 caracteres y se valida contra el límite
  compatible de 100 caracteres de Moodle.
- Reescribe únicamente `module.xml` dentro de la copia normalizada del MBZ.
  La copia cruda se comprueba bit a bit contra el backup sellado del paquete.
- `source_course_inventory.json` conserva `source_module_id` y registra
  `original_idnumber`, `generated_idnumber`, `effective_idnumber` e
  `idnumber_origin`. `module_key` usa el identificador efectivo.
- Las consultas de entregas, notas y foros resuelven la actividad por la
  instancia real del `course_module`, nunca por su nombre.
- Para paquetes heredados, las relaciones ambiguas se reconstruyen desde los
  XML de assign, forum, quiz, completion y files del MBZ. Si la evidencia no
  permite atribuirlas sin ambigüedad, el plan bloquea en lugar de adivinar.

## Auditoría e integridad

`exports/phase5/module_idnumber_normalization.csv` registra módulo, tipo,
instancia, nombre, identificador original, efectivo y motivo. El artefacto
forma parte de `artifacts_sha256` y `p5_load_plan()` lo verifica antes de
Apply/Restore. El resumen incorpora:

- `module_idnumbers_preserved`;
- `module_idnumbers_generated`;
- `module_idnumber_collisions`;
- `module_keys_unique`.

## Regresión

`tests/rc14-module-identities.php` cubre nombres repetidos en label, resource,
BigBlueButton, assign y forum, además de quiz, idnumber original, submissions,
grades, discussions, posts, completion y files. También prueba idempotencia,
backup original intacto, duplicado institucional y el escenario de 545
módulos con 545 llaves efectivas.

La suite no relaja qbank, assignfeedback_editpdf, comparación post-restore,
sellos, checkpoints ni contratos heredados de RC13.
