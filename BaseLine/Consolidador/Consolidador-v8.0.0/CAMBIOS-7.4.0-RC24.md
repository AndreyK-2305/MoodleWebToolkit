# Consolidador 7.4.0-linux-rc24

RC24 parte del ZIP oficial y verificado de RC23. Es una corrección localizada de
la Fase 13 posterior a la auditoría exhaustiva del lote de Posgrados; no cambia
los contratos aprobados de identidades, OAuth2, roles, plugins, Fases 1–12 ni
la política de verificación final. Sigue siendo una candidata técnica hasta
completar una ejecución integral limpia.

## Correcciones de Fase 13

### Relaciones históricas y módulos homónimos

Las relaciones `activity_completions`, `assignment_submissions`,
`assignment_grades`, `forum_discussions` y `forum_posts` dejan de exigir que un
`modname|name` histórico identifique un único módulo. Para estas cinco
relaciones, RC24 compara primero el multiconjunto semántico del inventario
fuente con las filas estructurales presentes en el MBZ. Solo si la igualdad es
exacta reconstruye `activity_key` desde el `source_module_id` estructural y el
`effective_idnumber` ya sellado.

Una diferencia de contenido continúa bloqueando con
`MODULE_RELATION_STRUCTURAL_MISMATCH`; no se adivina un módulo por nombre ni se
pierden filas agregadas de actividades homónimas. `quiz_attempts` conserva el
contrato capability-aware de RC23 y su resolución de alias, porque la auditoría
demostró que el inventario histórico puede contener intentos que no deben ser
sustituidos globalmente por el MBZ.

### Residuos legacy `qtype=random`

RC24 conserva el bloqueo de referencias activas a `questionid` o
`questionbankentryid`, la validación estricta del filtro y la cardinalidad por
`quiz.xml`. Si una semántica random no tiene referencia activa directa ni
`question_set_reference` correspondiente, se clasifica explícitamente como
residuo huérfano y puede retirarse de `questions.xml`; la auditoría registra
filas físicas, entradas y semánticas huérfanas.

Una referencia a la misma categoría con `includesubcategories` contradictorio
sigue bloqueando mediante `set_reference_semantic_conflict`. No se aplica una
relajación global de los filtros legacy.

### Archivos compartidos de `mod_customcert`

La validación de `inforef.xml` ya no presupone que todo `file_id` pertenece a
un único módulo. Se acepta exclusivamente el contrato demostrado por el plugin
para `mod_customcert/image`, `itemid=0`, almacenado en contexto de curso y
referenciado por varias actividades `customcert`. Esos archivos compartidos no
se atribuyen artificialmente a un módulo y quedan fuera de `relations.files`,
cuya población continúa definida por contexto de módulo.

Cualquier otra asignación de un mismo `file_id` a varios módulos continúa
bloqueando con `MODULE_FILE_ID_AMBIGUOUS`.

### Integridad global de payloads

Además de la verificación estricta de los archivos comparables por módulo,
RC24 inspecciona todos los registros internos de `files.xml` antes del restore:
valida metadatos, presencia y tamaño físico. Directorios y referencias externas
siguen su contrato de Moodle.

Un payload ausente de `user/icon` se registra como warning auditable y no
bloquea la consolidación, porque la pérdida queda limitada al avatar del
usuario y no altera contenido académico. Cualquier otro payload interno
faltante o con tamaño distinto continúa bloqueando antes de restaurar el curso.
La verificación SHA-1 estricta se mantiene para la población transportable de
módulos sin convertir el preflight global en un segundo recorrido costoso de
todo el file pool.

### Categorías de preguntas

Se conserva el bloqueo fail-closed de una categoría especial `top` con
`parent=0` que contiene entradas directamente. El diagnóstico se precisa como
`QUESTION_TOP_CATEGORY_DIRECT_ENTRIES_UNRESTORABLE`, en lugar de describirla
incorrectamente como categoría ordinaria. Las categorías `top` vacías normales
no son bloqueadas. Una categoría ordinaria con `parent=0` usa
`QUESTION_CATEGORY_PARENT_ZERO_INVALID`.

Un `assign.xml` inválido u otra evidencia XML estructuralmente ilegible continúa
siendo bloqueante; RC24 no intenta reparar XML corrupto ni introduce
excepciones por curso.

## Evidencia que fundamenta la corrección

La auditoría previa a RC24 comprobó en Posgrados:

- `activity_completions`: 206.426 filas fuente positivas = 206.426 filas MBZ,
  con coincidencia exacta en los 254 cursos del lote;
- `forum_discussions`: 3.870 = 3.870 y `forum_posts`: 12.528 = 12.528;
- `assignment_submissions`: coincidencia exacta bajo `latest=1` y
  `status<>new` en todos los cursos estructuralmente legibles;
- `assignment_grades`: coincidencia exacta bajo `grade>=0` en los 253 cursos
  con XML de assign legible;
- 23 bloqueos random correspondían a residuos sin referencia activa;
- la única duplicidad de `file_id` entre módulos en 255 MBZ era
  `mod_customcert/image`, dos IDs compartidos por tres certificados en contexto
  de curso, coherente con el código oficial del plugin incluido;
- de 211.533 payloads únicos comprobados, no hubo diferencias de tamaño ni
  SHA-1; los 48 registros sin blob eran tres variantes `user/icon` de un único
  usuario repetidas en 16 cursos.

## Regresión

Se añade `tests/rc24-phase13-contracts.php` para fijar los contratos de
proyección estructural, archivos compartidos de Custom certificate, categorías
`top` y warning de avatar. `tests/rc22-legacy-random-normalization.php` conserva
sus bloqueos de referencia activa, filtro inválido y cardinalidad, y agrega el
caso huérfano demostrado. `tests/rc24-runtime-tags.py` actualiza las etiquetas
de runtime y la migración de `.env` incorpora RC23 → RC24.

No se incorpora ninguna excepción específica para Pregrado, Posgrados, hashes
de curso o IDs de usuario. Los defectos genuinos de los paquetes fuente
continúan fallando de forma cerrada.
