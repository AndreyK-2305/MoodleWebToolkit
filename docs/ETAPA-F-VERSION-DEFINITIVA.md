# Etapa F: hacia la versión definitiva

Tras la Etapa E7, el recorrido Recolector → Consolidador V8 funciona de punta a punta en AWS y desde
la web. Esta etapa cierra lo que falta para la versión definitiva, en el orden acordado con el
usuario el 2026-10-24.

1. **Consolidador definitivo y pendientes de experiencia.**
    - Consolidador 8.0.0 final (F1, abajo).
    - La consolidación remota no pide instancias de origen.
    - Actualizar el agente desde la web con un proyecto en curso.
2. **Prueba representativa en dos pasos.**
    - Primero, un laboratorio sintético más rico, cargado automáticamente en cada Moodle de origen:
      unas 3 categorías con 2 cursos cada una, unos 10 usuarios y contenido genérico. Ejercita la
      consolidación, la revisión fuzzy de identidades y la gestión de categorías antes de consolidar.
    - Si pasa bien, el usuario restaura copias institucionales significativas en los EC2 de origen
      y se prueba la herramienta web con ellas. Ese proceso dura unas 3 a 5 horas, así que va al
      final.
    - La prueba de resiliencia en AWS va en el mismo ciclo: reiniciar la plataforma, cortar la
      conexión y detener un worker durante una consolidación larga, y comprobar que retoma sin
      duplicar.
3. **Runbook de operación.** Preparación de servidores, DNS y Caddy, OAuth, recolección,
   consolidación y publicación, junto con la respuesta a fallos, el rollback, los costos y la
   capacidad. Se escribe cuando el flujo ya no cambie.
4. **Integrador.** Sigue bloqueado como incompatible. El usuario decide si entra en la versión
   definitiva.

## F1: Consolidador 8.0.0-linux final

La distribución pasa de `8.0.0-linux-rc13` a `8.0.0-linux`:

- árbol `717b1fefca41fca97db22a1e2f964866f95e406ab259648d09c4fb5510817bc3`;
- manifiesto `47456297f907308f8b5602da488f0403918fa03b25a2c8746d89433263b566ca`;
- 266 archivos.

Corrige en el propio Consolidador el defecto de la consolidación sin plugins, que en E7 se rodeaba
desde la plataforma con «Continuar sin plugins».

- **Causa:** con un lock vacío, `v8-plugin-resolver.ps1 -Mode Prepare` reescribía
  `approved-plugins.json` y solo reconstruía `moodle-target` si había instalado algún plugin. La
  imagen conservaba el manifiesto anterior y la revalidación fallaba con «pins inconsistentes». El
  asistente archivaba entonces las necesidades y el lock nunca se sellaba.
- **Corrección:** `Prepare` reconstruye la imagen cuando el manifiesto de `moodle-target` difiere
  del archivo, también al reutilizar un lock. Con cero plugins, la etapa se sella y se aprueba sola.
  El detalle está en `CAMBIOS-8.0.0-FINAL.md`, con la prueba `tests/v8-800-zero-plugin-rebuild.ps1`.
- **Plataforma:** el catálogo, `CheckRemoteDestination::DISTRIBUTION_KEY` y
  `consolidator:enable-remote` apuntan a `moodle-consolidador-8.0.0-linux-tree`. «Continuar sin
  plugins» y la operación `consolidator.plugins-none` del agente se conservan como respaldo, por si
  la etapa vuelve a detenerse.
- **BaseLine:** pasa a 426 archivos, con huella
  `4613bd0a9a3338a0d6f3824955f9f30e712af3d1f6f15cbd43e2ae2b93c8cae6`.

**Al desplegarla en un destino existente**, el administrador vuelve a ejecutar el helper del perfil
con `--force --tree 717b1fef…`. El panel muestra el comando. Después se usa «Preparar destino» para
limpiarlo con la nueva versión. El cambio se confirma de punta a punta en la prueba representativa.

## F2: prueba con el laboratorio sintético (2026-10-24)

El usuario recolectó los dos orígenes Moodle 4.5 sembrados con `docs/LAB-ORIGENES-SINTETICOS.md` y los
consolidó con el Consolidador 8.0.0 en un Moodle 5.2.1 limpio, solo desde la web. La recolección y la
consolidación llegaron a COMPLETED. La etapa de plugins pasó sola con 8.0.0 y las fusiones de
identidad quedaron bien aplicadas en el destino.

La prioridad del usuario es que alguien sin formación técnica entienda el proceso, sobre todo la
revisión fuzzy de identidades. Estos son los hallazgos y lo que cambió.

### Revisión de identidades

