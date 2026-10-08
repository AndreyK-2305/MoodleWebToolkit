# Consolidador 7.4.0-linux-rc18

RC18 parte exactamente de RC17 y corrige exclusivamente la semántica
comparable de `course_completions` durante la verificación del curso piloto en
Fase 5. Continúa siendo una candidata técnica; no se declara estable ni apta
para benchmark hasta completar la ejecución real.

## Corrección

- Los inventarios fuente y destino conservan todas las filas crudas de
  `course_completions` para diagnóstico.
- La vista comparable incluye únicamente filas cuyo campo `completed` es el
  booleano `true`, equivalentes a una finalización con `timecompleted > 0`.
- Las filas `completed=false`, originadas por seguimiento con
  `timecompleted=NULL` o no positivo, no bloquean la relación comparable.
- El filtro se aplica antes del mapeo de usuarios, canonicalización,
  ordenación, serialización e igualdad de `relations_course_completions`.
- Una finalización efectiva ausente o convertida en seguimiento continúa
  bloqueando estrictamente.

## Diagnóstico

El resumen de verificación expone:

- `course_completions_raw_expected` y `course_completions_raw_actual`;
- `course_completions_effective_expected` y
  `course_completions_effective_actual`;
- `course_completions_tracking_ignored_expected` y
  `course_completions_tracking_ignored_actual`.

El escenario real `raw=54/53`, `effective=0/0` y `tracking=54/53` queda
clasificado como equivalente, sin ocultar pérdidas de `completed=true`.

## Alcance preservado

No se modifican restore, MBZ, reconstrucción de módulos o archivos, Fase 6,
OAuth2, identidades, roles, plugins, qbank, checkpoints ni ninguna relación
académica diferente de `course_completions`. Se conservan íntegramente las
protecciones RC14, RC15, RC16 y RC17.

## Regresión

`tests/rc18-course-completions.php` cubre los ocho escenarios obligatorios,
las métricas del benchmark `54/53` y la exigencia estricta del booleano
`completed=true`. La suite completa RC1–RC17 continúa ejecutándose.

