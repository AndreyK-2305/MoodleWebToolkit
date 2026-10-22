# Etapa E7: intervenciones de la consolidación desde la web

Objetivo: que el administrador lleve la consolidación remota de principio a fin desde la
plataforma. No necesita SSH ni editar archivos en el servidor, salvo lo que depende de
terceros: DNS, el proxy TLS y el cliente OAuth de Google en Moodle.

La prueba real de la etapa E (2026-10-22) mostró dónde había que entrar al servidor. Este
documento recoge el diseño acordado con el usuario el mismo día.

## Lo que hoy exige el servidor

| Etapa del Consolidador                     | Qué pide                                               | Cómo se resuelve hoy                                       |
| ------------------------------------------ | ------------------------------------------------------ | ---------------------------------------------------------- |
| `03-plugins` sin plugins (rc12)            | Nada: es un defecto con cero plugins (pins y sello)    | Reconstruir `moodle-target` y ejecutar `-Mode Seal` a mano |
| `03-plugins` con plugin fuera del catálogo | Un pin (repositorio, commit, árbol) para el componente | Editar `config/plugin-compatibility-catalog.json`          |
| `04`/`05b` candidatos fuzzy                | Revisar posibles duplicados de identidad               | En modo automático rc12 los **omite sin detenerse**        |
| `05b-readiness` theme global               | `global_theme_selection`                               | Editar `config/theme-policy.json`                          |
| `05b-readiness` themes legacy              | Aceptar fuentes sin metadata avanzada de themes        | Editar `theme_inventory_legacy_accepted`                   |
| Preparar sobre un destino usado            | Un destino limpio                                      | Borrar a mano el runtime y sus volúmenes                   |

## Cortes

### E7a: continuar sin plugins

Cuando la etapa `03-plugins` se detiene y el lock del Consolidador no requiere ningún plugin
(`plugins` y `unknown_plugins` vacíos, sin necesidades de compatibilidad), la intervención
explica el defecto de rc12 y ofrece **«Continuar sin plugins»**.

La operación del agente `consolidator.plugins-none` corre como el usuario del perfil, bajo el
lock del asistente y antes de cualquier escritura:

1. Comprueba de nuevo que el lock no pide plugins.
2. Reconstruye `moodle-target` si los pins de la imagen difieren de los del runtime.
3. Revalida y sella el lock con los scripts del propio Consolidador.

Luego la Ejecución continúa con la siguiente corrida.

### E7b: destino limpio y limpieza confirmada

**Preparar destino** avisa que se requiere, preferiblemente, un destino limpio. Si el runtime
ya tiene datos de Moodle (volúmenes, `reports/`, `exports/`, `copias/` o una escritura del
Consolidador), el administrador elige:

- **Continuar sobre el destino existente**, con la advertencia del riesgo de colisiones si
  Caddy, el DNS y los servicios relacionados no están bien configurados.
- **Limpiar el destino antes de preparar.**

Ambas opciones exigen un administrador de la plataforma y escribir textualmente `CONFIRMAR`.

Implementación:

- **Hechos del destino.** `destination-check` reporta `target_volumes`, `assistant_started`,
  `copias_packages` y `server_profiles`. A partir de ellos la plataforma decide si el destino
  está en uso y avisa con `destination_in_use` y `shared_server`.
- **Operación.** La limpieza es parte de la misma operación de preparación
  (`consolidator.prepare` con `reset=1`), para que un solo seguimiento muestre la limpieza y el
  `preparar`. Corre como root, porque los contenedores dejan archivos de sus propios usuarios.
- **Qué hace la limpieza:**
    - detiene el proyecto Compose del Consolidador y borra sus volúmenes;
    - vacía el runtime, salvo el `.env`, y lo restaura desde el árbol verificado;
    - deja la configuración administrada vacía y el runtime a nombre del usuario del perfil;
    - imprime `DESTINATION_RESET_DONE` y ejecuta `preparar` como ese usuario.
- **Lo que no toca:** las copias del sitio ya respaldadas en S3 y las imágenes Docker
  construidas, que aceleran la reconstrucción.
- **Varios perfiles.** El nombre del proyecto Compose del Consolidador es fijo, así que hay un
  solo runtime por servidor. Si hay otro perfil, la limpieza se rechaza
  (`destination_shared_server`).
- **Después.** La plataforma olvida lo que sabía de `copias/` y de las transferencias. Al
  terminar, comprueba de nuevo el perfil.

### E7c: Consolidador rc13 (ajuste mínimo, aprobado por el usuario)

Con `CONSOLIDATION_FUZZY_REVIEW=required` en el entorno del asistente, cuando hay candidatos
el modo automático toma la opción `[P]` del modo interactivo:

