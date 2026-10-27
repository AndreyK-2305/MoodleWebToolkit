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

## F5: personalización del theme global (2026-10-26)

En el laboratorio, el theme elegido quedaba con su apariencia de fábrica. El Recolector 7.4.2 ya
inventaría el perfil de configuración de cada theme, pero el Consolidador solo lo validaba y lo
sellaba como evidencia. El usuario pidió copiar al menos el SCSS para que el Moodle consolidado
conserve la identidad visual de la institución.

### Consolidador 8.2.0-linux

- `config/theme-policy.json` admite `global_theme_profile_source`. Vacío, el flujo es idéntico a
  8.1.0. Con el `source_id` de un paquete, se copia el perfil que ese origen tenía para el theme
  global.
- El plan de themes sella `global_theme_profile`: los ajustes que se aplican (solo
  `config_value`) y los que nunca, con su motivo. Nunca se aplican los secretos redactados, las
  referencias a archivos (logos, fondos), los valores nulos ni la versión del plugin.
- `scripts/v8-theme-profile.php` los escribe en la etapa `09b-themes-piloto` con `set_config`, los
  verifica, recompila el SCSS y deja `exports/theme-profile-application.json`.
- Los valores quedan en `config_plugins`, así que el administrador puede cambiarlos después desde
  Moodle. Los themes no elegidos siguen instalados.
- Detalle en `CAMBIOS-8.2.0.md`.

### Plataforma y agente 1.16.0

- `theme-options` devuelve, por origen y por theme, cuántos ajustes se copiarían, cuántos no y si
  hay SCSS. Solo viajan los conteos; los valores no salen del destino.
- `theme-policy-set` acepta un cuarto argumento con el origen elegido (`-` = ninguno).
- La intervención «Elegir el theme» añade «Personalización del theme». Por defecto se elige el
  primer origen que personalizó el theme elegido, y se puede dejar la apariencia de fábrica. La
  plataforma solo acepta un origen que traiga ajustes para ese theme.

### Verificación

- Pruebas: `tests/v8-820-theme-profile.php` (Consolidador) y las pruebas Feature de la intervención
  y del agente.
- Por decisión del usuario, la prueba de punta a punta queda para la prueba con las copias
  institucionales: elegir un origen, revisar en Moodle que el SCSS y los colores quedaron
  aplicados y subir a mano los logos.
- Antes de esa prueba hay que actualizar el agente del destino a 1.16.0 y re-apuntar el perfil al
  árbol de 8.2.0.

## F6: dos modos de proyecto (2026-10-26)

El personal no capacitado no entendía cómo unir una recolección con una consolidación: la tarjeta
de la consolidación ofrecía una lista plana de paquetes de cualquier proyecto y cada paquete se
traía a mano a `copias/`. El usuario aprobó rediseñarlo en dos modos.

### Solo Recolección y Consolidación

- El asistente de proyectos nuevos ofrece solo Recolectar y Consolidar. `INTEGRATE` sigue en el
  enum y los proyectos que ya lo usan funcionan igual; el backend rechaza crear uno nuevo o
  cambiar otro proyecto a Integrar (`ProjectType::creatable()`).

### «¿De dónde salen los orígenes?»

- **De un proyecto de recolección completado.** Se elige una recolección COMPLETED que el usuario
  puede ver; sus paquetes (uno por origen, VALID, AVAILABLE, sin revocar, en S3) quedan
  preseleccionados con origen, fecha y tamaño. La revisión guarda `source_project_uuid` y el
  backend exige que cada paquete pertenezca a esa recolección. El esquema ya admite un solo
  paquete por origen y proyecto; si hubiera varios, se toma el más reciente y se indica cuántos
  anteriores existen.
