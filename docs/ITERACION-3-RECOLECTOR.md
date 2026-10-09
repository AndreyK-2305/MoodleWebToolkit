# Iteración 3 — Recolector real en COLLECT LAB

## Base, alcance y estado

Rama `codex/3-recolector-real`, creada desde `main` en
`05f858bde44c4162bbec4a8710171b4b42d285f0`, después del merge del
[PR #8](https://github.com/AndreyK-2305/MoodleWebToolkit/pull/8).
Contiene el ancestro `d330fcc2e5c3cd2d24d1836977f47a1380e5a518` y la
[CI de la base](https://github.com/AndreyK-2305/MoodleWebToolkit/actions/runs/37813277514)
aprobó. Este informe sustituye las notas provisionales de implementación.

**Estado: correcciones de revisión implementadas; puertas completas pendientes.**
Se continúa sobre el [PR #9 borrador](https://github.com/AndreyK-2305/MoodleWebToolkit/pull/9),
sin merge. La revisión del SHA `15870fdb6f92237addbc800238d0bf2ec329f4d8`
obtuvo LAB SUCCESS y regresión general FAILURE: 41 casos de navegador aprobados,
uno fallido y dos LAB omitidos. Los otros tres fallos locales anteriores aprobaron
en esa CI. El fallo restante 423/202 se diagnosticó antes de corregirlo.

Las pruebas dirigidas de la corrección pasaron: 22 pruebas PHP/172 aserciones y
tres ejecuciones aisladas del caso de más de 24 horas, un worker y `retries=0`.
Estos resultados no sustituyen las puertas completas de un checkout limpio ni
los dos jobs de CI del mismo SHA publicado. Los resultados anteriores conservan
su carácter histórico en
[`evidence/it3-review-validation.json`](evidence/it3-review-validation.json).
La identidad definitiva procede de `head_sha` en ambos artifacts del
[PR #9](https://github.com/AndreyK-2305/MoodleWebToolkit/pull/9/checks), evitando
que este documento afirme validar un commit que cambia al escribir su propio SHA.

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
Los 20 GiB son exclusivamente el límite actual del laboratorio sintético; no son
el límite productivo definitivo. Los paquetes institucionales de 53, 247 o
245 GiB y la validación interna/AWS quedan fuera de esta iteración.
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

### Evidencia de acceso al origen

El bridge ya no publica el booleano fijo y ambiguo `source_write=false`.
`collector-source-access.v1` define explícitamente cuatro ámbitos:

| Ámbito        | Semántica y evidencia                                                                                                                                                                                                                                                                          |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Código Moodle | `PROHIBITED_AND_ENFORCED`: SHA-256 canónico de todos los archivos/directorios, tipo, permisos y contenido antes de exportar y después de exportar/auditar; igualdad verificada y montaje read-only observado para cada entrada, también ante mounts anidados, con escritura efectiva denegada. |
| moodledata    | `TEMPORARY_ALLOWED`: el backup oficial necesita temporal, cache, locks y filepool. El resultado y la limpieza quedan `NOT_VERIFIED`; no se afirma inmutabilidad.                                                                                                                               |
| Base Moodle   | `NOT_VERIFIED`: no existe observador de mutaciones SQL; no se declara `VERIFIED_NONE`.                                                                                                                                                                                                         |
| Destino       | `NOT_APPLICABLE`: COLLECT no integra un destino.                                                                                                                                                                                                                                               |

Se rechazan enlaces, hardlinks, archivos especiales, entradas ilegibles o
cambiantes, código escribible, código alterado, evidencia incompleta y el booleano
legacy. Solo salen hashes y conteos agregados, nunca rutas ni contenido Moodle.
El intervalo medido termina después de auditar el paquete; no se atribuye una
medición posterior ficticia al worker. Antes de registrar SourcePackage, el
workflow verifica además que grupo y supervisor terminaron y liga la evidencia
al runtime aprobado; FINALIZE vuelve a validar ese vínculo.

El manifiesto original de la distribución declara `source_write_performed=false`.
BaseLine se conserva intacta: esa declaración del productor se identifica como
`DECLARED_NOT_VERIFIED` y no se usa como prueba de seguridad. La limpieza que
intenta el backup de Moodle tampoco acredita ausencia de cambios en datos/base.

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

| Puerta                        | Estado de las correcciones                                                                                                                    |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP dirigido PostgreSQL/Redis | 22 pruebas, 172 aserciones, 0 fallos; incluye 8 casos de guardas HTTP, FINALIZE sin efectos y concurrencia de dos sesiones del mismo usuario. |
| Temporal aislado              | Tres ejecuciones aprobadas (11,1; 11,8; 9,8 s), un worker, retries=0; 423 antes de confirmar y cierre único después.                          |
| Análisis dirigido             | PHPStan y TypeScript E2E aprobados.                                                                                                           |
| Puertas completas locales     | Pendientes del checkout limpio de corrección.                                                                                                 |
| CI final                      | Deben aprobar ci y collector-lab del mismo head_sha; consultar los dos artifacts del PR 9.                                                    |

La puerta general conserva todas las pruebas PHP, PostgreSQL multiproceso,
upgrade con datos, rollback/reapply, Vitest, Pint, PHPStan, Vite Plus, ESLint,
TypeScript de aplicación/E2E, build y los 42 casos generales Playwright.
Los dos casos LAB se omiten únicamente en general y se ejecutan en su job separado.
Las dos puertas mantienen retries=0 y los mismos timeouts.

La prueba de reinicios inicia una exportación con el worker real y conserva su
identidad registrada. Un CLI exclusivo de testing aplica SIGSTOP/SIGCONT al
grupo verificado durante reinicios de Redis, queue-worker y Reverb. Después
comprueba la misma operación, secuencias continuas, seis artefactos, terminación
íntegra, cero checkpoints, progreso null y SourcePackage VALID. No habilita pausa
para usuarios. La prueba multiproceso SIGKILL del worker sigue incluida.

La inspección de secretos después de reinicios recorre las tablas de aplicación
y archivos del workspace/artefactos. La auditoría del paquete examina contenido
descomprimido. Las aserciones de material privado emiten booleanos y mensajes
cerrados, también ante fallo. El scanner de los dos XML JUnit/Playwright usa el
SecretProvider real, representaciones raw/XML/JSON, streaming 64 KiB con overlap,
límites, identidad de archivos y staging privado retirado. Un reporte rechazado
no se publica; el artifact de fallo/cancelación excluye siempre ambos XML LAB.

Los resultados locales están en quality-results/ (ignorados por Git). Los cortes
anteriores se conservan como históricos en evidence/it3-review-validation.json,
evidence/it3-collector-contracts.json y evidence/it3-collector-resilience.json;
no se confunden con aceptación de esta corrección.

El driver registra SHA, working_tree_dirty, métricas, salud y teardown. CI
construye imágenes desde el checkout exacto y conserva:

- iteration2-validation-<SHA>: regresión general, PHP y Playwright.
- iteration3-lab-validation-<SHA>: LAB, contratos, reinicios y report hygiene.

Ambos deben indicar quality_gates=PASSED, working_tree_dirty=false,
clean_images_built_in_driver=true y teardown=PASSED. Los JSON/JUnit son la fuente
de los conteos definitivos, que pueden variar en aserciones/eventos idempotentes.
El SHA final y los enlaces a ambos jobs se publican en el informe del PR 9 y en
sus checks, ligados al mismo head_sha de los artifacts. No se usa el SHA del
merge sintético de GitHub. Main no se modifica y el PR permanece borrador.

### Resultado de los cuatro escenarios de la revisión anterior

| Caso                  | Diagnóstico y cobertura conservada                                                                                                                                                       |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| INTERVENTION          | El fallo local fue anterior a la decisión, durante login; aprobó en CI 15870fd. Se conserva continuidad de la misma Execution e idempotencia. No se inventa una causa del timeout local. |
| Doble clic            | El fallo local fue timeout de snapshot(), no duplicación demostrada; aprobó en CI 15870fd. Se conserva una Execution y un START.                                                         |
| Autorización caducada | El corte local no llegó a intervención; aprobó en CI 15870fd. El helper nuevo caduca la sesión exacta y conserva el payload y la clave de la acción pendiente.                           |
| Más de 24 horas       | CI mostró 202 en vez de 423. Se reprodujo la carrera del helper SQL con StartSession; el mecanismo HTTP corregido aprobó tres veces aislado. La suite completa sigue siendo obligatoria. |

## Commits y archivos principales

| Corte | Commit    | Resultado                                                  |
| ----- | --------- | ---------------------------------------------------------- |
| 1     | `ad9199e` | Duración y recursos independientes                         |
| 2     | `42bc8a7` | Configuración LAB versionada y secretos efímeros           |
| 3     | `d360469` | Preflight real y pantalla de configuración                 |
| 4     | `9bc9192` | Host/namespace fijado y reconciliación local               |
| 5     | `095fc1f` | Señales reales y progreso sin éxito sintético              |
| 6     | `86de30f` | Runtime inmutable aprobado                                 |
| 7     | `6554736` | PHP compatible aislado                                     |
| 8     | `eefae99` | Bridge y comando LAB cerrado                               |
| 9     | `fe45263` | Moodle sintético y exportación real                        |
| 10    | `bdc79ac` | Auditoría de paquetes y productor legacy                   |
| 11    | `59b224d` | Outbox transaccional de eventos                            |
| 12    | `035a15c` | Observación/cursor durables                                |
| 13    | `d1e4b36` | Terminación verificada y captura idempotente               |
| 14    | `e04bf7c` | Binding y workflow con auditoría                           |
| 15    | `4e0257b` | Routing real y recuperación de observadores                |
| 16    | `f579a39` | Finalización real en jobs acotados                         |
| 17    | `d9ed2c0` | Nueva exportación con linaje inmutable                     |
| 18    | `4add843` | HTTP/UI LAB y revisión de paquetes reales                  |
| 19    | `c624a91` | Navegador real, evidencia de cuota y puertas reproducibles |
| 20    | `259c55e` | Reinicios reales, worker SIGKILL e higiene de secretos     |
| 21    | `44c6194` | Arranque fallido acotado y relojes monotónicos             |
| 22    | `8b7c7a6` | Barrera multiproceso monotónica y diagnóstico cerrado      |

El corte final actualiza esta documentación. La lista íntegra, incluido ese corte,
se obtiene con `git log --reverse 05f858bde44c4162bbec4a8710171b4b42d285f0..HEAD`;
su identidad final se conserva fuera del commit en los artifacts y el informe de entrega.

| Archivo o grupo                                                                                                                                            | Responsabilidad                                                      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `app/Domain/Collector/CollectorConfiguration.php`, `CollectorRuntimeConfiguration.php`                                                                     | Revisiones y aprobación del runtime                                  |
| `app/Domain/Collector/Contracts/SecretProvider.php`, `LabFileSecretProvider.php`, `MoodleConfigurationMaterializer.php`                                    | Referencias opacas y CFG efímero                                     |
| `app/Domain/Collector/CollectorPreflight.php`, `SyntheticMoodleProbe.php`                                                                                  | Checks reales, fingerprint y acceso sintético                        |
| `app/Domain/Tools/CollectorAdapter.php`, `app/Domain/Collector/CollectorExecutionProvider.php`                                                             | Adaptador y despacho por binding                                     |
| `app/Domain/Collector/CollectorRegisteredCommand.php`, `CollectorBridge.php`, `bin/collector-bridge.php`                                                   | Comando registrado y exportación desde copia verificada              |
| `app/Domain/Processes/RegisteredCommandRunner.php`, `RegisteredExecutionPolicy.php`                                                                        | Supervisor, duración, recursos y evidencia terminal                  |
| `app/Domain/Collector/CollectorLogReader.php`, `CollectorEventObserver.php`, `app/Domain/Realtime/ExecutionEventOutboxPublisher.php`                       | Cursor, transacción y publicación después del commit                 |
| `app/Domain/Collector/CollectorWorkflow.php`, `CollectorPackageInspector.php`, `app/Domain/Tools/SourcePackageRegistry.php`                                | Auditoría, captura, SourcePackage y revisión                         |
| `app/Domain/Collector/RetryCollectorExecution.php`                                                                                                         | Nuevo intento y linaje sin checkpoint ficticio                       |
| `database/migrations/2026_10_08_*.php`                                                                                                                     | Revisiones, cursor, identidad de captura, auditoría, outbox y linaje |
| `resources/js/pages/projects/collector.tsx`, `resources/js/components/collector-configuration-form.tsx`, `resources/js/pages/projects/executions/show.tsx` | Configuración, seguimiento, revisión y acciones autorizadas          |
| `compose.collector-lab.yaml`, `docker/collector-lab/`, `docker/php/Dockerfile`                                                                             | Fixture aislado y runtimes compatibles                               |
| `tests/Laboratory/`, `tests/E2E/collector-lab.spec.ts`, `tests/Support/collector-resilience.php`                                                           | Exportación real, navegador y fallos de infraestructura              |
| `tests/Infrastructure/run-quality.ps1`, `.github/workflows/tests.yml`                                                                                      | Puertas completas y artifacts del SHA exacto                         |

## Fallos encontrados y desviaciones

### Diagnóstico previo a la corrección de autorización

El escenario original de más de 24 horas se ejecutó aislado sobre PostgreSQL y
Redis reales, un worker y `retries=0`, sin modificar el helper ni el middleware.
Pasó en dos ejecuciones locales; CI del SHA `15870fd` había fallado con 202 en vez de 423. Por eso una ejecución aprobada no descarta una carrera.

Se reprodujo después la intercalación con `StartSession` real y el helper original
intacto: una consulta cargó una autorización reciente y retuvo el bloqueo Redis
de su sesión; el CLI modificó por SQL el payload y se comprobó que quedaba vencido;
al terminar la consulta, Laravel persistió su snapshot anterior y repuso la
autorización reciente. Todos esos pasos se verificaron mediante resultados
booleanos, sin publicar identificadores de sesión ni timestamps privados.
La evidencia está en
[`evidence/it3-session-race-diagnosis.json`](evidence/it3-session-race-diagnosis.json).

La causa demostrada es que `quality-control.php` evita el bloqueo de sesión HTTP
y modifica todas las sesiones del usuario. FINALIZE sí tiene el middleware de
confirmación; el timestamp restaurado permite que ese middleware acepte la
petición. El mecanismo de caducidad del arnés debe usar la sesión HTTP exacta y
persistirla bajo el bloqueo habitual antes de la siguiente mutación. Este
diagnóstico precede a la corrección; no se cambió la expectativa 423.

### Solución y regresiones de autorización

Se retiró la acción CLI `expire`. Playwright usa ahora
`POST /__quality/expire-action-authorization` desde el mismo contexto HTTP,
cookies y CSRF del caso. Bajo el bloqueo normal de `StartSession`, elimina
`auth.password_confirmed_at`, devuelve 204 vacío después de persistir y acredita
que la consulta sigue autenticada antes de solicitar FINALIZE. No transmite IDs
de sesión ni secretos. Una segunda sesión del mismo usuario no se modifica.

La ruta se registra solo con APP_ENV testing tanto en configuración como en
entorno real, QUALITY_HARNESS=1 y la base PostgreSQL E2E aislada. Una guarda
repite los checks en runtime y devuelve 404 incluso ante un cache de rutas
incorrecto. No se habilita en producción ni se modifica el middleware real.

La prueba concurrente usa dos procesos HTTP y barreras Redis explícitas:
caducar espera a que la consulta termine y ninguna escritura puede restaurar el
timestamp. FINALIZE vencido responde 423 antes de comandos, cambios de estado,
jobs, receipts, eventos, auditoría o artefactos. Contraseña incorrecta responde
422; confirmación correcta responde 200 y permite 202 con exactamente el payload
y el Idempotency-Key pendientes; una repetición devuelve 200 sin duplicar efectos.
Polling, eventos, Reverb y REVIEW siguen disponibles durante la caducidad.
La recuperación mediante Recordarme conserva consulta, nunca autorización.

### Defecto de evidencia y protección de reportes

La evidencia anterior afirmaba source_write=false sin medirlo, aunque Moodle
necesita escritura en moodledata. La corrección protege y mide el código,
documenta las escrituras temporales y mantiene datos/base/limpieza desconocidos
como NOT_VERIFIED. Las pruebas detectan cambios de contenido, permisos,
adición/borrado/rename, mounts RW y contratos favorables sin evidencia.

La revisión también detectó que un fallo antes del scanner podía publicar XML
LAB no inspeccionado. El driver mantiene aprobación únicamente para la invocación
actual, retira los dos reportes no aprobados y conserva el fallo; el workflow
excluye esos XML ante fallo o cancelación aunque el proceso sea interrumpido.

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
- Un arranque sin identidad confirmada dejó al supervisor esperando un hijo que
  aún no había creado su grupo. La nueva prueba detecta esa espera en el código
  anterior y pasa con la corrección: se detiene únicamente el hijo propio antes
  de abrir la compuerta. Arranque y gracia de cancelación usan reloj monotónico.
  La causa del primer arranque no confirmado no se atribuye a esta corrección.
- Una interrupción del transporte local invalidó un candidato de navegador;
  no se contó como prueba aprobada. La repetición completa COLLECT LAB pasó.
- La barrera de una prueba multiproceso falló antes de enviar las solicitudes
  HTTP. Se alineó su presupuesto monotónico de preparación con los 30 segundos
  permitidos al proceso, se añadieron etapas cerradas y se detienen los hijos
  propios antes de desbloquear una preparación fallida. Los 14 casos dirigidos
  pasaron, y el corte PHP completo posterior pasó 535 pruebas/6813 aserciones.
  La causa de la lentitud inicial no quedó demostrada.
- El arnés detenía su coordinador al vencer una unidad y contaminaba los casos
  posteriores. Ahora devuelve un fallo explícito y conserva solo etapas cerradas,
  sin payloads. Los resultados anteriores se conservan como históricos.
  La prueba de lanzamiento reconcilia la misma
  operación antes de exigir una identidad RUNNING, sin volver a lanzarla.
- Traces/videos E2E se deshabilitaron porque pueden retener cuerpos con contraseñas
  sintéticas. Se conservan JUnit y capturas de fallo.
- La entrega anterior se adelantó para revisión con aceptación pendiente. La
  solicitud posterior de corrección exige de nuevo ambas puertas completas y CI
  verde del mismo SHA, sin omitir pruebas ni introducir retries.

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
