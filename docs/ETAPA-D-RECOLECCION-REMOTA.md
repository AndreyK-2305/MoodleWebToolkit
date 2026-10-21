# Etapa D — Recolección remota como Ejecución

Base: `main` en `3c7d40c`. Continúa la [Etapa C](ETAPA-C-RECOLECTOR-EC2.md): la exportación,
la publicación en S3 y el `SourcePackage` ya funcionaban como servicios sueltos. Esta etapa
los conecta con el flujo normal de un proyecto COLLECT: wizard, preflight, confirmación,
Ejecución con pasos, progreso y eventos, y revisión.

## Cortes

| Corte | Alcance                                                                                              | Estado          |
| ----- | ---------------------------------------------------------------------------------------------------- | --------------- |
| D1    | Modo `REMOTE` de COLLECT: configuración, preflight, Ejecución hasta `REVIEW`, cancelación e interfaz | Fusionado (#22) |
| D2    | Confirmación final (`REVIEW` → `COMPLETED`) de un paquete remoto y nuevo intento desde la Ejecución  | Fusionado (#23) |
| D3a   | Limpieza del disco en el servidor de origen al terminar                                              | Este PR         |

## D1: modo `REMOTE`

### Configuración (`collector-remote.v1`)

`PUT /projects/{project}/collector/remote-configuration` guarda solo identificadores:
`server_uuid` (un servidor `SOURCE` del mismo proyecto), `source_profile`, `workers` (1–4) y
`package_name`. Queda como revisión inmutable en `collector_configuration_revisions`, igual
que el modo `LABORATORY`; cualquier cambio exige un nuevo preflight y confirmación.

### Preflight

Se evalúa con la evidencia que la plataforma ya tiene, sin llamar al servidor, para que
confirmar e iniciar sean rápidos y deterministas:

| Check                     | Requiere                                                                       |
| ------------------------- | ------------------------------------------------------------------------------ |
| `collector.configuration` | Proyecto COLLECT, agente remoto habilitado y revisión vigente                  |
| `remote.connection`       | Conexión principal `VALID` (última prueba de conexión y agente)                |
| `collector.distribution`  | Árbol desplegado igual a la distribución verificada y versión autorizada       |
| `remote.source`           | Perfil del servidor comprobado (`CheckRemoteSource`)                           |
| `remote.bucket`           | Bucket de paquetes configurado                                                 |
| `collector.remote_source` | Advertencia aceptada: el Recolector carga el Moodle de origen mientras exporta |

### Ejecución

El binding fija la distribución con el adaptador `collector-742-remote` y el provider
`remote-agent`. La cuota local es fija (16 MiB): la exportación ocupa disco en el origen, no
en la plataforma.

| Paso           | Qué ocurre                                                                                      |
| -------------- | ----------------------------------------------------------------------------------------------- |
| `collection`   | `RemoteCollectorExport` lanza `collector.742`; el progreso es cursos completados sobre el total |
| `verification` | `RemoteSourcePackagePublisher` publica y verifica en S3; se registra el `SourcePackage`         |
| `finalization` | Confirmación final con informes de cierre (D2)                                                  |

`ReconcileRemoteAgentOperation` observa la operación y avanza la Ejecución en la misma pasada
(`RemoteCollectionWorkflow::advance`). El scheduler sigue despachándola mientras la Ejecución
esté activa, incluso después de que la exportación o la subida terminen, así que perder un
worker nunca pierde la ejecución. Un candado consultivo por Ejecución evita que dos pasadas la
avancen a la vez.

Reglas que conserva:

- Una salida 0 sin reporte final no es éxito ni fallo: la Ejecución espera con backoff y se
  marca para revisión manual tras 8 intentos.
- Una salida distinta de cero, un reporte final sin paquete o una subida fallida cierran la
  Ejecución como `FAILED`, conservando la evidencia.
- La cancelación se envía al agente; la Ejecución queda `CANCELLING` hasta que todas sus
  operaciones terminan.
- `REVIEW` exige un `SourcePackage` `VALID` verificado desde S3 por la plataforma.

### Interfaz

- Proyecto: el formulario "Recolección desde el servidor de origen" elige servidor, perfil,
  workers y nombre, y muestra si la conexión, el Recolector y el perfil están listos.
- Ejecución: muestra el servidor, el perfil, la etapa y los cursos exportados, y en `REVIEW`
  el paquete con sus hashes.

## D2: cierre de la recolección remota

### Confirmación final (`REVIEW` → `COMPLETED`)

Usa la misma finalización incremental que el laboratorio (logs, eventos, cuatro informes,
verificación y promoción). Para el modo remoto:

- Antes de cada paso se vuelve a comprobar: exportación y publicación terminadas con éxito,
  `SourcePackage` `VALID` y el objeto en S3 con el mismo tamaño y SHA-256 que se revisó. Si el
  objeto cambió, la Ejecución no se cierra.
- Se espera un solo artefacto previo (la referencia S3) más los cuatro informes de cierre.
- El informe de cierre registra la ubicación del paquete (`region`, `bucket`, `key`) con
  `retention: ADMINISTRATOR`: **el paquete queda publicado en el bucket a disposición del
  administrador; la herramienta no lo borra ni lo expira.** La política de retención la
  decide el administrador del bucket.

### Descarga del paquete

`GET …/artifacts/{artifact}/download` de un artefacto S3 por referencia redirige a un enlace
firmado de 15 minutos, solo para administradores, después de verificar el objeto otra vez.
La descarga queda en `artifact_downloads` y en auditoría (`delivery: S3_PRESIGNED`).

### Nuevo intento

`RetryCollectorExecution` sirve a los dos modos: desde el último intento `FAILED` o
`CANCELLED`, con el preflight vigente, crea una Ejecución nueva con linaje
(`retried_from_execution_id`) y conserva la anterior con su evidencia. La migración
`allow_remote_collector_retry_lineage` amplía el guardia de PostgreSQL al adaptador remoto.

### Interfaz

- Botones "Desplegar Recolector 7.4.2" y "Comprobar perfil de origen" en el formulario de
  recolección remota (`POST …/servers/{server}/collector/deploy` y `…/sources/check`).
- En modo remoto, el wizard ya no muestra escenarios simulados: el paso 3 remite a la
  tarjeta de recolección remota y los textos de instancias y preflight dejan de decir
  "simulado" cuando hay acceso remoto.

## D3a: limpieza del disco en el origen

El agente 1.4.0 añade `cleanup <operación>`. Sobre una operación terminada (con `exit.json` y sin proceso vivo) borra solo lo pesado: el ZIP, el árbol de trabajo `.moodle-collector-work-<paquete>` y `tmp/` del Recolector, y `audit/` de la publicación. Conserva `launch.json`, `exit.json`, los logs, el sidecar SHA-256 y el `status.json`, y deja `cleanup.json` con los bytes liberados. Valida todas las rutas antes de tocar nada: un enlace en cualquiera de ellas aborta sin borrar. No cruza sistemas de archivos y es idempotente.

La plataforma (`RemoteSourceCleanup`) lo pide cuando el paquete ya no hace falta en el origen: verificado en S3 (`REVIEW` en adelante) o cuando la Ejecución se cerró sin él (`FAILED`/`CANCELLED`). Si el servidor no responde, reintenta con backoff (1 min a 1 h) sin bloquear la revisión, y lo marca para revisión tras 8 intentos. La Ejecución muestra el espacio liberado.

Para que el agente quepa en una sola llamada de instalación, ahora viaja comprimido (gzip + base64, unos 14 KB). El SHA-256 se sigue verificando sobre el archivo descomprimido.

## Límites

- La versión 7.4.2 del catálogo debe estar autorizada (`enabled` y flag `recolector_742`),
  como en cualquier ejecución real.
- Varios orígenes por proyecto, perfiles institucionales reales y limpieza del directorio
  de trabajo en el origen quedan para D3.
