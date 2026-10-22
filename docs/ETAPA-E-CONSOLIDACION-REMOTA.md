# Etapa E — Consolidación remota en el servidor de destino

Base: `main` en `4d96b1f`. Continúa la [Etapa D](ETAPA-D-RECOLECCION-REMOTA.md): una
Ejecución COLLECT ya deja varios paquetes sellados y verificados en S3. Esta etapa lleva esos
paquetes al servidor de destino y ejecuta allí el Consolidador V8 como una Ejecución
CONSOLIDATE, con el mismo agente (`mwt-agent`), transporte SSM y modelo de operaciones
remotas durables. Corresponde a las Iteraciones 4 y 6 del
[plan maestro](PLAN-MAESTRO-SINCRONIZACION.md).

## Lo que exige el Consolidador (8.0.0-linux-rc12)

Estudio de `BaseLine/Consolidador/Consolidador-v8.0.0` (README, `moodle-consolidation.sh`,
`scripts/consolidation-wizard.ps1`, `scripts/oauth2-validate.*`):

| Tema                  | Contrato observado                                                                                                                                                                                                                                                                                                                                                                              | Consecuencia para la plataforma                                                                                                                                        |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Entrada               | De 2 a 32 ZIP sellados en `copias/`, cada uno con su `.zip.sha256` y `source_id` único (`config/assistant.json`)                                                                                                                                                                                                                                                                                | Un proyecto CONSOLIDATE necesita ≥ 2 paquetes `VALID`; D3c ya los produce                                                                                              |
| Configuración         | `CONFIGURAR.sh` exige una terminal interactiva y escribe `.env` (URL pública, admin, claves de BD, SMTP, `MOODLE_MANAGED_CONFIG_DIR`)                                                                                                                                                                                                                                                           | No se puede invocar por SSM. El `.env` lo escribe un helper de administrador en el servidor, como `mwt-source-profile`; la clave del admin nunca sale del servidor     |
| Preparación           | `preparar` construye imágenes (Moodle 5.2.1, MariaDB, runtime pwsh) e inicia Moodle; necesita Internet y Docker Compose v2                                                                                                                                                                                                                                                                      | Operación durable `consolidator.prepare`, fuera de cualquier petición HTTP (4C)                                                                                        |
| Ejecución             | `ejecutar-automatico` corre el asistente en modo `-Automatic`, con `flock` en `reports/assistant-run.lock`. `iniciar-segundo-plano` usa `systemd-run --user` y exige linger                                                                                                                                                                                                                     | El agente lanza `ejecutar-automatico` con su propio launcher (systemd del sistema o nohup), igual que el Recolector; así no depende de linger                          |
| Estado                | `reports/assistant-state.json` (`schema_version` 1.0, `stage`, `status`, `message`, `review_paths`, `destination_write_recorded`), log de eventos TSV y `reports/fase-6-workers-status.json`                                                                                                                                                                                                    | Nuevo subcomando del agente que devuelve esos documentos normalizados; la plataforma no lee rutas arbitrarias                                                          |
| Etapas reales         | `01-importar-paquetes`, `02-oauth2`, `03-plugins`, `03b-oauth2-final`, `04-identidades`, `05-plan-usuarios`, `05b-readiness`, `06-aplicar-usuarios`, `07-verificar-usuarios`, `08-plan-piloto`, `09-aplicar-piloto`, `09b-themes-piloto`, `10-verificar-piloto`, `11-plan-lote`, `12-preparar-lote`, `13-aplicar-lote`, `13b-themes-lote`, `14-verificar-lote`, `15-cierre`, `16-paquete-sitio` | Los pasos de la Ejecución usan estos 20 identificadores tal cual (4E)                                                                                                  |
| Salidas del asistente | Código 22 = `WAITING_USER_ACTION` (OAuth2 o plugins); 24 = traspaso a segundo plano (solo interactivo)                                                                                                                                                                                                                                                                                          | 22 se traduce en una intervención de la Ejecución; al resolverla se relanza y el asistente reutiliza los checkpoints aprobados                                         |
| OAuth2                | `config/oauth2.json` exige `required: true`. El validador rechaza URLs sin HTTPS, exige `wwwroot` igual a la URL pública y un servicio Google con `clientid`                                                                                                                                                                                                                                    | **Incluso en laboratorio** el destino necesita un nombre DNS con TLS y un cliente OAuth de Google; el administrador lo configura en Moodle y la plataforma nunca lo ve |
| Cancelación           | `DETENER.sh` detiene también Moodle, BD y cron                                                                                                                                                                                                                                                                                                                                                  | Se presenta como «detener el runtime», no como cancelar (4M)                                                                                                           |
| Resultado             | `CONSOLIDATION_ASSISTANT_OK`, cierre `evidence_consolidated`, copia integral `exports/phase8/paquete-sitio-consolidado.zip` con su SHA-256                                                                                                                                                                                                                                                      | La copia integral se sube a S3 como artefacto de administrador; la publicación (`PUBLICAR-SITIO.sh`) queda como operación separada (4P)                                |