- **Recolectar ahora.** La tarjeta pide los orígenes (nombre, ID de instancia SSM, región, perfil
  de origen y nombre del paquete) junto con el destino. Se crea un proyecto COLLECT vinculado
  («<consolidación> · Recolección»), con las mismas personas asignadas, sus servidores, sus
  conexiones SSM y su configuración remota. Se ejecuta con su propia página y su propia
  ejecución: instalar el agente, desplegar el Recolector, comprobar perfiles, preflight,
  confirmar e iniciar. La consolidación muestra «Paso 1: recolección (n/N orígenes)» y qué espera.
- Cuando una ejecución llega a COMPLETED se emite `ExecutionCompleted` (después del commit). El
  listener `PickUpLinkedCollectionPackages` guarda los paquetes más recientes de la recolección
  como revisión de la consolidación, con el destino, el perfil y los workers elegidos al crearla.
  Es idempotente; si falla, el motivo queda en `projects.source_link` y en la página.
- La lista plana de paquetes queda como «Avanzado: elegir paquetes sueltos de varias
  recolecciones». Es el único modo que mezcla paquetes de varias recolecciones, por ejemplo un
  origen recolectado de nuevo en otro proyecto.
- El enlace vive en la consolidación (`projects.source_project_id`, `projects.source_link`), no en
  la recolección: un proyecto COMPLETED es de solo lectura.

### Paquetes a `copias/` sin pasos manuales

- `RemoteConsolidationCopias` trae a `copias/` exactamente los paquetes configurados, uno a la
  vez, reutilizando `RemoteDestinationPackages::fetch`/`observe` y su verificación. Arranca al
  guardar la configuración, al ejecutar el preflight y al tomar los paquetes de la recolección
  vinculada. Cuando una transferencia termina, la observación inicia la siguiente.
- Una transferencia fallida no se reintenta sola: el preflight siguiente la reintenta y explica
  el motivo. Nunca se retira nada de `copias/`: un ZIP ajeno lo sigue señalando el preflight.
- El panel manual «Traer desde S3» se mantiene, sin cambios, para casos especiales.
- La consolidación no puede iniciar hasta que el preflight ve `copias/` completo. El wizard dice
  qué espera: la recolección vinculada o la transferencia en curso.

### Verificación

- `tests/Feature/Remote/ConsolidationSourcesTest.php`: restricción de INTEGRATE, validación del
  origen desde una recolección, creación de la recolección vinculada, toma automática al
  completarse y transferencias a `copias/` una a la vez.
- `resources/js/lib/consolidation-sources.test.ts`: modo inicial, preselección, nombres de
  paquete y validación del formulario «Recolectar ahora».

### Asistente con servidores reales

Con el agente remoto, Recolectar y Consolidar siguen un asistente por pasos; el modo simulado e
INTEGRATE conservan el de antes.

- El stepper encabeza la página y cada paso muestra su estado real (completo, o lo que falta para
  seguir). Un paso se abre cuando los anteriores están completos. Las tarjetas remotas
  (`remote-access-panel`, `remote-collection-form`, `remote-consolidation-form`) son el contenido
  de los pasos; se mantienen todas sus acciones, incluido «Traer desde S3».
- Consolidar: 1 Datos básicos (con «Nombre de referencia del servidor de destino», opcional; vacío
  usa el nombre del proyecto), 2 Orígenes, 3 Destino, 4 Preflight, 5 Confirmación. Recolectar:
  1 Datos básicos, 2 Orígenes (etiqueta, conexión, Recolector y perfil de cada origen), 3 Preflight,
  4 Confirmación.
- Ya no hay paso «Instancias»: con servidores reales no se piden host, URL, versión de Moodle ni
  «revisada por el administrador». Solo los usaba `SimulatedPreflight`; la conexión es el ID de
  instancia SSM y la región, y URL y versiones salen de «Comprobar perfil». El backend registra
  solo la etiqueta de cada servidor (`ProjectWizard::saveRemoteServers`).
- Orígenes y destino se guardan por separado: `PUT consolidation/sources` deja la selección en
  `source_link` (modo `SELECTION`) hasta que el paso Destino completa la configuración, y
  «Recolectar ahora» ya no exige un destino preparado. Si la recolección vinculada termina antes,
  sus paquetes esperan a que se guarde el destino.
