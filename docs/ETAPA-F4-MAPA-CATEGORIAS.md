# Etapa F4 — Mapa de categorías antes de consolidar

Estado: **diseño aprobado** el 2026-10-25. Decisiones: la propuesta es un árbol unificado, sin raíz por origen; las categorías mapeadas van en la raíz de Moodle; se entrega en dos PR.

## Problema

- El piloto (fase 5) se restaura siempre en `assistant.json → pilot.target_category_id`
  (= 1, «Categoría 1»). `phase5-finalize.php` y `phase5-verify.php` lo exigen.
- El lote (fase 6) crea una raíz por origen (`<ALIAS>-ROOT`) bajo
  `target_parent_category_id` y recrea debajo la jerarquía de cada origen.
  Reutiliza una categoría del destino solo por su marcador (`idnumber`).
- No hay forma de unir categorías de orígenes distintos. «Ingeniería» y
  «Ciencias Básicas / Ciencias basicas» quedan duplicadas.
  `category_plan.csv` es solo una propuesta de lectura.

## Objetivo

Antes de que el Consolidador escriba la primera categoría, la web muestra el
árbol destino propuesto con los cursos de cada nodo. Una persona no técnica
lo ajusta (unir, renombrar, mover/arrastrar, elegir padre, comparar antes y
después) y lo aprueba. El Consolidador consume un archivo de mapeo
declarativo con hash. Sin mapeo, el comportamiento es idéntico a 8.0.0.

## 1. Contrato: `config/category-map.json` (schema `1.0`)

```json
{
    "schema_version": "1.0",
    "proposal_sha256": "<sha256 de exports/category-map/proposal.json>",
    "config_sha256": "<sha256 de la configuración, como el resto de fases>",
    "nodes": [
        { "key": "E-1", "existing_category_id": 1 },
        { "key": "N-1", "name": "Ingeniería", "parent": "@top" },
        { "key": "N-2", "name": "Ciencias Básicas", "parent": "@top" },
        { "key": "N-3", "name": "Matemáticas", "parent": "N-2" },
        { "key": "N-4", "name": "Estadística", "parent": "N-2" }
    ],
    "assignments": [
        { "source": "pregrado-…", "source_category_id": 12, "node": "N-1" },
        { "source": "posgrado-…", "source_category_id": 7, "node": "N-1" }
    ],
    "operator": "…",
    "decision_timestamp_utc": "…Z",
    "evidence_reference": "…"
}
```

- `parent`: `@top` es la raíz de Moodle (padre 0). Cualquier otro valor es la
  `key` de otro nodo.
- Un nodo con `existing_category_id` es una categoría que ya existe en el
  destino, como «Categoría 1». Conserva su nombre y su padre, puede recibir
  categorías de origen (unir) y puede tener hijos nuevos.
- La web nunca envía marcadores. El Consolidador los deriva de forma
  determinista:
    - todo nodo nuevo recibe `MAP-<sha256(ancla|ruta normalizada)[0:20]>`;
    - el marcador no depende de las claves de la web: los miembros unidos lo
      comparten, fase 6 reutiliza la categoría y una nueva ejecución con la
      misma estructura también.
- Validación (Consolidador y, antes, la plataforma, con las mismas reglas):
    - el hash de la propuesta coincide;
    - cada categoría de cada inventario está asignada exactamente una vez
      (sin categorías sin mapear);
    - no hay ciclos;
    - ningún nombre está vacío ni supera 255 caracteres;
    - no hay hermanos con el mismo nombre normalizado;
    - los padres existen;
    - no hay una homónima sin marcador en el destino bajo el mismo padre;
    - todos los marcadores tienen como mucho 100 caracteres.
- El piloto se ubica en el nodo asignado a la categoría de origen de su curso.

## 2. Consolidador 8.1.0-linux (misma disciplina que F1)