Puntos que el estudio no cierra y se resolverán en el corte correspondiente:

- Cómo acepta el modo automático la intervención de plugins (`[Y]`/`[R]` en modo interactivo).
  Hay que confirmar qué archivo sellado (`exports/phase2/plugin_intervention_resolution.json`)
  permite continuar sin terminal.
- El `Read-Host 'Seleccione 1 o 2'` de la fase 12 parece exclusivo del modo interactivo. Debe
  comprobarse con una corrida real.

## Cortes propuestos

| Corte | Alcance                                                                                                                                                                                                                               |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| E1    | Despliegue del Consolidador en el destino (árbol verificado, como el Recolector) y helper `mwt-destination-profile`, que escribe el `.env` como root. Subcomando `destination-check`: Docker, Compose, flock, disco, RAM, HTTPS y URL |
| E2    | Preparación del destino como operación durable (`consolidator.prepare`) con progreso y log; estado del runtime en la ficha del servidor                                                                                               |
| E3    | Transferencia S3 → `copias/` en el destino (operación `package.fetch` del agente, con el SHA-256 verificado antes de mover cada ZIP) y bloqueo de cambios una vez hay escritura en el destino                                         |
| E4    | Ejecución CONSOLIDATE: configuración (paquetes, workers, piloto), preflight, lanzamiento de `ejecutar-automatico`, pasos reales y progreso por curso                                                                                  |
| E5    | Intervenciones: «Configurar OAuth2» (Continuar) y plugins como formularios declarativos que relanzan el asistente; nunca comandos arbitrarios                                                                                         |
| E6    | REVIEW → COMPLETED: evidencias del cierre, subida de la copia integral a S3, limpieza del disco del destino y publicación como operación aparte                                                                                       |

## Responsabilidades del administrador (decisión del 2026-10-21)

- **Dominio y TLS.** Se asume que el administrador ya tiene montado el destino con un
  dominio propio y su proxy TLS frente a Moodle. La herramienta no gestiona DNS ni
  certificados. Solo comprueba en `destination-check` que la URL pública del perfil es HTTPS y
  coincide con la del `.env`.
- **OAuth2 de Google.** Cuando el asistente llega a `02-oauth2` (o `03b-oauth2-final`) y se
  detiene con `WAITING_USER_ACTION`, la Ejecución abre una intervención «Configurar OAuth2».
  Esa intervención muestra las instrucciones de `reports/oauth2-configuracion-manual.txt`
  (URI de callback, pasos en Moodle). El administrador configura el servicio en Moodle por su
  cuenta y pulsa **Continuar** en la herramienta. A partir de ahí se da por listo: la
  plataforma relanza el asistente y este revalida en vivo. Si la validación vuelve a fallar,
  la Ejecución regresa a la misma intervención con el mensaje del asistente. La plataforma
  nunca pide, lee ni guarda el Client ID ni el secret.

## Prerrequisitos del laboratorio

1. **Tamaño de la instancia.** «Claude Destino» (`i-0d69555bdfa275322`) es `t3.small` (2 GiB de
   RAM) con 30 GiB gp3 sin cifrar. Moodle 5.2.1, MariaDB y el runtime pwsh, con los workers de
   restauración, no caben con holgura en 2 GiB. Se recomienda `t3.medium` (4 GiB) como mínimo.
2. **Lectura de paquetes en S3.** El rol `mwt-ssm-lab` solo puede escribir en `mwt/`. Para E3
   hay dos caminos:
    - una política de lectura `s3:GetObject` restringida a `mwt/` para el destino, idealmente
      con un rol propio. Es la opción recomendada;
    - URLs prefirmadas de 15 minutos que emite la plataforma. No requieren cambiar IAM, pero la
      URL queda en el historial de comandos de SSM mientras es válida.