- `resources/js/lib/remote-wizard.ts` (con su prueba) calcula qué paso está completo y qué
  bloquea el siguiente; `ProjectWizardTest` y `ConsolidationSourcesTest` cubren el registro por
  etiqueta, la etiqueta por defecto y el guardado en dos pasos.

### Proyectos como historial

La página Proyectos ya no tiene su propio formulario de creación, que repetía el paso 1 del
asistente. Es el historial de los proyectos visibles (nombre, operación, estado, última actividad
y paso) con un botón «Nuevo proyecto»; sin proyectos muestra una explicación breve y el mismo
botón.

- «Nuevo proyecto» abre `/projects/create`, el paso 1 «Datos básicos» con los mismos campos
  (nombre, operación, descripción y, con servidores reales en Consolidar, el nombre de referencia
  del destino). Al guardarlo se crea el proyecto y el asistente sigue en su página, en el paso 2.
- `POST /projects` mantiene la autorización y la restricción de INTEGRATE, y guarda el paso 1 en
  la misma transacción (`ProjectWizard::saveBasics`): una consolidación remota registra su
  destino al crearse.
- `tests/Feature/Projects/ProjectCreateTest.php` cubre la página, la autorización, la creación y
  el destino; los E2E crean los proyectos desde «Nuevo proyecto».

## F7: notificaciones por correo (2026-10-26)

El Consolidador CLI avisaba por SMTP con la configuración del `.env` de cada destino. La plataforma
web no avisaba de nada, y las corridas de 3 a 5 horas se detienen en intervenciones que esperan a
una persona. El usuario decidió que el SMTP se configura una sola vez en la plataforma y que es la
plataforma quien envía los correos. Así las credenciales SMTP nunca viajan a los EC2.

### Configuración global (Administración → Notificaciones)

- Solo para administradores. Campos: servidor, puerto, cifrado (TLS, STARTTLS o ninguno),
  usuario, contraseña, dirección y nombre del remitente, interruptor de envío e intervalo opcional
  del resumen de avance (5 a 1440 minutos; apagado por defecto).
- Se guarda en `notification_settings`. La contraseña usa un cast `encrypted`, nunca se devuelve
  al navegador (la página solo muestra «configurada») y solo se puede conservar, reemplazar o
  borrar.
- Guardar exige confirmar la contraseña (`action.confirmed`) y queda en la auditoría
  (`NOTIFICATION_SETTINGS_UPDATED`) con los nombres de los campos que cambiaron, nunca con los
  valores.
- «Enviar correo de prueba» usa la configuración guardada. Puede ir a la dirección del propio
  administrador (por defecto), a una dirección que se escribe en el momento o a todos los
  destinatarios adicionales, con un correo por dirección para que nadie vea a los demás. Si falla,
  explica el error SMTP en español (conexión, credenciales, TLS, remitente) sin repetir la
  contraseña. La auditoría (`NOTIFICATION_TEST_EMAIL`) guarda el resultado, el tipo de destino y
  cuántos se enviaron, sin las direcciones.
- El mailer se arma al enviar desde esta configuración (`PlatformMailer`). Solo cuando no hay
  ninguna guardada se usan los `MAIL_*` del `.env`. Si hay configuración pero el envío está
  apagado, no sale ningún correo.

### Eventos que notifican

Se enganchan en `ExecutionEventRecorder`, el punto donde ya se registran todos los eventos. El
evento notificable escribe su fila en `execution_notifications` en el mismo commit, con una clave
estable única por ejecución:

