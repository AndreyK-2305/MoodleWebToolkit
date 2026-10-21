# Etapa D — Recolección remota como Ejecución

Base: `main` en `3c7d40c`. Continúa la [Etapa C](ETAPA-C-RECOLECTOR-EC2.md): la exportación,
la publicación en S3 y el `SourcePackage` ya funcionaban como servicios sueltos. Esta etapa
los conecta con el flujo normal de un proyecto COLLECT: wizard, preflight, confirmación,
Ejecución con pasos, progreso y eventos, y revisión.

## Cortes

| Corte | Alcance                                                                                              | Estado  |
| ----- | ---------------------------------------------------------------------------------------------------- | ------- |
| D1    | Modo `REMOTE` de COLLECT: configuración, preflight, Ejecución hasta `REVIEW`, cancelación e interfaz | Este PR |
| D2    | Confirmación final (`REVIEW` → `COMPLETED`) de un paquete remoto y nuevo intento desde la Ejecución  | Próximo |

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
| `finalization` | Pendiente de D2; la plataforma rechaza finalizar antes de crear un comando                      |

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
  el paquete con sus hashes. No ofrece "Finalizar" hasta D2.

## Límites

- La versión 7.4.2 del catálogo debe estar autorizada (`enabled` y flag `recolector_742`),
  como en cualquier ejecución real.
- La confirmación final y el nuevo intento de una recolección remota quedan para D2.
