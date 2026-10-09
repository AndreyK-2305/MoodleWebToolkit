# Iteración 3 — Recolector real en COLLECT LAB

## Base, alcance y estado

Rama `codex/3-recolector-real`, creada desde `main` en
`05f858bde44c4162bbec4a8710171b4b42d285f0`, después del merge del
[PR #8](https://github.com/AndreyK-2305/MoodleWebToolkit/pull/8).
Contiene el ancestro `d330fcc2e5c3cd2d24d1836977f47a1380e5a518` y la
[CI de la base](https://github.com/AndreyK-2305/MoodleWebToolkit/actions/runs/37813277514)
aprobó. Este informe sustituye las notas provisionales de los cortes 1–18.

El recorrido real de navegador ya está acreditado en Moodle sintético. La
estabilización final y la publicación permanecen en validación. El cierre exige
las puertas completas y la CI del SHA publicado; los resultados locales de un
working tree modificado se identifican como candidatos, no como ese SHA final.

El alcance es exclusivamente 3A–3H. Fake sigue siendo el modo predeterminado.
`TOOL_RECOLECTOR_742_ENABLED` y `TOOL_LOCAL_RUNNER_ENABLED` siguen en `false` por
defecto; el catálogo conserva Recolector `LABORATORY`, Consolidador `BLOCKED` e
Integrador `INCOMPATIBLE`. No se implementó Iteración 4 ni se conectó Moodle
institucional o AWS.

## Distribución y contrato comprobado

| Identidad                    | Valor                                                              |
| ---------------------------- | ------------------------------------------------------------------ |
| Recolector                   | `7.4.2-linux`                                                      |
| Distribución                 | Árbol `BaseLine/Recolector/Recolector-v7.4.2`; 29 archivos         |
| Manifiesto                   | `FILES.sha256`, 28 entradas verificadas                            |
| SHA-256 canónico del árbol   | `4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e` |
| BaseLine completa            | 423 archivos                                                       |
| SHA-256 canónico de BaseLine | `d2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221` |

No existe un ZIP original de distribución: se conserva la identidad del árbol.
El contrato inspeccionado admite configuración Moodle, workers, paquete, salida,
temporal y opciones de reutilización. `scripts/source-export.php` permite ejecución
no interactiva con `scope=lab`; el bridge usa ese contrato desde la copia verificada.
El estado tiene schema `1.0`, productor, `source_id`, etapa, resultado y contadores.
El wrapper humano usa `tee`; su salida cruda no se entrega al navegador.

La secuencia aplicada es BaseLine read-only → verificación → copia al workspace →
segunda verificación → configuración aprobada → comando registrado → observación →
terminación durable → auditoría independiente → artefactos → SourcePackage.
Ningún script de herramienta se ejecuta directamente desde BaseLine.

## 3A–3B: configuración y secretos

`collector-lab.v1` conserva revisiones inmutables, actor, fecha y SHA-256. El perfil
administrado `synthetic-moodle` fija servidor/conexión local, código, moodledata,
base PostgreSQL, identidad sintética, serie Moodle y referencia de credencial.
La interfaz elige el perfil y configura workers, nombre lógico del paquete,
capacidad y margen; no recibe rutas físicas, comandos ni valores de credenciales.
URLs con credenciales y rutas fuera del ámbito autorizado se rechazan.

Guardar una revisión invalida preflight y confirmación. La confirmación fija la
revisión y su fingerprint. Antes del lanzamiento se conserva además
`collector-runtime.v1` aprobado y ligado a Execution, distribución, PHP 8.3,
perfil, cuota, actor y referencias versionadas. PostgreSQL impide sustituir las
revisiones, el binding, la aprobación y la auditoría por actualizaciones directas.

`SecretProvider` ofrece una implementación de testing y otra de archivo LAB.
LAB exige archivo regular, un enlace, permisos `0600` y referencia/versionado
opacos; los consumidores reciben el valor únicamente mediante un callback del
backend. La rotación utiliza una nueva versión de referencia. Los servicios de
aplicación montan las referencias de laboratorio como solo lectura.

La configuración Moodle privada se materializa en `input/moodle-runtime.php`,
con `0600`, y se elimina en `finally`. El JSON aprobado de `state` contiene
referencias, nunca el valor. Argv, hashes de comandos, eventos, logs, auditoría,
evidencia del runner, artefactos y props de Inertia no reciben la credencial.
SIGKILL no ejecuta finally: un residuo solo se retira después de acreditar que
no queda proceso o supervisor de la operación. AWS Secrets Manager no se habilita.

## Preflight real

El preflight comprueba catálogo y flags, versión, árbol/manifiesto, capabilities,
entrypoint, Linux/Bash, PHP 8.3 y extensiones, permisos y ámbitos, configuración y
referencia de credencial, identidad sintética, acceso a código/moodledata/base,
serie Moodle, cursos/usuarios/OAuth, workers, cuota con margen y espacio disponible.
No inicia una recolección.

Checks y fingerprint se persisten. Un ERROR bloquea; un WARNING requiere
aceptación auditada de todas las advertencias vigentes. Cambiar configuración,
perfil, versión de referencia, distribución o capacidad vuelve obsoleta la
confirmación. El lanzamiento revalida las entradas aprobadas.

## 3C: adaptador, proveedor y duración

Frontend → dominio → `CollectorAdapter` → proveedor local → supervisor → bridge →
Recolector. React opera con acciones y UUID; no construye comandos ni interpreta
el ZIP. La selección del proveedor procede del binding inmutable. Un binding real
incompleto nunca vuelve silenciosamente a Fake.

El único comando real registrado es `collector.742.lab`. Su argv es cerrado:
identidades UUID y SHA de runtime; el ejecutable, bridge y parámetros permitidos
proceden del backend. El host/namespace queda fijado antes de crear el proceso.
Los jobs reales se enrutan a `redis-tool-runs/tool-runs`.

| Política registrada   | Recolector LAB                                           |
| --------------------- | -------------------------------------------------------- |
| Arranque              | 10 segundos                                              |
| Heartbeat             | 10 segundos                                              |
| Inactividad           | 7200 segundos                                            |
| Tiempo de pared       | `null`, sin recorte artificial de 24 horas               |
| Gracia de cancelación | 10 segundos                                              |
| CPU acumulada         | `null`, sin `prlimit --cpu=86400`                        |
| Recursos              | 2 GiB por proceso, 128 procesos, 20 GiB por archivo      |
| Job Laravel           | Máximo 120 segundos; lanzamiento y observación separados |

La política forma parte de la identidad/hash del comando. Cero es inválido.
El supervisor utiliza reloj monotónico; heartbeat no simula actividad de la
herramienta. La ausencia de límite de pared conserva vigilancia de inactividad,
recursos, capacidad y cancelación. Las pruebas de más de 24 horas usan reloj
controlado; no se afirma haber exportado durante un día completo.

La cuota de aplicación se mide periódicamente y antes de escrituras administradas;
no constituye una cuota dura del filesystem para escrituras externas. Se mantiene
una reserva técnica de hasta 64 KiB, con 16 KiB por archivo de evidencia, para poder
acreditar terminación cuando una herramienta consume la cuota. Se detiene el grupo,
se descarta salida adicional y se firman solo bytes realmente persistidos. Un fallo
físico de escritura o sincronización conserva `UNKNOWN` y exige reconciliación.

## 3D: eventos y seguimiento

El bridge publica señales comprobables y mensajes cerrados. `CollectorEventParser`
y `CollectorLogReader` toleran fragmentación, UTF-8 inválido, desconocidos,
duplicados y reinicio de lectura. El cursor durable conserva offset, identidad del
archivo y hash del prefijo; truncación, rotación o alteración bloquean cierre y
conservan evidencia. No se persiste texto crudo pendiente de una línea.

Cursor, evento, unidades y progreso se confirman juntos en PostgreSQL. Después del
commit, la outbox publica a Reverb; el polling recupera eventos por secuencia.
Cada evento conserva Execution, RemoteOperation, secuencia, tipo, paso, severidad,
unidades, mensaje/payload sanitizados y fecha. No se usa tiempo transcurrido para
calcular porcentajes. El progreso global real permanece `null` cuando no hay una
unidad total acreditada, incluso al finalizar.

Nginx resuelve Reverb con el DNS del contenedor, también después de un cambio de IP.
Cerrar navegador, perder Reverb o vencer la sesión no detiene el supervisor.
Modificar/cancelar requiere autorización vigente o reconfirmación de contraseña
dentro de la misma pantalla.

## 3E–3F: compatibilidad y nuevos intentos

Las nuevas recolecciones usan únicamente productor 7.4.2. El inspector y el registro
aceptan paquetes existentes 7.4.1 con contratos reconocidos, mantienen el productor
original y los MBZ, y enriquecen metadata incompleta solo con información verificable.
Versiones desconocidas, hashes/manifiestos alterados y combinaciones incompatibles
de capabilities se bloquean. El fixture legacy valida el contrato 7.4.1; no acredita
haber ejecutado el binario histórico 7.4.1.

La distribución posee checkpoints propios, pero el fingerprint incorpora la ruta
real de configuración. Cambiar de workspace cambia esa entrada: esta integración
no habilita una reanudación entre workspaces sin un contrato seguro acreditado.
`resume=false`, `pause=false`, `fresh_retry=true`, `cancel=true`. No se crean
checkpoints ficticios ni se presenta `--restart` como reanudación.

Tras FAILED/CANCELLED, “Nueva exportación LAB” exige actor autorizado, revisión
vigente, aceptación explícita y preflight real. Crea otra Execution, workspace,
binding, runtime y RemoteOperation, con secuencias desde 1 y linaje inmutable
`retried_from_execution_id`. Conserva el intento anterior y su evidencia.
El contrato de reanudación con checkpoint de Fake sigue separado.

## Artefactos, revisión y SourcePackage

La captura exige `launch.json` y `exit.json` íntegros, hashes de logs, identidad de
PID/grupo/start time/host y ausencia tanto del grupo como del supervisor registrado.
Un PID ausente, exit 0 aislado o un estado TERMINATED en DB no acreditan cierre.

Solo se capturan seis salidas declaradas: ZIP fuente, checksum lateral, manifiesto,
inventario, inventario visual y validación. Se verifican ruta relativa, categoría,
nombre, MIME, tamaño, SHA, sensibilidad, obligatoriedad y propiedad. Se rechazan
traversal, enlaces inseguros, archivos especiales y archivos externos al workspace.
La captura repetida reutiliza la referencia propia verificada; no admite hardlinks
ajenos como artefactos nuevos.

La auditoría independiente comprueba ZIP/MBZ, manifiestos, inventarios, hashes,
productor/capabilities y ausencia de contenido privado. `CollectorPackageAudit`
persiste un snapshot inmutable ligado a operación y Execution. Solo entonces se
registra un SourcePackage `VALID` del mismo proyecto, reutilizable por otra Execution.
La reutilización revalida bytes y estado; no se implementa consumo real por V8.

La interfaz muestra el paquete auditado, productor, schema, hashes, cursos y descargas.
La revisión real no inventa un árbol académico. FINALIZE revalida evidencia, audit,
SourcePackage, seis artefactos y fingerprint; genera cuatro reportes reales en jobs
acotados, termina con diez artefactos y conserva progreso global indeterminado.

## 3G: Moodle sintético y prueba de navegador

El overlay explícito prepara Moodle 4.5.10 en el commit
`b2c2f2a0c5f9a141896df6c322a0b6b29bb77646`, PHP 8.3 aislado y PostgreSQL.
La muestra tiene dos cursos, tres usuarios nologin además de admin/guest, dos páginas,
dos carpetas con archivos pequeños, temas Boost/Classic, proveedor OAuth y vínculo
sintético. No contiene datos institucionales; la preparación descarta salida cruda
y solo conserva códigos de etapa. Dependencias públicas se obtienen al construir
imágenes; las pruebas de ejecución no dependen de Internet.

Los dos casos Playwright, con `retries=0`, acreditan crear/configurar, persistir y
recargar configuración, preflight/aceptación/confirmación, inicio, sesión independiente,
polling, revisión, SourcePackage, descarga con SHA y cierre tras reconfirmación.
El segundo consume capacidad, observa FAILED, crea otro intento con linaje y lo
cancela; repetir su Idempotency-Key no duplica el comando.

## 3H y evidencia de calidad

| Puerta                                         | Evidencia actual                                                                          |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------- |
| PHP completo PostgreSQL                        | 534 pruebas, 6808 aserciones, 0 fallos/errores/omisiones                                  |
| Multiproceso                                   | Lanzamiento concurrente, reconciliadores, cuota concurrente y worker SIGKILL incluidos    |
| LAB real                                       | 3 pruebas; aserciones varían con observaciones idempotentes; sin fallos/omisiones         |
| Contratos del Recolector                       | Cuatro contratos desde copia verificada de 29 archivos/PHP 8.3                            |
| Playwright COLLECT LAB                         | 2/2, retries=0; salud final 12 servicios y teardown PASSED                                |
| Playwright Fake completo                       | 42/42 ejecutadas; los dos casos LAB se ejecutan en su puerta separada; retries=0          |
| Análisis/frontend                              | Pint, PHPStan, Vite Plus, ESLint, TypeScript aplicación/E2E, Vitest 5 y build             |
| Migraciones                                    | Fresh, upgrade con datos, rollback y reapply incluidos en PHP completo                    |
| BaseLine                                       | 423 archivos/hash esperado; prueba de escritura read-only en siete servicios persistentes |
| Reinicio Redis/queue-worker con COLLECT activo | Prueba específica final pendiente                                                         |
| Publicación                                    | CI de SHA final y PR borrador pendientes                                                  |

La cobertura incluye configuración/distribución alteradas, autorización vencida,
ADMIN/OPERATOR asignado/AUDITOR/outsider/inactivo/revocación, idempotencia, secreto
fragmentado, paquete/manifiesto alterados, capacidad insuficiente, truncación de log,
reconciliación y limpieza conservadora. Las pruebas físicas de escritura/sync/exit
siguen rechazando un resultado no comprobable.

Los reportes locales están en `quality-results/` (ignorados por Git). El driver
registra SHA, working tree dirty, métricas, salud y teardown. CI construye imágenes
limpias y conserva `iteration2-validation-<SHA>` para la regresión completa y
`iteration3-lab-validation-<SHA>` para el laboratorio, con JSON/JUnit ligados al
checkout exacto. La lista completa de commits se obtiene con
`git log --reverse 05f858bde44c4162bbec4a8710171b4b42d285f0..HEAD`; la publicación
mantendrá commits normales, push normal y PR borrador hacia main, sin merge.

## Fallos encontrados y desviaciones

- El runtime inicial de aproximadamente 900 MiB terminaba procesos con SIGKILL;
  se amplió WSL a 4 GiB con autorización del usuario.
- Docker Desktop no proporcionó un socket operativo. La validación local usa
  Docker CLI/Compose contra la API de Podman Linux; las imágenes se construyen con
  Podman nativo por una incompatibilidad de cache-from del API. `-SkipBuild` lo
  registra explícitamente. La puerta Docker Engine corresponde a CI.
- El objeto de tag de Moodle no era el commit; la preparación fija y comprueba
  el commit despejado indicado arriba.
- Nginx retenía la IP anterior de Reverb después de un reinicio de Podman; se
  cambió a resolución dinámica con el DNS del contenedor.
- Consumir la cuota bloqueaba también exit evidence. Se agregó reserva técnica
  acotada y se conservó el rechazo ante errores físicos de escritura/sync.
- Una interrupción del transporte local invalidó un candidato de navegador;
  no se contó como prueba aprobada. La repetición completa COLLECT LAB pasó.
- El arnés detenía su coordinador al vencer una unidad y contaminaba los casos
  posteriores. Ahora devuelve un fallo explícito y conserva solo etapas cerradas,
  sin payloads. La regresión completa de navegador pasó; los candidatos fallidos
  se descartaron. La prueba de lanzamiento reconcilia de forma acotada la misma
  operación antes de exigir una identidad RUNNING, sin volver a lanzarla.
- Traces/videos E2E se deshabilitaron porque pueden retener cuerpos con contraseñas
  sintéticas. Se conservan JUnit y capturas de fallo.

## Reproducción, rollback y límites

Con Docker Engine/Compose y PowerShell disponibles, desde el checkout:

```powershell
./tests/Infrastructure/run-quality.ps1 -ProjectName mt1g-it3-fake-check
./tests/Infrastructure/run-quality.ps1 -ProjectName mt1g-it3-lab-check -CollectorLab
```

Cada prefijo debe ser nuevo. El driver genera secretos efímeros, valida Compose,
construye imágenes, verifica salud/fresh/pruebas y retira exclusivamente sus
contenedores, volúmenes y red. No cambia `.env` ni elimina volúmenes de desarrollo.
Para habilitar el catálogo sintético dentro del overlay se ejecuta
`tools:catalog:sync` y después `collector:enable-laboratory`; ambos están incorporados
en la puerta LAB. No habilitar flags reales en un entorno institucional.

Antes de rollback, conservar DB y evidencia y comprobar que no queden operaciones
activas. Cerrar flags impide nuevos lanzamientos; no constituye cancelación de un
proceso existente. La columna/FK histórica de linaje se conserva al retirar su guard
para evitar pérdida de datos; reapply restaura la validación. No borrar workspaces
activos ni evidencia desconocida. Los recursos propios del laboratorio se retiran
por prefijo, sin prune global.

Esta entrega acredita un laboratorio pequeño. No acredita supervivencia al reinicio
completo del contenedor/host, exportaciones institucionales de muchos GiB o duración
real de 24 horas. Sin prueba terminal íntegra se requiere reconciliación manual.
No hay pausa ni checkpoint de reanudación real acreditado. Para Iteración 4 quedan
el adaptador V8, consumo real de SourcePackage, destino/plugins/temas/identidades e
intervenciones académicas; esos trabajos no se iniciaron aquí.