| Grupo                   | Evento                                                                          | Clave               |
| ----------------------- | ------------------------------------------------------------------------------- | ------------------- |
| Intervenciones          | `consolidator.intervention_opened` (con su título)                              | clave del conflicto |
| Finalizaciones y fallos | `collector.package_registered` (por origen)                                     | paquete fuente      |
|                         | `collector.failed`, `consolidator.failed`, `tool.failed`, `execution.abandoned` | `outcome.failed`    |
|                         | `consolidator.closed` (REVIEW)                                                  | `outcome.review`    |
|                         | `execution.completed` de una consolidación                                      | `outcome.completed` |
| Respaldos               | `consolidator.backup_registered`, `consolidator.backup_failed`                  | digest u operación  |
| Resumen de avance       | cada N minutos de una ejecución en curso                                        | número de intervalo |

- `notifications:dispatch`, programado cada minuto, abre los resúmenes de avance y despacha cada
  fila una vez. Escribe una fila por destinatario en `execution_notification_deliveries` y envía
  la notificación encolada `ExecutionNotice` después del commit. Un reintento, una observación
  repetida o un fallo cerrado dos veces no producen un segundo correo. Una intervención reabierta
  tiene clave nueva y se avisa otra vez.
- Una fila que lleva más de 12 horas sin despacharse se cierra sin enviar, porque ya no es noticia.
- El correo lleva el nombre del proyecto, el evento, una línea en español redactada por la
  plataforma (no el mensaje técnico del evento) y el enlace a la página de la ejecución. No lleva
  datos personales de Moodle, secretos ni rutas de servidor.
- La cola es la `default`, que ya atiende el worker. La notificación admite 3 intentos (60 s y
  300 s) por si el SMTP no está disponible un momento.

### Preferencias de cada usuario (Configuración → Notificaciones)

- Recibir correos sí o no, y los grupos: intervenciones, finalizaciones y fallos, respaldos y
  resumen de avance. Por defecto están activos todos menos el resumen de avance.
- Destinatarios: los usuarios activos que pueden abrir el proyecto (los administradores y los
  usuarios asignados) y que optaron por ese grupo. Los administradores no se asignan a proyectos y
  ven todos, así que reciben los avisos de todos los proyectos salvo que los desactiven.

### Destinatarios adicionales (Administración → Notificaciones)

El usuario pidió que el correo se pueda dirigir a varias personas desde la misma página. Por
ejemplo, el administrador designa a un trabajador que debe intervenir y que quizá no tiene cuenta
en la plataforma.

- Lista de hasta 20 direcciones en `notification_recipients`. Cada una elige los mismos grupos que
  las preferencias de un usuario. Una dirección nueva empieza con intervenciones y finalizaciones y
  fallos activos.
- Las direcciones se guardan en minúsculas y no se pueden repetir. La validación rechaza la misma
  dirección escrita dos veces aunque cambien las mayúsculas.
- Reciben los avisos de todos los proyectos, además de los usuarios que los eligieron. El correo
  les explica que un administrador los designó, en lugar de remitir a Configuración.
- Idempotencia por dirección: `execution_notification_deliveries` guarda también el correo, en
  minúsculas y único por notificación. Los usuarios se despachan primero, así que una dirección
  adicional que coincide con el correo de un usuario no recibe el mismo aviso dos veces.
- Guardar la lista exige confirmar la contraseña. La auditoría (`NOTIFICATION_RECIPIENTS_UPDATED`)
  guarda solo cuántas hay, cuántas se agregaron, quitaron o cambiaron, nunca las direcciones.

### Verificación

- Pruebas Feature: `tests/Feature/Notifications/NotificationSettingsTest.php` (acceso, validación,
  cifrado, contraseña nunca en las respuestas, auditoría sin valores, correo de prueba con
  `Mail::fake` a cada tipo de destino, error SMTP real contra un puerto cerrado, validación,
  deduplicación y auditoría por conteo de los destinatarios adicionales) y
  `ExecutionNotificationsTest.php` (un correo por evento y por miembro, eventos que no avisan,
  idempotencia ante repeticiones, preferencias, destinatarios adicionales por grupo sin duplicar
  el correo de un usuario, envío apagado o vencido, resumen de avance y contenido del correo).
- Pendiente: probar con un SMTP real desde AWS durante una consolidación.

## F8: cuentas AWS y almacenamiento global (2026-10-27)