3. **Dominio de prueba.** La prueba de punta a punta en AWS (E4–E6) necesita que el destino de
   laboratorio tenga su dominio y TLS montados, igual que en producción. E1–E3 se pueden
   validar antes, sin él.

## E1: Consolidador en el destino y perfil de destino

Guía para el administrador: [GUIA-PERFIL-DESTINO.md](GUIA-PERFIL-DESTINO.md).

- **Despliegue.** `POST /projects/{project}/servers/{server}/consolidator/deploy` solo acepta
  servidores `DESTINATION` con la conexión `VALID`. Encola `DeployRemoteTool`: el árbol pesa
  unos 512 KB comprimido y viaja en decenas de llamadas, demasiadas para una petición HTTP. Su
  estado (`QUEUED`, `RUNNING`, `DEPLOYED` o `FAILED`, con código) queda en
  `metadata.remote_tool_deployments` y se muestra en el panel.
- **Árbol completo.** Viaja el árbol completo, incluidos los archivos excluidos y mutables del
  catálogo, de modo que la copia remota hashea igual que la entrada del catálogo (263
  archivos). Por eso `RemoteToolDeployer` ya no rechaza distributions con exclusiones: el
  almacén del agente es inmutable y la configuración del sitio vive en el runtime que deriva el
  administrador.
- **Perfil.** `remote-agent/mwt-destination-profile` (root) reemplaza a `CONFIGURAR.sh`, que exige
  una terminal. Crea el runtime `/srv/mwt-destinations/<id>`, una copia del árbol desplegado, con
  su `.env` 0600 para un sitio HTTPS detrás de proxy, y escribe
  `/etc/mwt-agent/destinations/<id>.conf` con `RUNTIME_DIR`, `PUBLIC_URL` y `TOOL_TREE`. La clave
  del administrador de Moodle solo se muestra en la terminal del administrador.
- **Agente 1.6.0.** Dos subcomandos nuevos:
    - `destination-list`: devuelve id, validez y URL pública de cada perfil;
    - `destination-check`: devuelve hechos cerrados sobre el `.env` (existe, está protegido, está
      completo, su URL coincide), el árbol, la integridad del motor (`FILES.sha256`), Docker,
      Compose, `flock`, CPU, memoria, disco, Moodle destino corriendo y escrituras previas. No
      devuelve rutas ni valores del `.env`.
- **Preparación.** `CheckRemoteDestination` traduce el informe en bloqueos y advertencias:
    - bloqueos: `env_*`, `tool_missing`, `tool_tree_mismatch`, `engine_modified`,
      `docker_unavailable`, `compose_v2_missing`, `flock_missing` y `public_url_not_https`;
    - advertencias: `low_memory` (< 3,5 GB), `low_disk` (< 20 GiB) y `destination_written`.
      Una vez que hubo escrituras en el destino, un motor modificado ya no bloquea: las resoluciones
      del operador cambian archivos de `config/`.

## E2: preparación del destino como operación durable

- **Operación del agente.** `consolidator.prepare` (`destination`, `distribution`) ejecuta
  `moodle-consolidation.sh preparar` desde el runtime del perfil. Antes de crear el proceso, el
  agente comprueba:
    - que el perfil nombra ese árbol;
    - el hash del árbol del almacén;
    - el runtime con su `.env`;
    - `FILES.sha256` del runtime;
    - que no haya escrituras previas (`destination_already_written`).
- **Ciclo de vida.** Como el Recolector, corre en una unidad systemd que sobrevive a la sesión
  SSM. La preparación pertenece al servidor y no a una Ejecución: su estado vive en
  `metadata.remote_destination_preparations.<perfil>`, con los estados `STARTING`, `RUNNING`,
  `CANCELLING`, `SUCCEEDED`, `FAILED`, `CANCELLED` y `UNKNOWN`.
- **Observación.** `destinations:observe-preparations` corre cada minuto y encola
  `ObserveDestinationPreparation` por cada preparación activa. Cada observación es acotada: el
  estado del proceso, hasta 64 KB nuevos de `stdout` redactados (se conservan los últimos 8 KB)
  y los marcadores del Consolidador (`PREFLIGHT_OK`, `DESTINO_LISTO`, `EXPORT_TRANSPORT_OK`,
  `TARGET_SHARED_EMAIL_POLICY_OK`).