| Hallazgo                                                                                                               | Corrección                                                                                                                                                                                                                             |
| ---------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Al cambiar la cuenta canónica, el correo y el usuario seguían siendo los de la otra cuenta.                            | El correo y el usuario siguen a la cuenta elegida, salvo que el administrador los haya editado (`chooseCanonical`).                                                                                                                    |
| El usuario canónico por defecto era el correo (`laura.gomez@uni-lab.example`).                                         | Se usa el usuario que la cuenta ya tiene. El agente 1.14.0 lo lee de `exports/phase3/identity-*.json`; con un agente anterior se usa la parte del correo antes de la @.                                                                |
| El selector mostraba ids como `posgrado:19`.                                                                           | Muestra nombre, correo y origen. Los ids quedan en «Detalles técnicos».                                                                                                                                                                |
| Jerga: `CAND-…`, «confianza HIGH», «nombre 1.0000 · correo 0.6429», «Google missing», «Evidencia: google_sub=missing». | Cada caso explica por qué se detectó («tienen el mismo nombre», «sus correos se parecen») y compara las dos cuentas en una tabla que resalta lo distinto: nombre, correo, usuario, documento (últimos 4 dígitos) y vínculo con Google. |
| MERGE, KEEP_SEPARATE e IGNORE no se distinguían. «Ignorar coincidencia» dejó dos cuentas con el mismo correo.          | Las opciones son «Es la misma persona: unir», «Son personas distintas» y «Dejar como está, sin unir», cada una con su efecto. Si dos cuentas con el mismo correo van a quedar separadas, la página lo advierte.                        |
| No había progreso ni motivo del botón deshabilitado.                                                                   | Muestra «3 de 8 decididos» y la lista de lo que falta en cada caso.                                                                                                                                                                    |
| Muchos casos evidentes se decidían uno por uno.                                                                        | Si hay más de un caso de coincidencia alta con el mismo correo y documento, se pueden unir todos de una vez. El motivo sigue siendo obligatorio y cada caso se puede revisar después.                                                  |
| Al enviar, el formulario desaparecía y seguía el texto «Respóndala en «Acciones controladas»».                         | La confirmación «Respuestas enviadas», con el resumen (unidas, separadas, sin cambios), sigue visible mientras el Consolidador está en esa etapa. La tarjeta del Consolidador dice que la respuesta quedó registrada.                  |

El agente pasa a **1.14.0**: `identity-candidates` agrega `accounts` por cuenta (`key`, nombre, correo,
usuario y los últimos 4 caracteres del documento). La plataforma solo acepta claves `origen:id`.
Antes de la próxima prueba hay que actualizar el agente del destino con «Instalar agente» o
«Actualizar agente».

### Mensajes del Consolidador

`OperatorText` traduce los códigos más frecuentes a frases claras, por ejemplo
`V8_IDENTITY_REVIEW_REQUIRED`, `V8_THEME_SELECTION_REQUIRED`, `V8_LEGACY_THEME_DECISION_REQUIRED`,
`PLUGIN_INTERVENTION_PENDING` y `PLUGIN_TECHNICAL_VALIDATION_FAILED`.

- Un código desconocido se quita. Si lo que sigue se lee como frase, se conserva; si no, se muestra una
  frase según su familia (identidades, themes o plugins).
- El texto original queda en «Detalles técnicos».
- El evento «El Consolidador espera una intervención…» del seguimiento usa el mismo texto claro.

### Preflight y asistente

- Con un error en el preflight, el asistente no pasa a «Confirmación». La lista de errores aparece
  arriba, con lo que hay que corregir, y «Revisar confirmación» queda deshabilitado, con el motivo al lado. El paso «Confirmación» del indicador de pasos también queda cerrado hasta que un nuevo preflight pase.
- La confirmación enumera los errores que bloquean y deshabilita «Confirmar configuración».
- Ya no se muestran ids internos como `collector.configuration`. Los resultados se leen «Correcto»,
  «Advertencia» y «Error».
- En una recolección con servidores reales, el paso 3 ya no muestra los escenarios simulados antes de
  guardar la tarjeta remota. Si falta guardarla, lo indica.
- «Instancia revisada por el administrador» ya no viene marcada.
- El destino usa `moodle-destino.test` y Moodle 5.2 por defecto.

### Catálogo

- Volver a ejecutar `php artisan migrate --seed` dejaba el Recolector 7.4.2 en `enabled=false` sin
  avisar. `ToolCatalogSeeder` ahora conserva `enabled` si el árbol no cambió. Un árbol distinto con la
  misma versión vuelve a quedar cerrado.
- El preflight distingue una herramienta deshabilitada en el catálogo («Recolector 7.4.2 habilitado en
  la plataforma») de una que no está desplegada. Lo mismo vale para el Consolidador.

### Panel remoto

- «Instalar agente» prueba la conexión enseguida y muestra el resultado.
- Una conexión cuyo único error es el agente muestra «Falta instalar el agente» o «Falta actualizar el
  agente», no «Con errores».
- Los perfiles de origen detectados llenan las filas que no tienen uno. Una fila nueva toma el siguiente
  servidor que no se esté usando.