Hasta F7 la identidad AWS de la plataforma salía de la cadena por defecto del host (en el
laboratorio, el `~/.aws` montado con `AWS_PROFILE`) y el bucket de paquetes estaba fijo en el `.env`.
Otra institución que despliegue la plataforma tendría que editar archivos para conectar su propia
infraestructura. El usuario decidió que la identidad y el bucket se configuran en la web, como el
SMTP. El bucket es uno solo para toda la plataforma, no uno por proyecto. Los permisos IAM los crea
la institución.

### Administración → AWS

- Solo para administradores, junto a «Notificaciones». Guardar, eliminar y verificar exigen
  confirmar la contraseña (`action.confirmed`).
- **Credenciales** (`aws_accounts`): varias cuentas con nombre, una de ellas predeterminada. La
  primera queda como predeterminada y marcar otra desmarca la anterior (índice único parcial en
  PostgreSQL). Hay tres modos:
    - **Rol del entorno**: la cadena por defecto del SDK (rol de EC2/ECS o perfil montado). El
      laboratorio sigue funcionando así.
    - **Claves de acceso**: ID `AKIA…` y secreto con cast `encrypted`. Nunca vuelven al navegador:
      la página muestra solo los cuatro últimos caracteres del ID. Se conservan o se reemplazan.
    - **Asumir un rol** (recomendado): ARN del rol, External ID opcional (cifrado, nunca devuelto)
      y nombre de sesión. Se asume con STS sobre la identidad del entorno; las credenciales
      temporales se memorizan por cuenta, versión y región.
- Al cambiar de modo se borran los secretos del modo anterior. No se puede eliminar una cuenta que
  elige una conexión remota o el almacenamiento.
- **Verificar** una cuenta ejecuta `sts:GetCallerIdentity` y `ssm:DescribeInstanceInformation`
  (instancias en línea, hasta 10 páginas) en la región elegida. Explica en español quién es la
  plataforma, cuántas instancias ve o qué permiso falta, sin repetir secretos.
- **Almacenamiento** (`aws_storage_settings`, una fila): bucket, región y cuenta (vacía = la
  predeterminada). **Verificar** ejecuta HeadBucket y luego escribe, lee (HeadObject) y borra un
  objeto pequeño bajo `mwt/_pruebas/`. El objeto se borra aunque la lectura falle. El resultado
  dice qué paso falló: bucket inexistente, otra región o permiso faltante.
- La auditoría (`AWS_ACCOUNT_CREATED/UPDATED/DELETED/VERIFIED`, `AWS_STORAGE_UPDATED/VERIFIED`)
  guarda nombres de campos, el modo y qué pasó con cada secreto (`REPLACED`, `KEPT`, `CLEARED`).
  Nunca guarda claves, External ID, ARN, nombre de la cuenta ni bucket.
- La página trae las políticas mínimas, generadas con el bucket guardado: identidad de la
  plataforma, rol de los orígenes, rol del destino y relación de confianza con External ID. El
  principal de la relación de confianza sale de la última verificación de una cuenta «Rol del
  entorno».

### Cableado

- `SsmClientFactory` y `S3ClientFactory` resuelven la identidad con `AwsCredentials`. SSM usa la
  cuenta que elige la conexión, si no la predeterminada y si no la cadena del entorno. S3 usa la
  cuenta del almacenamiento, si no la predeterminada y si no la del entorno. Una conexión que nombra
  una cuenta eliminada falla con `aws_credentials_unavailable`. No toma otra identidad en silencio.
- La conexión SSM de «Conexiones remotas» tiene un selector opcional «Cuenta AWS». Se guarda como
  `aws_account` (UUID) en la configuración `remote-connection.v1`, se valida contra
  `aws_accounts` y llega al transporte en `RemoteTarget::$awsAccount`.