1. Prepara `exports/identity_candidate_review.csv`.
2. Se detiene en `05b-readiness` con `V8_IDENTITY_REVIEW_REQUIRED`, antes de escribir usuarios.

La variable va en el entorno y no en `identity-policy.json`, porque el hash de esa política queda
ligado a la conciliación: cambiarla obligaría a repetirla. El agente la exporta al lanzar el
asistente, y `compose.yaml` la transmite al contenedor. Sin la variable, rc13 se comporta igual
que rc12.

Es una nueva distribución del catálogo: `8.0.0-linux-rc13`, con árbol `f4119912…` y 264
archivos. Sus cambios están en `CAMBIOS-8.0.0-RC13.md`.

### E7d: revisión de identidades en la web

La intervención `IDENTITY_REVIEW` muestra cada grupo candidato:

- las dos cuentas de origen, con nombre, correo y usuario;
- la puntuación y el motivo.

El administrador elige `MERGE` (con la cuenta canónica), `KEEP_SEPARATE` o `IGNORE`, con una
justificación. Los datos personales solo se muestran al administrador y no van a eventos ni a
la auditoría, salvo los identificadores.

Implementación:

1. **Lectura.** `identity-candidates` lee la revisión preparada por páginas, con campos
   cerrados.
2. **Respuestas.** La plataforma las valida y las guarda en la intervención como evidencia.
   Las envía en trozos base64url, porque el agente solo admite argumentos cortos de un
   alfabeto cerrado.
3. **Escritura.** `identity-review-submit` las escribe en
   `exports/identity_candidate_review.csv`. Conserva las columnas selladas y agrega operador,
   fecha y referencia de evidencia.
4. **Aplicación.** La operación `consolidator.identity-apply` ejecuta
   `v8-identity-review.ps1 -Mode Apply` en el contenedor del asistente. Si lo acepta, sigue la
   siguiente corrida. Si lo rechaza, la revisión se abre de nuevo.

### E7e: themes desde la web

La intervención de `05b-readiness` muestra los themes disponibles en el destino y la decisión
legacy:

- `theme-options` lista los themes instalados o validados en staging.
- La elección del administrador se escribe con `theme-policy-set` en `config/theme-policy.json`,
  con `selection_source=operator`. Si se aceptan fuentes legacy, se escribe también la lista de
  esas fuentes, como en la opción `[C]` del modo interactivo.

Después la Ejecución continúa.

### E7f: plugin fuera del catálogo

La intervención de plugins lista los componentes sin pin. Para cada uno, el administrador
indica el repositorio Git y el commit:

1. **Inspección.** La operación `consolidator.plugin-inspect` corre en el contenedor del
   asistente, mientras la Ejecución sigue esperando:
    - clona el commit;
    - lee `version.php` con las funciones del propio catálogo (componente, versión, release,
      requires y dependencias);
    - calcula la huella del árbol con `v8c_tree_sha256`;
    - deriva la ruta de Moodle 5.2 (`public/<tipo>/<nombre>`) y escribe una propuesta.
      Solo se admiten repositorios públicos de GitHub o Bitbucket, que es la regla del catálogo.
      Los submódulos no se inspeccionan desde la plataforma.
2. **Revisión.** La plataforma muestra la propuesta para revisarla.
3. **Promoción.** Con la confirmación, `consolidator.plugin-promote` la agrega al catálogo con
   el modo `promote` del Consolidador, que valida la entrada completa. Después se revalida la
   etapa de plugins: el resolver instala el plugin, reconstruye la imagen y lo valida en
   staging, y el administrador aprueba el inventario como siempre.

## Reapertura de intervenciones

Si continuar falla, la intervención resuelta se abre de nuevo con el motivo. Por ejemplo, si el
destino rechaza la respuesta o el destino no está listo. Antes, la Ejecución quedaba esperando
sin ninguna acción disponible.

## Agente 1.13.0

Operaciones y subcomandos nuevos:

- **Operaciones:** `consolidator.plugins-none`, `consolidator.identity-apply`,
  `consolidator.plugin-inspect` y `consolidator.plugin-promote`.
- **Subcomandos:** `identity-candidates`, `identity-review-submit`, `theme-options`,
  `theme-policy-set` y `plugin-proposal`.
- **Cambio en una operación existente:** `consolidator.prepare` admite `reset`.

## Prueba

Después de los cortes, la prueba E1–E6b se repite desde cero y **como usuario**, solo con la
interfaz web: crear los proyectos, recolectar, publicar, preparar el destino limpio,
consolidar e intervenir. Así se evalúan la experiencia, las advertencias y el manejo de
errores. Solo DNS, Caddy y OAuth se hacen fuera de la plataforma.
