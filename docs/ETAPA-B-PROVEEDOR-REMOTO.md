# Etapa B — Proveedor remoto (SSM principal, SSH alternativo)

Base: `main` en `4b36ec4` (merge del PR #9). Este documento cubre el corte **B1**.

## Objetivo de la etapa

Que la plataforma se conecte a las EC2 de origen y destino sin que el operador
entre por SSH, y que un proceso largo siga vivo aunque se pierda la sesión, se
reinicie el worker o se cierre el navegador.

La conexión **no se mantiene abierta**. Cada llamada es corta y acotada:

```text
Laravel ──(SSM SendCommand | SSH)──► mwt-agent launch  ──► systemd-run (unidad mwt-op-<uuid>)
   ▲                                                         │
   └──(llamadas periódicas: status / tail)◄── archivos durables en /var/lib/mwt-agent/ops/<uuid>
```

El proceso vive en el servidor bajo systemd; la web vuelve a conectarse y lee
su estado. Perder la conexión significa "estado desconocido", nunca "falló".

## Cortes

| Corte | Alcance                                                                                 | Estado    |
| ----- | --------------------------------------------------------------------------------------- | --------- |
| B1    | Transportes SSM/SSH, agente remoto, instalación verificada, prueba de conexión          | PR #11    |
| B2    | `RemoteOperation` con proveedor `remote-agent`: lanzar, observar, reconciliar, cancelar | PR B2     |
| B3    | UI: conexión por servidor en el wizard, prueba de conexión, estado de operaciones       | Pendiente |
| B4    | `SecretStore` en AWS (Secrets Manager / Parameter Store) y prueba en EC2 real           | Pendiente |

## B1: qué se implementó

### Agente remoto `remote-agent/mwt-agent` (1.0.0)

Script Bash versionado e identificado por SHA-256. Subcomandos cerrados, una
respuesta JSON `mwt-agent.v1` por llamada y códigos de error cerrados (nunca
texto libre):

| Subcomando                                    | Función                                                                                  |
| --------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `version`                                     | Versión, SHA-256 propio y modo de lanzamiento disponible                                 |
| `probe`                                       | SO, kernel, CPU, memoria, systemd, root, Bash, disco, PHP, Docker, pwsh, AWS CLI         |
| `launch <uuid> <comando> [k=v]`               | Lanza una operación **registrada** como unidad systemd transitoria; idempotente por UUID |
| `status <uuid>`                               | `launch.json`, `run.json`, `exit.json`, heartbeat, estado de la unidad y tamaños de logs |
| `tail <uuid> <stdout\|stderr> <offset> <max>` | Lectura incremental acotada (≤16 KiB) en base64                                          |
| `cancel <uuid>`                               | SIGTERM a la unidad; queda registrado en `cancel.json`                                   |

Solo hay operaciones registradas de diagnóstico: `diagnostic.sleep`,
`diagnostic.fail` y `diagnostic.lines` (esta última desde B2). El Recolector y el Consolidador se registrarán en las
etapas C y D con el mismo mecanismo. El agente rechaza comandos no registrados,
parámetros duplicados o fuera de patrón y UUID inválidos.

Sin systemd y root, el agente se niega a lanzar (`launcher_unavailable`). El
modo `setsid/nohup` existe únicamente para la suite de pruebas
(`MWT_AGENT_ALLOW_NOHUP=1`).

### Transportes (`app/Domain/Remote/Transports`)

- **SSM** (`SsmRemoteTransport`): `AWS-RunShellScript` con el script codificado
  en base64; espera la invocación con plazo y la cancela si lo excede.
  Credenciales: cadena por defecto del SDK (rol IAM de la instancia web). La
  conexión SSM rechaza una credencial almacenada.
- **SSH** (`SshRemoteTransport`): OpenSSH con `BatchMode`, `StrictHostKeyChecking=yes`
  y la llave del host **fijada en la configuración**; la llave privada se obtiene
  por referencia (`SecretProvider`), existe en disco con `0600` solo durante la
  llamada y se borra en `finally`. Soporta `sudo -n`.
- **Local** (`LocalBashRemoteTransport`): solo con `APP_ENV=testing` y
  `toolkit.remote.local_transport`; ejecuta los mismos scripts con bash local.

Los scripts los construye el backend a partir de plantillas fijas con
argumentos validados y escapados. Ningún dato del frontend llega al shell.

### Dominio

- `RemoteConnectionConfiguration`: esquema `remote-connection.v1`; rechaza campos
  desconocidos, hosts con esquema/usuario/opciones, puertos inválidos y llaves
  de host malformadas. Se revalida en cada uso.
- `RemoteServerConnections`: una conexión `primary` por servidor; cambiarla
  vuelve a `UNTESTED` y descarta la evidencia anterior. Auditoría sin la
  referencia de la llave.
- `InstallRemoteAgent`: acción explícita y auditada. Copia, verifica SHA-256 en
  el servidor y solo entonces mueve el agente a `/opt/mwt-agent/<versión>/`.
- `TestRemoteConnection`: preflight real (transporte, agente y hash, launcher
  durable, Bash ≥ 4, espacio libre). No instala ni modifica nada. Persiste la
  evidencia en `servers.metadata.remote_probe` y el estado `VALID`/`INVALID`.
- Comando `php artisan remote:connection {configure|install-agent|test} <server_uuid>`
  para operar contra una EC2 mientras se construye la UI (B3).

Todo está detrás de `TOOL_REMOTE_AGENT_ENABLED=false`.

## B2: operaciones remotas durables

`RemoteAgentOperations` crea `RemoteOperation` con `provider_key=remote-agent`,
`host_id` = UUID del servidor y `runtime_key=mwt-agent-v1`. El coordinador local
nunca las procesa: el scheduler (`executions:reconcile-remote-operations`) y el
job heredado las enrutan a `ReconcileRemoteAgentOperation`.

| Paso                  | Comportamiento                                                                                                                                                                                                                                                                   |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lanzar                | Exige conexión `VALID` del mismo proyecto y una operación del `RemoteCommandRegistry` (espejo del agente). Idempotente por `execution + idempotency_key`; el hash fija servidor, versión/SHA del agente, comando y parámetros. Registra la reclamación antes de llamar.          |
| Respuesta perdida     | La operación queda `UNREACHABLE/UNKNOWN` sin `process_id`. La siguiente observación lee `status`: si el agente la lanzó, adopta su identidad; si responde `operation_not_found`, repite `launch` con el **mismo UUID** (el agente es idempotente). Nunca hay un segundo proceso. |
| Observar              | `status` cada 15 s: `CONNECTED/RUNNING` con heartbeat; `DEGRADED` si el heartbeat tiene más de 90 s según el reloj del servidor. Logs completos de stdout/stderr en `execution_logs`, censurados, con offset y líneas confirmados en la misma transacción.                       |
| Contacto perdido      | `UNREACHABLE/UNKNOWN` con backoff 15 s → 900 s. Tras 8 intentos marca intervención manual y audita, **pero sigue consultando**: el proceso puede seguir vivo en el servidor. Al recuperar contacto vuelve a `CONNECTED/RUNNING`.                                                 |
| Terminar              | Solo `exit.json` con el proceso ya terminado cierra la operación: `SUCCEEDED` (0), `FAILED` (≠0) o `CANCELLED` (cancelación solicitada).                                                                                                                                         |
| Resultado desconocido | Proceso desaparecido sin `exit.json`, estado remoto borrado o evidencia de lanzamiento ajena → `TERMINATED/UNKNOWN` con intervención manual. Nunca se infiere éxito.                                                                                                             |
| Rechazo definitivo    | `launcher_unavailable`, comando o parámetros inválidos → `TERMINATED/FAILED` sin reintentos.                                                                                                                                                                                     |
| Cancelar              | Solo operaciones cancelables con lanzamiento confirmado. La intención se guarda antes de llamar; si la llamada se pierde, la reconciliación repite `cancel` hasta ver la salida.                                                                                                 |

Corrección asociada: las escrituras devuelven el modelo releído de PostgreSQL. Con
`APP_TIMEZONE=America/Bogota`, un timestamp UTC asignado en memoria se
reinterpretaba en la zona local (5 h de desfase) hasta releerlo.

## Requisitos en AWS para probar B1

- Instancia con SSM Agent registrado (aparece en _Fleet Manager_) y rol con
  `AmazonSSMManagedInstanceCore`.
- La identidad del servidor web con permiso `ssm:SendCommand` (documento
  `AWS-RunShellScript`, limitado a las instancias del proyecto),
  `ssm:GetCommandInvocation` y `ssm:CancelCommand`.

```bash
php artisan remote:connection configure <server_uuid> --type=aws-ssm --instance-id=i-0123456789abcdef0 --region=us-east-1
php artisan remote:connection install-agent <server_uuid>
php artisan remote:connection test <server_uuid>
```

## Pruebas

- Unitarias: validación de configuración, SSM con `MockHandler` del SDK, SSH con un
  binario simulado (opciones, host key fijada, permisos y borrado de la llave).
- Integración: ciclo de vida real del agente con bash local (instalación verificada,
  lanzamiento idempotente, supervivencia tras terminar la llamada, heartbeat,
  cancelación con código 143, código de salida y logs durables, rechazos).
- Dominio con PostgreSQL: feature flag, evidencia y auditoría, agente ausente o
  desactualizado, servidor inaccesible, launcher no durable, reconfiguración.

## Límites conocidos de B1 y B2

- Las operaciones remotas todavía no gobiernan el estado de la `Execution` ni la UI: eso llega con
  los adaptadores del Recolector (etapa C) y del Consolidador (etapa D) y con B3.
- La conciliación de logs guarda líneas completas; un archivo truncado o rotado en el servidor no
  se detecta todavía.
- No hay UI (B3) ni `SecretStore` en AWS (B4); SSH usa el `SecretProvider` LAB.
- No se ha probado contra una EC2 real; requiere credenciales e instancia.