Se mantiene la carpeta `Consolidador-v8.0.0` (como con las RC). `VERSION.txt`
pasa a `8.1.0-linux` y se añaden `CAMBIOS-8.1.0.md`, pruebas y `FILES.sha256`.
Se actualizan la huella de BaseLine, el catálogo, el seed y la clave de
distribución.

- **Interruptor**: `CONSOLIDATION_CATEGORY_MAP=required`, con el mismo patrón
  que `CONSOLIDATION_FUZZY_REVIEW`. Sin él, la etapa nueva no existe y todo
  queda como en 8.0.0.
- **Etapa nueva `05a-mapa-categorias`** (solo con la variable o con
  `config/category-map.json`), entre `05-plan-usuarios` y
  `05b-readiness`. Es la última etapa sin escrituras en el destino.
    - `v8-category-map.php --mode=proposal` lee los inventarios
      `exports/phase6/source-inventory-*.json` (existen desde la etapa 01) y las
      categorías actuales del destino (solo lectura). Escribe
      `exports/category-map/proposal.json`: el árbol 8.0.0 por defecto, cursos
      por categoría y sugerencias de unión.
    - Sin `config/category-map.json` válido, la etapa se detiene con
      `V8_CATEGORY_MAP_REQUIRED`. Si el mapeo existe pero no valida, se detiene
      con `V8_CATEGORY_MAP_INVALID` y una lista de problemas.
    - Con un mapeo válido, `--mode=validate` escribe
      `exports/category-map/validation.json`: hash del mapeo, marcadores
      derivados y nodo del piloto. `Test-CategoryMap` exige que ese hash
      coincida con el archivo vigente.
- **Piloto: etapa nueva `07b-categorias-piloto`**, antes de `08-plan-piloto`:
    - `v8-category-chain.php` crea o reutiliza por marcador la cadena de
      categorías hasta el nodo del piloto;
    - escribe su id en `config/phase5-pilot-package.json`, un archivo mutable;
    - las fases 5 no cambian: restauran, finalizan y verifican contra ese id.
    - Esto corrige el bug de «Categoría 1».
- **Lote (11/13/14)**:
    - con mapeo, `phase6-plan.php` genera las filas de `category_plan.csv` por
      nodo destino (y no por categoría de origen). Añade las columnas
      `node_key` y `member_keys`, y no crea raíces `<ALIAS>-ROOT`;
    - `target_category_key` de cada curso apunta al nodo de su categoría;
    - los nodos que el piloto ya creó se reutilizan por marcador, con la lógica
      actual;
    - `phase6-apply-categories.php` no cambia: crea o reutiliza por fila;
    - `phase6-verify.php` acepta el mapeo: comprueba nombre, padre y marcador
      por nodo, y que el curso está en el nodo de su categoría;
    - `plan_summary.json` registra `category_map_sha256`.
- **Pruebas nuevas**:
    - `tests/v8-810-category-map.php`: contrato, ciclos, duplicados, sin mapear,
      marcadores y caso del laboratorio sintético;
    - una prueba que confirma que, sin la variable, plan y pasos son iguales a
      8.0.0;
    - `verify-package.sh` las incluye.

## 3. Plataforma

- **Agente 1.15.0**:
    - `category-map-proposal <destino> <offset>` devuelve la propuesta por
      páginas de hasta 14 000 bytes, para que cada respuesta quepa en la salida
      de SSM. La primera página trae la cabecera (hash de la propuesta,
      orígenes, piloto y problemas de la última validación). Siguen las
      categorías existentes del destino, cada categoría de origen con hasta 20
      cursos listados (y el total) y las sugerencias. Todos los campos son
      cerrados.
    - `category-map-submit <destino> <envío> <parte> <total> <chunks…>` recibe
      trozos base64url, como `identity-review-submit`. El mapa viaja en partes
      de 40 trozos para no superar el tamaño de una llamada. La última parte
      los une desde un archivo (nunca desde argv), comprueba la estructura y el
      hash de la propuesta, escribe `config/category-map.json` de forma atómica
      y devuelve su hash. Lo rechaza si ya existe `destination-write.lock`.
    - `consolidator-run` exporta `CONSOLIDATION_CATEGORY_MAP=required`.