- **Resultado.** Solo hay éxito con código 0 y el último marcador; un código 0 sin él es
  `markers_missing`. Un fallo conserva el final redactado de `stderr` y su última línea `ERROR`.
  Un proceso perdido, sin latido ni salida, queda `UNKNOWN`. Un lanzamiento que nunca llegó al
  servidor se cierra como `launch_not_received` después del margen de 90 s. Tras el éxito se
  repite la comprobación del perfil, que ya muestra Moodle destino en ejecución.
- **Interfaz.** Cada perfil comprobado ofrece **Preparar destino** (solo si está listo y sin
  escrituras), el avance por marcadores, el error, las últimas líneas del registro y
  **Cancelar preparación**.

## E3: transferencia S3 → `copias/` del destino

Se eligió la lectura con el rol de la instancia: el destino necesita `s3:GetObject` sobre
`mwt/*` en el bucket de paquetes. Con esto no viajan URLs prefirmadas por SSM.

- **Verificación previa.** Antes de lanzar, la plataforma verifica el objeto en S3 con su
  propia identidad (`RemotePackageObjects::verifyPackage`). Además exige que la llave siga el
  contrato de publicación, `mwt/<proyecto>/<source_id>/<sha256>.zip`.
- **Operación del agente.** `package.fetch` recibe `destination`, `project`, `source`, `sha`,
  `bucket` y `region`. Descarga primero el sidecar y valida que declare ese SHA-256 y un nombre
  `*.zip` seguro. Comprueba el espacio libre (ZIP + 10 %) y descarga el ZIP a un staging dentro
  de `copias/`. Solo mueve los archivos si el ZIP hashea igual y tiene el tamaño de S3, y mueve
  el sidecar al final. Un paquete idéntico ya presente cuenta como `already_present`; otro con
  el mismo nombre falla con `destination_name_conflict`. Los fallos son marcadores cerrados en
  `stderr`: `sidecar_invalid`, `package_hash_mismatch`, `insufficient_disk`, etc.
- **Éxito.** Solo hay éxito si el SHA-256 que `fetch-report` devuelve desde el servidor es el
  mismo que la plataforma verificó en S3.
- **Listado y retiro.** `copias-list` devuelve nombre, tamaño y SHA-256 del sidecar, sin volver
  a hashear los ZIP; la plataforma los asocia con los `SourcePackage` por hash.
  `copias-remove` retira un paquete.
- **Bloqueo.** Ambos quedan bloqueados en el agente y en la plataforma cuando existe
  `reports/destination-write.lock.json`, es decir, desde la primera escritura del Consolidador.
- **Estado.** Vive en `metadata.remote_destination_fetches.<perfil>.<paquete>` y
  `metadata.remote_destination_copias.<perfil>`. El mismo comando del scheduler observa las
  transferencias activas, una a la vez por destino.
- **Interfaz.** Paquetes en `copias/` con su sello, **Traer desde S3** (paquetes verificados en
  S3 de los proyectos que el usuario puede ver), **Retirar** y el historial de transferencias.
- **Agente 1.7.0.** Agrega `package.fetch` y los subcomandos `fetch-report`, `copias-list` y
  `copias-remove`.

## E4: Ejecución CONSOLIDATE remota (diseño)

Se divide en dos cortes:

- **E4a.** Configuración, preflight y Ejecución hasta `REVIEW`, `WAITING_USER_ACTION`, `FAILED`
  o `CANCELLED`, con los pasos reales y el progreso.
- **E4b.** Intervenciones tipadas (E5) y cierre `REVIEW` → `COMPLETED` (E6).

### Configuración (`consolidator-remote.v1`)

Modo `REMOTE_CONSOLIDATION` de un proyecto CONSOLIDATE. Guarda solo identificadores:

- el servidor `DESTINATION` del proyecto y su perfil;
- de 2 a 32 `source_package` (uuid y SHA-256), que pueden venir de proyectos COLLECT visibles
  para quien configura;
- los `workers` (`auto` o de 1 a 4).

Se versiona como revisión inmutable, con el mismo mecanismo que COLLECT. El binding no usa
`execution_tool_binding_sources`, que exige paquetes locales del mismo proyecto: los paquetes y
sus hashes quedan en el snapshot de configuración del binding.

### Preflight

Se evalúa con la evidencia que la plataforma ya tiene:

