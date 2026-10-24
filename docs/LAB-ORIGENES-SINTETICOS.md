# Laboratorio sintético representativo para los dos orígenes

Antes de restaurar copias institucionales reales (un proceso de 3 a 5 horas), los
dos orígenes EC2 del laboratorio reciben un contenido sintético pequeño pero
representativo. Así se prueba la consolidación de punta a punta con casos de
identidad y de categorías conocidos de antemano.

| Origen   | Perfil de origen | Variante del seeder | Contenido                                       |
| -------- | ---------------- | ------------------- | ----------------------------------------------- |
| Origen 1 | `synthetic-lab`  | `pregrado`          | 4 categorías (1 anidada), 6 cursos, 10 usuarios |
| Origen 2 | `posgrado`       | `posgrado`          | 4 categorías (1 anidada), 6 cursos, 10 usuarios |

Los archivos están en `docker/collector-lab-ec2/lab-seed/`:

- `seed-lab.php`: script CLI de Moodle 4.5.10. Usa las API de Moodle y sus
  generadores de datos (`testing_data_generator`), los mismos que usa
  `tool_generator`.
- `send-seed-via-ssm.sh`: envía el script por SSM a una instancia y lo ejecuta en
  el contenedor `php` del origen, como `www-data`.

## Qué contiene cada curso

Solo actividades del núcleo de Moodle; la recolección y la consolidación no
necesitan ningún plugin del catálogo.

- 3 secciones con nombre, además de la general.
- Etiqueta de bienvenida, página, recurso con un archivo `.txt` pequeño, foro,
  tarea con texto en línea y cuestionario con 2 preguntas (verdadero/falso y
  respuesta corta) en el banco de preguntas del curso.
- 1 o 2 profesores (`editingteacher`) y 5 estudiantes (`student`) con
  matrícula manual.
- Actividad de usuarios, para que el respaldo lleve datos de usuario: una
  discusión del profesor con 2 respuestas de estudiantes, una entrega de la
  tarea y un intento de cuestionario terminado y calificado.

Las cuentas son `manual` con una contraseña aleatoria que nunca se imprime;
nadie inicia sesión con ellas. Los correos usan el dominio reservado
`uni-lab.example`.

## Cómo ejecutarlo

Requisitos: AWS CLI con el perfil `personal`, la instancia encendida y
aprovisionada con `docker/collector-lab-ec2/provision.sh` (`/opt/moodle-lab`).
Encender o apagar instancias del laboratorio se acuerda antes con el usuario.

```bash
docker/collector-lab-ec2/lab-seed/send-seed-via-ssm.sh <id-origen-1> pregrado
docker/collector-lab-ec2/lab-seed/send-seed-via-ssm.sh <id-origen-2> posgrado
```

El tercer argumento elige el modo (por defecto `seed`):

| Modo     | Efecto                                                                            |
| -------- | --------------------------------------------------------------------------------- |
| `seed`   | Crea lo que falta. Si ya está completo, imprime `ALREADY_SEEDED` y no cambia nada |
| `reset`  | Borra lo que creó el seeder y lo vuelve a crear                                   |
| `remove` | Solo borra lo que creó el seeder                                                  |
| `status` | Solo lee: imprime el manifiesto y los conteos                                     |

Una ejecución completa imprime `COURSE LABSEED-...` por curso y termina con una
línea `SEEDED {...}`. Por origen se esperan 4 categorías, 6 cursos, 10 usuarios,
36 módulos, 18 mensajes de foro, 6 entregas y 6 intentos terminados.

Garantías del script:

- **Solo en el laboratorio.** Exige el marcador
  `/opt/moodle-lab/.moodle-toolkit-synthetic-lab.json` y el `fixture_id` que el
  fixture C0 escribió en la base de datos. Una copia institucional restaurada no
  tiene ambos, así que el script termina con `not_a_laboratory_site` sin escribir.
- **Idempotente.** Guarda un manifiesto en `config_plugins` (`mwt_labseed`).
  Un curso a medio crear por una ejecución interrumpida se rehace desde cero.
  Cambiar la variante o la versión del contenido exige `reset`.
- **Borrado acotado.** `reset` y `remove` solo tocan cursos y categorías con
  `idnumber` `LABSEED-...` y usuarios cuya descripción empieza por `[LAB-SEED]`.
  Nunca adopta un username existente que no haya creado. El fixture C0
  (`SYNTHETIC-1`, `SYNTHETIC-2`, `synthetic1..3`) no se toca.
- Moodle conserva los usuarios borrados como `deleted=1`; el Consolidador los
  excluye de la conciliación.

## Casos de identidad esperados