- **Intervención `CATEGORY_MAP`**:
    - se abre en `05a-mapa-categorias` con `V8_CATEGORY_MAP_REQUIRED` o
      `V8_CATEGORY_MAP_INVALID`;
    - incluye la propuesta y los problemas; decisión `APPLY_ANSWERS`, y
      `CONFIRM_COMPLETED` como respaldo;
    - la plataforma vuelve a validar el mapeo (clase PHP pura
      `CategoryMapValidator`) antes de llamar al agente;
    - si falla al continuar, `reopen()` muestra el motivo.
- **Auditoría**:
    - el editor lleva un registro de operaciones (unir, renombrar, mover, crear,
      separar);
    - al enviar, se registra `CATEGORY_MAP_SUBMITTED` con ese registro, el hash
      del mapeo y los totales; además queda la auditoría estándar de la
      resolución del conflicto;
    - si el Consolidador rechaza el mapa, la intervención nueva arranca desde la
      respuesta anterior de la misma ejecución, no desde cero.
- **Editor React** (`resources/js/components/category-map-editor.tsx` y
  lógica pura en `resources/js/lib/category-map.ts`, con Vitest):
    - árbol destino accesible según el patrón WAI-ARIA _tree_: `role="tree"`,
      `treeitem`, roving tabindex, flechas, Inicio/Fin, Intro para renombrar y
      `aria-live` para anunciar cada cambio;
    - acciones con botones y menú: «Unir con…», «Mover a…» (selector de
      padre), «Renombrar», «Nueva categoría», «Separar», «Deshacer»;
    - arrastrar y soltar con `@dnd-kit/core` **6.3.1**, versión exacta. Es un
      atajo: con el teclado todo se puede hacer sin arrastrar;
    - sugerencias: igual nombre normalizado (minúsculas, sin tildes ni
      puntuación, espacios colapsados), marcadas como «Coinciden». Una similitud
      de al menos 0,85 (Levenshtein) se marca como «Parecidas». Solo se sugieren
      entre orígenes distintos y nunca se aplican solas;
    - cada nodo muestra los cursos (shortname y nombre) y de qué origen viene
      cada uno;
    - vista «Antes / Después»: los árboles de origen a la izquierda y el destino
      a la derecha. Al seleccionar un nodo se resaltan sus miembros;
    - validación en vivo con el mismo conjunto de reglas. El botón «Aprobar mapa»
      queda desactivado y la lista de problemas enlaza a cada nodo afectado.

## 4. Laboratorio sintético (validación posterior, de punta a punta)

Resultado esperado (`LAB-ORIGENES-SINTETICOS.md`, «Categorías esperadas»):

- una sola Ingeniería con ING-101 ×2, ING-201 e ING-501;
- una sola «Ciencias Básicas», con Matemáticas y Estadística dentro;
- Humanidades y Educación propias.

El piloto (POS-EDU-501) queda en Educación. `phase6-verify` aprueba el
resultado.

Pasos de la prueba en AWS:

1. Actualizar el agente del destino a 1.15.0 («Instalar/Actualizar agente»).
2. Restablecer el destino y lanzar la consolidación remota desde la web.
3. En `05a-mapa-categorias`:
    - aplicar las sugerencias de Ingeniería y Ciencias Básicas;
    - unir las dos «Category 1» con la «Categoría 1» existente;
    - comprobar que el piloto se anuncia en Educación;
    - aprobar el mapa.
4. Confirmar que `07b-categorias-piloto` crea Educación y que el piloto se
   restaura allí.
5. Al cerrar, revisar en Moodle el árbol anterior y que cada curso esté en su
   categoría.

## 5. Entregas

- PR A (borrador): Consolidador 8.1.0, con contrato, scripts, pruebas, huellas
  y catálogo.
- PR B (borrador, apilado sobre A): agente 1.15.0, intervención, validador,
  editor y pruebas.
