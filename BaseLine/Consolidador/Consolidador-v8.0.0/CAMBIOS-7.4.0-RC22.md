# Consolidador 7.4.0-linux-rc22

RC22 parte exactamente de RC21 original. La corrección se limita a la
normalización previa al restore de Fase 6, etapa 13, y no incorpora excepciones
locales de laboratorio para Pregrado.

## Corrección

Moodle 5.2 rechaza backups modernos que aún conservan preguntas residuales
`qtype=random`, incluso cuando el cuestionario ya contiene los
`question_set_reference` equivalentes. RC22 inspecciona la única extracción ya
existente y retira exclusivamente entradas residuales demostrablemente
obsoletas antes de crear el `restore_controller`.

La operación es fail-closed. Solo se normaliza cuando la entrada contiene
exclusivamente preguntas random, no existe una referencia estructural activa,
el filtro JSON moderno identifica la misma categoría y la misma semántica de
`includesubcategories`, y la cardinalidad es exacta. Cualquier ambigüedad
bloquea el curso con `LEGACY_RANDOM_BLOCKED`.

No se modifica el MBZ fuente, no se crea un segundo archivo normalizado y no se
recalcula su sello. `questions.xml` se vuelve a parsear y validar; los
`question_set_reference` se comparan antes/después y deben permanecer idénticos.

## Auditoría

La auditoría `phase=6-course-single-extraction-normalization-audit` incorpora:

- hashes de `questions.xml` antes y después;
- cantidades detectadas, retiradas y verificadas;
- IDs ordenados de preguntas, entradas y categorías;
- estado `normalized` o `not_required`.

Se conservan `single_extraction=true` y
`normalized_archive_created=false`.

## Regresión

`tests/rc22-legacy-random-normalization.php` cubre el caso 10/10 observado y
los bloqueos por entrada mixta, referencia activa, ausencia de referencia
moderna, cardinalidad, JSON inválido, categoría distinta y semántica de
subcategorías incompatible. También verifica que usuarios, roles, referencias
modernas y jerarquía de preguntas permanezcan intactos.

La suite completa RC1–RC21 continúa siendo obligatoria mediante
`tests/verify-package.sh`. RC22 sigue siendo una candidata técnica y no una
versión estable.