Consolidador V8 concilia primero de forma determinística: dos cuentas `manual`
con el mismo username son la misma identidad. Después compara por bloques
(`v8-blocked-2`) nombre, parte local del correo y documento (`idnumber`) entre
identidades distintas y produce `exports/identity_candidates.csv`. El operador
decide cada candidato con `MERGE`, `KEEP_SEPARATE` o `IGNORE`.

Las similitudes de la tabla salen de ejecutar el algoritmo de
`v8-preparation.php` sobre estos usuarios.

| Caso | Pregrado                               | Posgrado                                 | Qué produce V8                                                   | Decisión esperada  |
| ---- | -------------------------------------- | ---------------------------------------- | ---------------------------------------------------------------- | ------------------ |
| ID-A | `ana.garcia`, Ana García Torres        | `ana.garcia`, mismo correo y documento   | Fusión automática por username; no es candidato                  | MERGE (automático) |
| ID-B | `laura.gomez`, Laura Gómez Silva       | `lgomez`, mismo correo y documento       | Candidato HIGH: nombre 1.00, correo 1.00, documento igual        | MERGE              |
| ID-C | `carlos.ramirez`, Carlos Ramírez Díaz  | `cramirez`, otro correo, mismo documento | Candidato HIGH: nombre 1.00, correo 0.64, documento igual        | MERGE              |
| ID-D | `sofia.martinez`, Sofía Martínez Rojas | `smartines`, Sofia Martines Rojas        | Candidato HIGH: nombre 0.95, correo 0.93                         | MERGE              |
| ID-E | `juan.perez`, Juan Pérez Gómez         | `jperez`, mismo nombre, CC-2004          | Candidato MEDIUM: nombre 1.00, correo 0.60, documentos distintos | KEEP_SEPARATE      |
| ID-F | `prueba.pregrado`, Usuario Prueba      | `prueba.posgrado`, Usuario Prueba        | Candidato HIGH: nombre 1.00, correo 0.87                         | IGNORE             |

- ID-D es una persona con la tilde perdida y una errata (`z`→`s`). La
  normalización de V8 quita las tildes; la errata la absorbe la similitud.
- ID-E es otra persona: el documento distinto es la evidencia para separarla.
- ID-F es una cuenta de prueba en cada origen; el candidato se descarta sin
  definir identidad canónica.
- Para cada `MERGE` de cuentas manuales use `oauth_policy=canonical_account`;
  `none` las dejaría como `pending_relink`.
- ID-B también aparece en la auditoría de correos compartidos
  (`shared_source_email`) hasta que se decide su `MERGE`.
- Los 4 usuarios exclusivos de cada origen no generan candidatos.

Resultado esperado para las 20 cuentas sembradas: 16 identidades canónicas
(1 fusión automática y 3 fusiones aprobadas). Si un origen conserva el fixture C0,
`fixture-admin` se fusiona por username y `synthetic1..3` (autenticación
`nologin`) aparecen como candidatos HIGH entre orígenes: la decisión esperada es
`IGNORE`.

## Categorías esperadas

| Pregrado                       | Posgrado                       | Qué ejercita                                 |
| ------------------------------ | ------------------------------ | -------------------------------------------- |
| Ingeniería (ING-101, ING-201)  | Ingeniería (ING-101, ING-501)  | Mismo nombre en ambos orígenes               |
| Ciencias Básicas › Matemáticas | Ciencias basicas › Estadística | Variante de mayúsculas y tildes, y anidación |
| Humanidades (HUM-101, HUM-102) | Educación (EDU-501, EDU-502)   | Categorías exclusivas de un origen           |

Matemáticas tiene MAT-101 y MAT-102; Estadística tiene EST-501 y EST-502. La
categoría padre Ciencias Básicas no tiene cursos propios.

Sin mapeo previo, V8 crea una categoría raíz por origen (marcador
`<ALIAS>-ROOT`) y recrea debajo la jerarquía de ese origen, con marcadores
`<ALIAS>-LABSEED-PRE-...` o `<ALIAS>-LABSEED-POS-...`. Resultado: dos
«Ingeniería» y dos «Ciencias…» separadas, cada una bajo su origen, y la
anidación se conserva. Para un campus unificado, el mapeo previo a la
consolidación debe:

- unir las dos Ingeniería en una sola;
- unir «Ciencias Básicas» y «Ciencias basicas» con el nombre con tilde,
  conservando Matemáticas y Estadística como subcategorías;
- dejar Humanidades y Educación como categorías propias.

Las dos ING-101 son cursos distintos con el mismo shortname. El shortname de
destino se deriva del alias del origen (`source_alias_code`), así que ambos
conviven sin colisión.

## Límites

- Es contenido pequeño: no prueba volumen, archivos grandes ni plugins.
- Las cuentas son `manual`; no hay vínculos OAuth ni `google_sub` (el fixture C0
  ya cubre un emisor OAuth sintético en el Origen 1).
- La siguiente etapa restaura copias institucionales reales en lugar de este
  contenido.