| Check                       | Requiere                                                                                                                          |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `consolidator.distribution` | Árbol del Consolidador desplegado e igual al catálogo                                                                             |
| `remote.destination`        | Perfil comprobado y listo                                                                                                         |
| `destination.prepared`      | Última preparación `SUCCEEDED` o Moodle destino en ejecución                                                                      |
| `destination.copias`        | El último listado de `copias/` contiene **exactamente** los paquetes elegidos: el Consolidador importa todos los ZIP de `copias/` |
| `source.packages`           | Paquetes `VALID`, disponibles y no revocados                                                                                      |
| `destination.unwritten`     | Sin escrituras previas, salvo que se reanude la misma Ejecución                                                                   |
| `oauth.manual`              | Advertencia aceptada: la Ejecución se detendrá para configurar OAuth2 en Moodle                                                   |

### Ejecución

- **Operación del agente.** `consolidator.run` (`destination`, `distribution`, `workers`) re-verifica
  el árbol y el perfil, y ejecuta `moodle-consolidation.sh ejecutar-automatico --workers=N` en una
  unidad systemd del agente.
- **Reporte.** `consolidator-report` devuelve, codificados y limitados en tamaño:
    - `reports/assistant-state.json`;
    - `reports/fase-6-workers-status.json`;
    - el resumen de cierre;
    - la presencia del candado de escritura.
- **Pasos.** Los 20 identificadores reales: `01-importar-paquetes`, `02-oauth2`, `03-plugins`,
  `03b-oauth2-final`, … `16-paquete-sitio`. Se suma `review`.
- **Progreso.** Etapas completadas sobre 20 y, en `13-aplicar-lote`, cursos verificados sobre el
  total, según el estado de los workers.
- **Salida del asistente.**
    - Código 0 con `16-paquete-sitio` completada: `VERIFYING` y luego `REVIEW`.
    - Código 22 (`WAITING_USER_ACTION`): la Ejecución pasa a `WAITING_USER_ACTION` con la etapa y el
      mensaje del asistente. **Continuar** lanza una nueva operación `consolidator.run` sobre la misma
      Ejecución; el asistente reutiliza los checkpoints aprobados. Es el mecanismo de la
      intervención de OAuth2 decidida para la etapa E.
    - Código 20 u otro: `FAILED`, con la evidencia conservada.
- **Cancelación.** Señala la unidad del agente, que detiene el asistente. No es `DETENER.sh`, que
  detiene también Moodle y la base de datos.

## E4a: implementación

- **Catálogo.** `moodle.consolidation.v8` pasa de `BLOCKED` a `LABORATORY`, todavía detrás de
  la feature flag `consolidador_800`. `php artisan consolidator:enable-remote` habilita la
  versión después de volver a verificar su distribución.
- **Binding.** El adaptador `consolidator-800-remote` no exige la configuración runtime aprobada
  en la plataforma: el runtime es el del administrador. A cambio, el binding valida el flujo,
  el provider, el modo y que no haya paquetes locales.
- **Agente 1.8.0.**
    - Nueva operación `consolidator.run` y nuevo subcomando `consolidator-report`.
    - La integridad del motor (`engine_intact`) excluye `config/`: allí el operador y el
      Consolidador guardan políticas y resoluciones a propósito.
- **Arranque.** Antes del primer lanzamiento, `RemoteConsolidationWorkflow` vuelve a listar
  `copias/` en vivo; si no coincide con la configuración, la Ejecución falla sin lanzar nada.
- **Continuar.** `POST …/consolidation/continue` exige una confirmación explícita. La transición
  `WAITING_USER_ACTION` → `RUNNING` es la reserva: un segundo Continuar no lanza otra corrida.
- **Cierre de E4a.** «Finalizar» queda rechazado para una consolidación remota hasta E6.

## E5: intervenciones tipadas

Cada espera del asistente (salida 22) abre un `Conflict` tipado en la Ejecución. Su decisión
pasa por la resolución genérica de conflictos: versionada, idempotente, auditada y con un
comando `RESOLVE_CONFLICT` durable que el provider de la consolidación procesa. El botón
«Continuar» de E4a queda reemplazado por esta intervención.