- `RemoteStorageConfiguration` superpone el bucket guardado a `toolkit.remote.package_bucket` y
  `package_region` en cada petición web y antes de cada trabajo de la cola (`JobProcessing`). Así
  un worker de larga duración ve el cambio sin reiniciarse y el resto del código no cambia. Sin
  fila guardada quedan los valores del `.env`.
- `docs/GUIA-PERFIL-DESTINO.md` corregida: el destino también necesita `s3:PutObject` en
  `mwt/*/consolidated/*` para la copia integral final.

### Políticas mínimas

| Identidad               | Permisos                                                                                                                                                                                                                                      |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Plataforma              | `ssm:SendCommand` (instancias y `AWS-RunShellScript`), `ssm:GetCommandInvocation`, `ssm:CancelCommand`, `ssm:DescribeInstanceInformation`, `s3:ListBucket`, `s3:GetObject` en `mwt/*`, `s3:PutObject` y `s3:DeleteObject` en `mwt/_pruebas/*` |
| Rol de los orígenes     | `s3:PutObject` en `mwt/*`                                                                                                                                                                                                                     |
| Rol del destino         | `s3:GetObject` en `mwt/*` y `s3:PutObject` en `mwt/*/consolidated/*`                                                                                                                                                                          |
| Rol asumido (confianza) | `sts:AssumeRole` para la identidad del entorno, con `sts:ExternalId` igual al guardado                                                                                                                                                        |

`s3:ListBucket` es el permiso que pide HeadBucket. HeadObject usa `s3:GetObject`.

### Verificación

- Pruebas Feature: `tests/Feature/Aws/AwsSettingsTest.php` cubre el acceso, la contraseña
  confirmada y la validación. También el cifrado: secretos fuera de la base en claro, de las
  respuestas y de la auditoría, y conservar, reemplazar o borrar al cambiar de modo. Además la
  única predeterminada, el bloqueo al eliminar una cuenta en uso, el almacenamiento con
  superposición de la configuración y las verificaciones con clientes STS, SSM y S3 simulados
  (`MockHandler`), con éxito y con fallos.
- `tests/Feature/Aws/AwsCredentialResolutionTest.php` cubre el orden de resolución (conexión,
  predeterminada, entorno; almacenamiento, predeterminada, entorno), la cuenta eliminada, la
  solicitud del rol asumido, la superposición en tiempo de ejecución y el selector de la conexión
  hasta el transporte.
- Pendiente: verificar con una cuenta real de otra institución (rol asumido con External ID).

## F9: preparar los servidores desde la web (2026-10-27)

El usuario decidió que todo se pueda hacer desde la web. Hasta ahora un administrador root tenía
que ejecutar `mwt-source-profile` o `mwt-destination-profile` en cada servidor y, tras cada
despliegue del Consolidador, repetir el segundo con `--force --tree <sha>`. Con SSM el agente ya
corre como root en la instancia y el límite real es IAM, así que la creación de perfiles pasa a la
plataforma: solo administradores, con confirmación de contraseña y auditada sin rutas en claro.
Los scripts quedan como «Alternativa manual».

### Agente 1.17.0

Subcomandos cerrados, validados y sin texto libre en la salida, como los anteriores. Las rutas,
URLs y correos viajan en base64url dentro de pares `nombre=valor`, de modo que todo argumento sigue
en el alfabeto cerrado del cliente (`RemoteAgent::text`).

- `moodle-detect`: busca instalaciones en `/var/www/*`, `/opt/*` y `/srv/*` (hasta 4 niveles) y en
  los servicios de Docker Compose en marcha que montan un `config.php` de Moodle. Mira solo la
  presencia de `version.php`, `config.php` y `lib/setup.php` (o `public/`, Moodle 5.1+) y el dueño;
  nunca lee `config.php`. Devuelve la ruta, el runtime (nativo, o Compose con su directorio y
  servicio) y el usuario probable.
- `source-profile-create`: los mismos argumentos y validaciones que `mwt-source-profile`. No
  reemplaza sin `force=1`, escribe `root:root 0600` y devuelve la misma comprobación que
  `source-check`.
