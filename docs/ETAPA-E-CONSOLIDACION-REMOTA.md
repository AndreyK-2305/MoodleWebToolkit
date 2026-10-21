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

| Tema                  | Contrato observado                                                                                                                                                                                                                                                                                                                                                                | Consecuencia para la plataforma                                                                                                                                        |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Entrada               | De 2 a 32 ZIP sellados en `copias/`, cada uno con su `.zip.sha256` y `source_id` único (`config/assistant.json`)                                                                                                                                                                                                                                                                  | Un proyecto CONSOLIDATE necesita ≥ 2 paquetes `VALID`; D3c ya los produce                                                                                              |
| Configuración         | `CONFIGURAR.sh` exige una terminal interactiva y escribe `.env` (URL pública, admin, claves de BD, SMTP, `MOODLE_MANAGED_CONFIG_DIR`)                                                                                                                                                                                                                                             | No se puede invocar por SSM. El `.env` lo escribe un helper de administrador en el servidor, como `mwt-source-profile`; la clave del admin nunca sale del servidor     |
| Preparación           | `preparar` construye imágenes (Moodle 5.2.1, MariaDB, runtime pwsh) e inicia Moodle; necesita Internet y Docker Compose v2                                                                                                                                                                                                                                                        | Operación durable `consolidator.prepare`, fuera de cualquier petición HTTP (4C)                                                                                        |
| Ejecución             | `ejecutar-automatico` corre el asistente en modo `-Automatic`, con `flock` en `reports/assistant-run.lock`. `iniciar-segundo-plano` usa `systemd-run --user` y exige linger                                                                                                                                                                                                       | El agente lanza `ejecutar-automatico` con su propio launcher (systemd del sistema o nohup), igual que el Recolector; así no depende de linger                          |
| Estado                | `reports/assistant-state.json` (`schema_version` 1.0, `stage`, `status`, `message`, `review_paths`, `destination_write_recorded`), log de eventos TSV y `reports/fase-6-workers-status.json`                                                                                                                                                                                      | Nuevo subcomando del agente que devuelve esos documentos normalizados; la plataforma no lee rutas arbitrarias                                                          |
| Etapas reales         | `01-importar-paquetes`, `02-oauth2`, `03b-oauth2-final`, `04-identidades`, `05-plan-usuarios`, `05b-readiness`, `06-aplicar-usuarios`, `07-verificar-usuarios`, `08-plan-piloto`, `09-aplicar-piloto`, `09b-themes-piloto`, `10-verificar-piloto`, `11-plan-lote`, `12-preparar-lote`, `13-aplicar-lote`, `13b-themes-lote`, `14-verificar-lote`, `15-cierre`, `16-paquete-sitio` | Los pasos de la Ejecución usan estos 19 identificadores tal cual (4E)                                                                                                  |
| Salidas del asistente | Código 22 = `WAITING_USER_ACTION` (OAuth2 o plugins); 24 = traspaso a segundo plano (solo interactivo)                                                                                                                                                                                                                                                                            | 22 se traduce en una intervención de la Ejecución; al resolverla se relanza y el asistente reutiliza los checkpoints aprobados                                         |
| OAuth2                | `config/oauth2.json` exige `required: true`. El validador rechaza URLs sin HTTPS, exige `wwwroot` igual a la URL pública y un servicio Google con `clientid`                                                                                                                                                                                                                      | **Incluso en laboratorio** el destino necesita un nombre DNS con TLS y un cliente OAuth de Google; el administrador lo configura en Moodle y la plataforma nunca lo ve |
| Cancelación           | `DETENER.sh` detiene también Moodle, BD y cron                                                                                                                                                                                                                                                                                                                                    | Se presenta como «detener el runtime», no como cancelar (4M)                                                                                                           |
| Resultado             | `CONSOLIDATION_ASSISTANT_OK`, cierre `evidence_consolidated`, copia integral `exports/phase8/paquete-sitio-consolidado.zip` con su SHA-256                                                                                                                                                                                                                                        | La copia integral se sube a S3 como artefacto de administrador; la publicación (`PUBLICAR-SITIO.sh`) queda como operación separada (4P)                                |

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