| Tipo                        | Etapas                          | Lo que muestra (whitelist, redactado)                                                                     | Decisiones                                                                       |
| --------------------------- | ------------------------------- | --------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| `OAUTH2_CONFIGURATION`      | `02-oauth2`, `03b-oauth2-final` | Instrucciones de `reports/oauth2-configuracion-manual.txt`, URI de callback y pendientes de la validación | `CONFIRM_COMPLETED`: el administrador configuró Google y se relanza el asistente |
| `PLUGIN_INTERVENTION`       | `03-plugins`                    | Necesidades de los orígenes, diff del inventario, errores técnicos y estado del checkpoint                | `CONFIRM_COMPLETED` (revalidar) y, con `READY_FOR_APPROVAL`, `ACCEPT`            |
| `CONSOLIDATOR_INTERVENTION` | Cualquier otra                  | El mensaje del asistente y las rutas a revisar en el servidor                                             | `CONFIRM_COMPLETED`                                                              |

- **Agente 1.9.0.** Agrega dos piezas:
    - `consolidator-intervention <perfil> oauth2|plugins` devuelve los documentos del
      Consolidador, como máximo 10 KB cada uno;
    - `consolidator.plugins-approve` ejecuta, bajo el lock del asistente y en su propio
      contenedor `assistant-runtime`, los dos pasos que el asistente aplica con `[Y]`:
      `audit-package-plugins.ps1 -Mode Approve` y `v8-plugin-resolver.ps1 -Mode Seal`. Solo se
      acepta con el checkpoint en `READY_FOR_APPROVAL` y sin escrituras previas.
- **Aceptar plugins.** La Ejecución vuelve a `RUNNING` mientras se sella la aprobación. Si se
  sella, se lanza la siguiente corrida del asistente. Si falla, la Ejecución vuelve a esperar con
  una nueva intervención de plugins.
- **Continuación bloqueada.** Si el destino no está listo para continuar (conexión, perfil,
  `copias/`), la decisión queda registrada y la Ejecución sigue esperando con el evento
  `consolidator.continue_blocked`.

## E6a: cierre `REVIEW` → `COMPLETED`

E6 se divide en tres cortes:

- **E6a.** La confirmación: es este corte.
- **E6b.** El respaldo de la copia integral en S3 y la limpieza del disco del destino.
- **E6c.** La publicación (`PUBLICAR-SITIO.sh`), como operación aparte.

Lo que cambia en E6a:

- **Paso de cierre.** El último paso de la Ejecución pasa a llamarse `finalization`
  («Confirmar la consolidación») y «Finalizar» queda habilitado para la consolidación remota. El
  provider procesa `FINALIZE` con la misma finalización por etapas que el resto de las
  herramientas.
- **Comprobación de lo revisado.** `RemoteConsolidationWorkflow::assertFinalizable` exige que la
  última corrida haya cerrado la consolidación (`completed/completed`) y que la huella de
  revisión (paquetes + SHA-256 de la copia integral) siga igual.
- **Lectura en vivo.** Al iniciar la finalización y justo antes de cerrarla, se lee el destino
  en vivo: el mismo cierre y la misma copia integral. Las pasadas intermedias no llaman al
  servidor.
- **Informes.** Son los cuatro de cierre. El JSON registra:
    - el destino y el perfil;
    - los paquetes consolidados;
    - el cierre del Consolidador;
    - el SHA-256 de la copia integral, con su ubicación (`DESTINATION`);
    - cada intervención, con su decisión, quién decidió y cuándo.

    La copia integral no sale del destino en este corte.

## E6b: respaldo de la copia integral y limpieza del destino

Una Ejecución `COMPLETED` deja el proyecto en solo lectura: los triggers rechazan nuevos
artefactos, operaciones o cambios en sus servidores. Por eso el respaldo se toma **en
`REVIEW`, antes de finalizar**, y «Finalizar» espera a que termine.

- **Operación del agente.** `consolidator.site-upload` exige que el ZIP de
  `exports/phase8/paquete-sitio-consolidado.zip` tenga el SHA-256 revisado y que
  `site_package_summary.json` declare ese mismo hash. Lo sube con el rol de la instancia a
  `mwt/<proyecto>/consolidated/<ejecución>/<sha>.zip`, cifrado en reposo y con el SHA-256 como
  metadato y como checksum de S3.
- **Registro.** La plataforma lee `site-upload-report`, verifica el objeto con su propia
  identidad (`RemotePackageObjects::verifyPackage`) y lo registra como artefacto
  `FULL_BACKUP`, de referencia S3 y con retención a cargo del administrador. Si la
  verificación todavía no es posible, reintenta con backoff y, tras varios intentos, lo marca
  para revisión.