- `destination-profile-create`: lo mismo que `mwt-destination-profile` (URL pública HTTPS, correo y
  usuario administrador, puerto, usuario de ejecución). Crea el runtime desde el árbol desplegado,
  que vuelve a verificar por hash antes de copiarlo. Nunca devuelve la clave generada del
  administrador de Moodle: queda en el `.env` (`0600`), y la respuesta solo dice dónde.
- `destination-repoint <árbol>`: re-apunta los perfiles de destino al árbol recién desplegado
  mientras su sitio no haya recibido escrituras. Cada perfil informa `repointed`,
  `already_current`, `destination_written`, `runtime_missing`, `env_url_mismatch` o
  `profile_invalid`.
- `s3-upload-probe <bucket> <región> [write|readwrite] [etiqueta]`: con el rol de la instancia sube
  un objeto mínimo a `mwt/_pruebas/<instancia>/<aleatorio>` y, en `readwrite`, lo vuelve a leer.
- `source-check` agrega `cpus`, `aws_cli_version` y `aws_cli_v2`; `probe` agrega
  `compose_version`.

### Plataforma

- **Paso 2 Orígenes**: cada origen tiene la lista «Preparar origen»: conexión SSM, agente,
  requisitos (AWS CLI v2, PHP, disco), subida a S3, Recolector, Moodle detectado (elegir un
  candidato o escribir la ruta, y confirmar) y perfil creado y comprobado (Moodle, PHP y CPU). Cada
  punto tiene su estado en vivo y su reintento. El mismo componente sirve a los proyectos de
  recolección y a la recolección que crea «Recolectar ahora».
- **Workers por origen**: desaparece «Workers de cada exportación». Cada origen elige los suyos
  cuando su perfil queda comprobado: «Automático» (CPU − 1, mínimo 1) o de 1 a CPU − 1. El
  Recolector 7.4.2 acepta `--workers=auto|1..4`, con `auto` igual a todas las CPU hasta 4, así que
  la plataforma guarda `auto` y lo resuelve a `min(4, max(1, CPU − 1))` al lanzar la exportación;
  con un perfil comprobado por un agente anterior (sin CPU) pasa `auto` tal cual. La configuración
  nueva es `collector-remote.v3`; las revisiones v2 (un valor para todos) y v1 se siguen leyendo.
- **Paso 3 Destino**: la lista «Preparar destino»: conexión, agente, requisitos (Docker, Compose
  v2, memoria, disco), S3 en lectura y escritura del respaldo, Consolidador desplegado con el perfil
  re-apuntado solo, perfil creado desde la web si no hay ninguno (URL pública HTTPS y correo del
  administrador) y comprobado, y la preparación de siempre con su diálogo de limpiar o reutilizar.
- **Re-apuntado automático**: al terminar bien un despliegue del Consolidador, `DeployRemoteTool`
  llama a `destination-repoint` y vuelve a comprobar los perfiles movidos. Si el re-apuntado falla,
  el despliegue sigue como `DEPLOYED` y la lista explica por qué no se movió el perfil.
- **Reglas**: crear un perfil exige rol de administrador y la confirmación de contraseña
  (`action.confirmed`). Se audita como `REMOTE_SOURCE_PROFILE_CREATED` y
  `REMOTE_DESTINATION_PROFILE_CREATED` con el SHA-256 de las rutas y del correo, nunca en claro.
  La detección (`REMOTE_MOODLE_DETECTED`), la prueba de S3 (`REMOTE_STORAGE_PROBED`) y el
  re-apuntado (`REMOTE_DESTINATIONS_REPOINTED`) también quedan auditados.

### Verificación

- Agente real: `tests/Feature/Remote/RemoteToolDeploymentTest.php` (detección por presencia,
  creación y rechazos del perfil de origen, CPU y AWS CLI v2, prueba de subida con un `aws` falso
  confirmada por la identidad de la plataforma) y `RemoteDestinationTest.php` (creación del destino
  sin devolver la clave, rechazos y re-apuntado).