- Cuando termina una preparación, un despliegue o una transferencia, el panel vuelve a leer el estado
  una vez más. La tarjeta de consolidación elige el perfil de destino cuando aparece.
- El respaldo en S3 se quedó en «Respaldando…» hasta recargar. Leyendo el código no se encontró la
  causa: el seguimiento ya consultaba cada 5 s mientras el respaldo corría. Ahora también recarga las
  propiedades de la página cada 20 s mientras dure. Hay que confirmarlo en la próxima prueba, mirando
  la consola y la red si se repite.
- Los radios del diálogo de preparación tienen `value` explícito, así que ya no exponen «on».

### Otros textos

- El botón de la contraseña del login dice «Mostrar contraseña».
- Las páginas de perfil, seguridad y apariencia están en español.
- El inicio ya no habla de ejecuciones simuladas ni de «herramientas reales fuera de este corte».
- La revisión de una recolección con varios orígenes ya no dice «Etapa sin determinar».

### Fuera de alcance: requieren una decisión del usuario

1. El Consolidador 8.0.0 restauró el curso piloto (POS-EDU-501) en «Categoría 1» y no en su categoría
   «Educación».
2. No hay un paso web para mapear categorías antes de consolidar. Las categorías con el mismo nombre en
   ambos orígenes quedan duplicadas.

### Verificación

- En local pasan Pint, PHPStan, `npx vp check --fix`, `tsc` (aplicación y e2e), Vitest
  (`identity-review.test.ts`) y PHPUnit `OperatorTextTest`.
- Las pruebas Feature (seed, preflight deshabilitado, instalación con prueba, candidatos con cuentas) y
  Playwright (preflight con error) corren en CI.
- La interfaz de la revisión de identidades no se probó en un navegador contra un destino real. Se
  confirma en la próxima prueba en AWS, con el agente 1.14.0.

## F3: segunda prueba de las intervenciones (2026-10-24)

Una segunda prueba solo desde la web, con el mismo laboratorio, llegó a COMPLETED y confirmó la mayoría
de las correcciones de F2. Quedaron dos fallas.

### La confirmación tapaba la intervención siguiente

Después de responder la revisión de identidades, el encabezado ya mostraba WAITING_USER_ACTION por la
elección de apariencia, pero el panel seguía con «Respuestas enviadas». El formulario nuevo solo
aparecía al recargar la página.

Causas:

1. Las dos intervenciones paran en `05b-readiness`. La confirmación se mostraba mientras la etapa fuera
   la misma y no hubiera un conflicto abierto.
2. La Execution pasa a WAITING_USER_ACTION y emite su evento antes de leer la intervención en el
   destino. El conflicto nuevo se creaba después, sin evento propio. El aviso en tiempo real llegaba
   antes que el conflicto, y nada volvía a avisar cuando el conflicto existía.
3. El seguimiento actualiza el estado del componente, pero no los props de Inertia (`history.state`).

Correcciones:

- El backend crea el conflicto y el evento `consolidator.intervention_opened` en la misma transacción
  (`RemoteConsolidationInterventions::create`). El evento llega por tiempo real y por el sondeo junto
  con el conflicto abierto.
- `resources/js/lib/intervention-state.ts` decide qué muestra el panel. La confirmación pertenece al
  conflicto respondido (id y versión). Desaparece cuando hay un conflicto más nuevo o cuando el
  Consolidador vuelve a parar después de su `conflict.resolved`.
- Si la Execution espera y la intervención todavía no llegó, el panel muestra «El Consolidador prepara
  la siguiente decisión» y consulta cada 3 s hasta que llega el formulario.
- Cuando el seguimiento trae un conjunto de conflictos que los props no tienen, la página relee sus
  props (`router.reload` asíncrono). Así una restauración o un remontaje muestran el mismo formulario.
- Cada formulario se identifica por el id y la versión del conflicto.

### «Unir de una vez los casos evidentes» no apareció

Un solo caso cumplía la regla: Laura Gómez, coincidencia alta, mismo correo y documentos terminados en 1006. Los datos llegaban bien (`confidence` = `HIGH`, `identifier_match` = `'true'`). La acción pedía
al menos dos casos evidentes (`evident.length > 1`). Ahora basta uno, con el texto en singular («Unir el
caso evidente»). La lógica está en `evidentCases` y `mergeEvident` (`identity-review.ts`).

### Verificación

- En local pasan Pint, PHPStan, `npx vp check --fix`, `tsc` y Vitest (`intervention-state.test.ts` e
  `identity-review.test.ts`).
- La prueba Feature extendida en `RemoteConsolidationExecutionTest` responde la revisión de identidades.
  Después, la ejecución para en la elección de apariencia, y el sondeo de eventos trae el conflicto
  abierto y su evento sin recargar. Corre en CI porque no hay Docker local.
- No se agregó una prueba Playwright. La suite e2e no tiene fixtures de consolidación remota. La
  próxima prueba en AWS confirma el flujo en el navegador.
