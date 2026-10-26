# CAMBIOS — Consolidador 8.1.0-linux

8.1.0 deriva exclusivamente de 8.0.0-linux y añade una sola capacidad: un
mapa de categorías destino, declarado antes de escribir el destino.

## Problema

- El piloto se restauraba siempre en `pilot.target_category_id` de
  `assistant.json`, es decir, la «Categoría 1» general.
- El lote creaba una raíz por origen (`<ALIAS>-ROOT`) y reflejaba debajo la
  jerarquía de cada origen, sin forma de unir categorías de orígenes
  distintos. Por eso «Ingeniería» o «Ciencias Básicas / Ciencias basicas»
  quedaban duplicadas.

## Interruptor

El mapa participa solo si existe `config/category-map.json` o si el
asistente corre con `CONSOLIDATION_CATEGORY_MAP=required`. Es el mismo patrón
que `CONSOLIDATION_FUZZY_REVIEW`, y la plataforma web exporta ambas variables.
Sin ninguno de los dos, las etapas nuevas no existen y el plan, la
aplicación y la verificación son los de 8.0.0, byte a byte.

`compose.yaml` declara `CONSOLIDATION_CATEGORY_MAP` en el entorno de
`assistant-runtime`, junto a `CONSOLIDATION_FUZZY_REVIEW`. Sin esa
declaración, la variable que exporta el agente no llegaba al contenedor y la
etapa se omitía en silencio: así ocurrió en la primera prueba en AWS.
`tests/v8-810-env-propagation.py` exige que toda variable `CONSOLIDATION_*` que
leen los scripts llegue al asistente, ya sea por el entorno de compose, por el
`.env` que escribe el launcher o por un `-e`.

## Etapas nuevas

- **`05a-mapa-categorias`**: va antes de `05b-readiness` y no escribe en el
  destino. `v8-category-map.ps1 -Mode Prepare`:
  - lee las categorías actuales del destino (`v8-category-target.php`);
  - construye `exports/category-map/proposal.json` a partir de
    `exports/phase6/source-inventory-*.json`. La propuesta es determinista:
    un árbol unificado sin raíz por origen, los cursos de cada categoría
    (hasta 50 listados, más el total), las categorías existentes del destino
    y sugerencias de unión entre orígenes;
  - las sugerencias son de dos tipos: nombre igual tras normalizar
    (minúsculas, sin tildes ni puntuación, espacios colapsados) y similitud
    de edición de al menos 0,85;
  - sin `config/category-map.json` se detiene con `V8_CATEGORY_MAP_REQUIRED`;
    con un mapa no válido, con `V8_CATEGORY_MAP_INVALID` y los problemas.
- **`07b-categorias-piloto`**: va después de `07-verificar-usuarios` y antes
  de `08-plan-piloto`.
  - `v8-category-chain.php` crea o reutiliza, por marcador, la cadena de
    categorías hasta el nodo del piloto;
  - `v8-category-map.ps1` escribe su id en
    `config/phase5-pilot-package.json` (`target_category_id`,
    `category_map_sha256`, `category_map_node`);
  - las fases 5 no cambian: restauran, finalizan y verifican contra ese id.
    El piloto ya no cae en «Categoría 1».

## Contrato `config/category-map.json` (1.0)

- `proposal_sha256` vincula el mapa a la propuesta.
- `nodes`: cada nodo trae `key`, `name` y `parent` (`@top` o la clave de otro
  nodo), o bien `existing_category_id` para una categoría que ya existe en el
  destino. Una categoría existente conserva su nombre y su padre.
- `assignments`: cada categoría de origen va a un único nodo.
- `operator`, `decision_timestamp_utc` y `evidence_reference` son
  obligatorios.
- El mapa no trae marcadores. Cada nodo nuevo recibe
  `MAP-<sha256(ancla|ruta normalizada)[0:20]>`, así que los orígenes unidos
  comparten marcador y una nueva ejecución con la misma estructura reutiliza
  las categorías.

`exports/category-map/validation.json` sella el hash del mapa, el de la
propuesta y el de la configuración, los nodos resueltos y la cadena del
piloto.

La validación rechaza:

- ciclos;
- nombres vacíos o de más de 255 caracteres;
- categorías de origen sin mapear o asignadas dos veces;
- padres inexistentes;
- hermanos con el mismo nombre normalizado;
- una homónima del destino sin el marcador bajo el mismo padre;
- marcadores repetidos o renombrados en el destino;
- un mapa hecho sobre otra propuesta;
- la falta de los campos de auditoría.

Una vez que el destino recibió escrituras, la propuesta no se regenera.

## Lote con mapa

- `phase6-package-plan.ps1` copia la validación a
  `exports/phase6/category_map.json` y la pasa con `--categorymap`.
- `phase6-plan.php` genera una fila de `category_plan.csv` por nodo
  (`NODE-<clave>`), con las columnas extra `node_kind` y `member_keys`.
- Los cursos apuntan al nodo de su categoría de origen.
- Las categorías del piloto se reutilizan por marcador.
- `plan_summary.json` registra `category_mode=mapped` y `category_map_sha256`,
  y el mapa se sella entre los artefactos del plan.
- `phase6-apply-categories.php` acepta como padre la raíz de Moodle (0) y
  reutiliza por id las categorías existentes, sin modificarlas.
- `phase6-verify.php` exige que cada nodo del plan quede aplicado. Mantiene la
  comprobación de nombre, padre y marcador de cada categoría, y de la
  categoría de cada curso.

## Pruebas

`tests/v8-810-category-map.php` reproduce el laboratorio sintético. Cubre:

- normalización, similitud, sugerencias y propuesta determinista;
- el mapa unificado (Ingeniería y Ciencias Básicas unidas, Matemáticas y
  Estadística dentro, Humanidades y Educación propias, «Category 1» unida a
  la «Categoría 1» existente) y el piloto en Educación;
- todos los rechazos y una validación alterada;
- las filas de fase 6, incluida la reutilización de Educación creada por el
  piloto;
- las condiciones que dejan intacto el flujo 8.0.0.

No se modifica conciliación, restore, scheduler, workers, themes, identidades,
checkpoints, heartbeat ni notificaciones.