- **Limpieza.** Con el respaldo verificado, `copias-release` libera los ZIP de origen de
  `copias/`, que ya están en S3. Solo lo hace si el Consolidador ya escribió en el destino. La
  copia integral se conserva en el destino porque la publicación la necesita.
- **Cierre.** Finalizar incluye la referencia del respaldo (cinco artefactos en lugar de
  cuatro). El informe JSON registra su ubicación y su retención. La descarga sigue la regla de
  las referencias S3: un enlace firmado de corta duración, solo para administradores.
- **Fallo.** Un respaldo fallido no cambia la Ejecución y puede tomarse de nuevo.
- **Agente 1.10.0.**

## Prueba real E1–E6b (2026-10-21 y 22): hallazgos

Destino: EC2 `t3.medium` (Ubuntu 26.04, 4 GiB, 50 GB), perfil `campus` en
`https://moodle-test.cuadre.click`, con Caddy como proxy TLS. Origen: los paquetes `pregrado`
y `posgrado` de la Recolección D3.

**Corregido en la plataforma (agente 1.11.0):**

- **Carpeta S3.** El paquete se busca en la carpeta del perfil de origen que registra su clave
  S3, no en su `source_id`.
- **`tail` en logs grandes.** El agente salía con 141 (`SIGPIPE`).
- **Marcadores de la preparación.** Se leían con la lectura atrasada.
- **Usuario sin privilegios.** El Consolidador se niega a ejecutarse como root
  (`ASSISTANT_UID y ASSISTANT_GID deben ser enteros positivos`). El perfil declara `RUN_AS`,
  un usuario sin privilegios del grupo `docker`, dueño del runtime. El helper lo crea y le
  pasa el runtime. El agente ejecuta como ese usuario `preparar`, `ejecutar-automatico` y la
  aprobación de plugins, y deja `copias/` a su nombre. La comprobación bloquea con
  `runtime_not_owned` o `run_as_not_docker`.
- **Configuración administrada.** El helper la crea dentro del runtime (`managed-config/`),
  como propone `CONFIGURAR.sh`. Fuera de él, el gestor de configuración del contenedor del
  asistente no podía escribirla y la política de themes de `05b-readiness` fallaba.
- **Motor intacto.** `config.yaml`, que el Consolidador reescribe al importar los paquetes, y
  `docker/custom-plugins/`, donde instala los plugins del catálogo, quedan fuera de la
  comprobación, igual que `config/`.
- **Exit 20.** Una etapa bloqueada en una decisión de configuración, como el theme global de
  `05b-readiness`, ya no cierra la Ejecución en `FAILED`. Abre una intervención
  `CONSOLIDATOR_INTERVENTION`: el administrador corrige `config/` en el servidor y pulsa
  Continuar, o cancela.
- **Nuevo intento.** Una consolidación `FAILED` o `CANCELLED` que ya corrió admite un nuevo
  intento. Continúa desde los checkpoints del destino, aunque este ya haya recibido escrituras
  de esta misma consolidación, y conserva el intento anterior.

**Del administrador:**

- **Proxy TLS.** Con `MOODLE_REVERSE_PROXY=1`, Moodle rechaza toda petición cuyo `Host` sea el
  de su URL pública («Proxy inverso habilitado…»). El proxy debe reenviar el `Host` local; ver
  la guía del perfil de destino, § 1.1.
- **DNS.** Un registro A antiguo hizo fallar la validación de Let's Encrypt hasta que se
  eliminó.

**Del Consolidador 8.0.0-linux-rc12** (para reportar a su mantenimiento):

- **Pins sin plugins.** Con cero plugins por instalar, `v8c_install` reescribe
  `docker/custom-plugins/approved-plugins.json` con otro formato, pero el resolver solo
  reconstruye la imagen si `installed_count > 0`. La auditoría ve entonces pins distintos a
  los de la imagen (`PLUGIN_TECHNICAL_VALIDATION_FAILED: destino, URL o pins inconsistentes`).
  Se resolvió reconstruyendo `moodle-target` como hace el resolver.
- **Sello sin revisión.** Sin necesidades de plugins, la auditoría aprueba sin pasar por
  `READY_FOR_APPROVAL`, y el asistente solo ejecuta `v8-plugin-resolver -Mode Seal` desde ese
  estado. El lock queda en `ARTIFACTS_VERIFIED_PENDING_STAGING` y la etapa 03 espera
  indefinidamente. Se resolvió ejecutando una vez `-Mode Seal` en el contenedor del asistente.