- Feature: `tests/Feature/Remote/RemoteServerPreparationTest.php` (autorización y contraseña,
  rutas validadas y auditadas por hash, candidatos, prueba de S3, workers contra las CPU,
  re-apuntado tras el despliegue).
- Vitest: `resources/js/lib/server-preparation.test.ts` (estado de cada punto y workers).
- Pendiente de AWS real: la prueba de S3 con los roles de las instancias y el bucket reales (la
  identidad de la plataforma necesita `s3:DeleteObject` en `mwt/_pruebas/*` para borrar el objeto;
  sin ese permiso queda el objeto de unos bytes), `moodle-detect` sobre los Moodle de origen reales
  y un Compose real, y la creación del destino con `useradd` y el grupo `docker` reales.

### Corrección tras la prueba en vivo (agente 1.17.1)

- «Crear y comprobar el perfil» estaba dentro del formulario de la recolección: el botón enviaba y
  guardaba la recolección, y el formulario del perfil se perdía al recargar. Ya no es un `<form>`
  anidado: el botón envía solo su propia petición a `sources/create`, y Enter en sus campos no
  envía la recolección. Lo mismo para el perfil del destino.
- `moodle-detect` lee también la configuración resuelta de cada proyecto de Compose
  (`compose.yaml` hasta tres niveles bajo las rutas habituales), con todos sus perfiles activos,
  así que propone un servicio de PHP que solo existe con un perfil (por ejemplo `tools`, usado con
  `docker compose --profile tools run php`) con su directorio, servicio y perfil. La lista ofrece
  primero la instalación sin problemas, Compose antes que nativa.
- Elegir una instalación nativa ya no devuelve el runtime a «nativo» si el administrador lo cambió
  a mano.
- Se corrigió una clave de React duplicada en el paso de orígenes.
- Pruebas: `resources/js/components/remote-collection-form.test.tsx` (un solo `<form>` y el botón
  del perfil como `type="button"`), los casos nuevos de `server-preparation.test.ts` y
  `RemoteToolDeploymentTest::test_detection_proposes_a_compose_service_enabled_only_by_a_profile`.

### Destino escrito con una versión anterior del Consolidador (agente 1.17.3)

En la prueba en vivo, el destino `campus` ya tenía una consolidación hecha con 8.1.0. Tras desplegar
8.2.0 el perfil no se re-apuntó (el sitio estaba escrito), su comprobación fallaba con
`tool_tree_mismatch` y «Preparar destino» exigía un perfil comprobado: un destino escrito no podía
pasar nunca a la versión nueva desde la web.

- Cuando el único bloqueo de la comprobación es la versión del Consolidador, el perfil queda
  `upgradable` y el punto 6 lo explica en castellano («usa una versión anterior del Consolidador;
  límpielo y prepárelo para pasar a la 8.2.0»). El punto 7 y el panel ofrecen directamente
  «Limpiar el destino y preparar», con la contraseña del administrador y CONFIRMAR; preparar encima
  del sitio anterior no está disponible.
- La preparación con limpieza (`consolidator.prepare` con `reset=1`) acepta el árbol desplegado
  aunque el perfil apunte a otro. Bajo el bloqueo del asistente, antes de restaurar el runtime, el
  agente re-apunta el perfil (`PROFILE_REPOINTED`), conservando el runtime, la URL, el usuario y el
  `.env`; luego restaura el runtime desde el árbol verificado y prepara. Al terminar, la
  plataforma vuelve a comprobar el perfil. La auditoría `REMOTE_DESTINATION_RESET_STARTED` registra
  el árbol anterior (`upgrade_from`).
- Cualquier otro bloqueo, junto al de la versión, sigue impidiendo la preparación.
- Pruebas: `RemoteDestinationTest::test_the_real_cleaning_moves_a_written_destination_to_a_newer_consolidator`
  (agente real, como root), los casos nuevos de `RemoteDestinationPreparationTest` y de
  `server-preparation.test.ts`.
