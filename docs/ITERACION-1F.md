# Iteración 1F — Verificación y cierre

Fecha: 4 de septiembre de 2026. Iteración correctiva cerrada el 11 de
septiembre de 2026.

Rama: `codex/1f-verificacion-cierre`.

Base: `origin/main` en
`a575536b2c67b6963abe7ddd43aa3b2af752a9bc`, merge del PR #4. La base contiene
el cierre exigido de 1E, incluido
`88f9185d351eb562a5ea85c6df9618350f282eae`, y su workflow de GitHub Actions
finalizó correctamente antes de crear la rama.

## Alcance entregado

1F cierra la ejecución simulada iniciada en 1D y ampliada en 1E. El
procesamiento satisfactorio ya no queda detenido al 50 %: persiste y despacha
una verificación inicial, construye una previsualización académica determinista,
permite guardar sólo operaciones acotadas, revalida la versión exacta dentro de
la misma `Execution` y produce cuatro artefactos antes de declarar el cierre.

La transición nominal implementada es:

```text
RUNNING
→ VERIFYING
→ REVIEW
→ VERIFYING
→ REVIEW
→ COMPLETED
```

También se conserva la cancelación cooperativa desde una verificación activa:

```text
VERIFYING → CANCELLING → CANCELLED
```

`COMPLETED` es terminal. En ese estado la configuración, instancias,
propuestas, validaciones, conflictos, reanudaciones, cancelaciones, nuevas
ejecuciones, verificaciones y artefactos quedan protegidos por interfaz,
policies, servicios de dominio y PostgreSQL.

## Arquitectura de verificación

La frontera exitosa de `FakeExecutionProvider` crea un comando `VALIDATE`, una
verificación versionada y el evento funcional correspondiente antes de
despachar `RunExecutionUnit`. `ProcessSimulatedVerification` reclama el comando
con el lease compartido, vuelve a bloquear Project, Execution y Command, y
comprueba identidad, estado, versión y fingerprint antes de escribir.

El resultado tiene contrato estructurado: clave, estado, aprobación, severidad,
resumen, checks, mensajes, datos observados, versión de propuestas, fingerprint
y fecha. El modo determinista aprueba el estado normal y rechaza nombres
marcados para el escenario correctivo. Tanto aprobación como rechazo regresan
a `REVIEW`; únicamente una aprobación vigente permite finalizar.

Los eventos continúan el orden aprobado en 1E:

```text
servicio de dominio
→ PostgreSQL y commit
→ distribución por sesión autorizada
→ Reverb
→ catch-up/polling de React
```

Ningún evento se transmite antes del commit. Una recarga reconstruye ejecución,
revisión, árbol, verificaciones, propuestas y eventos exclusivamente desde
PostgreSQL.

## Previsualización y propuestas académicas

`AcademicPreview` genera y persiste un snapshot inicial diferente para
Recolectar, Consolidar e Integrar. Cada categoría y curso usa una clave estable;
los índices de arreglo no participan en identidad, concurrencia ni
persistencia. El presentador expone nombre corto, nombre visible, ubicación
actual y ubicación propuesta.

El cliente nunca envía un árbol completo. `ProposeAcademicChange` acepta
exclusivamente:

- `RENAME_CATEGORY`;
- `MOVE_CATEGORY`;
- `MOVE_COURSE`;
- `CHANGE_VISIBLE_NAME`.

El backend vuelve a aplicar la operación sobre el snapshot persistido y valida
tipo y existencia del nodo, destino, autorreferencia, ciclos, ubicación válida,
longitud y contenido del nombre, identidad duplicada, colisiones, versión y
fingerprint base. Cada propuesta agrega una fila de historial con valor
anterior, valor nuevo, actor, fecha, versión y estado; no sobrescribe la
propuesta anterior.

Guardar una propuesta incrementa `proposal_version`, recalcula
`review_fingerprint` e invalida las marcas de validación final. La validación
posterior conserva la misma `Execution` y guarda la versión y fingerprint
exactos que revisó.

## Idempotencia y concurrencia

Proponer, validar, finalizar y registrar una descarga usan llaves de
idempotencia en su ámbito. `IdempotencyReceipt` conserva de forma append-only
el actor, la acción, el recurso, el ámbito, la llave, el hash del payload y el
resultado lógico. Validar y finalizar persisten además `ExecutionCommand`, hash
de solicitud y estado antes del despacho posterior al commit.

Una llave repetida con el mismo contenido devuelve el resultado ya existente;
la misma llave con contenido distinto devuelve conflicto. Si dos llaves
distintas pierden una carrera por el mismo comando lógico, ambas quedan
registradas contra el resultado ganador. Por ello una llave aceptada no puede
reutilizarse silenciosamente contra una versión posterior. Los locks mantienen
el orden Project, Execution, asignación y entidad específica. Con ellos se
impiden validaciones simultáneas, propuestas durante `VERIFYING`, dos cierres,
el cierre de una versión obsoleta y respuestas tardías sobre otra ejecución.

Las pruebas multiproceso abren conexiones PostgreSQL independientes detrás de
una barrera. Las carreras de validación, finalización y propuestas producen un
solo efecto o un conflicto de versión controlado. La secuencia concurrente de
eventos conserva unicidad y orden.

## Finalización y artefactos

`RequestExecutionFinalization` comprueba `REVIEW`, validación aprobada vigente,
misma Execution, versión y fingerprint exactos, ausencia de otros comandos
pendientes y permisos actuales. Después persiste un comando `FINALIZE` y lo
despacha tras el commit.

La solicitud crea atómicamente el comando y su fila de
`ExecutionFinalization`. `ProcessExecutionFinalization` ejecuta una sola unidad
acotada por job y persiste antes de redispatchar la siguiente. Las etapas son
`PREPARE`, `EXPORT_LOGS`, `EXPORT_EVENTS`, `GENERATE_REPORTS`,
`VERIFY_STAGING`, `PROMOTE_ARTIFACTS`, `FINAL_SUMMARY`, `PROMOTE_SUMMARY`,
`COMMIT` y `COMPLETED`. La fila conserva cursores de logs y eventos, estado
SHA-256 reanudable, offset de verificación, temporales, artefactos verificados y
promovidos, propietario y expiración del lease.

`GenerateFinalArtifacts` y el contrato de streams de `ArtifactStorage`
producen estos cuatro contenidos:

1. `JSON_REPORT`;
2. `VERIFICATION_REPORT`;
3. `LOG_EXPORT`;
4. `FINAL_SUMMARY`.

`LocalArtifactStorage` sólo acepta claves relativas generadas por el servidor,
rechaza rutas absolutas, traversal y enlaces simbólicos, y subdivide cualquier
chunk del productor antes de cada `fwrite`: ninguna escritura física supera
65.536 bytes, incluso con strings UTF-8 de varios cientos de KiB. Logs y eventos
se consultan por lotes configurables de 200 filas y se anexan al JSON mediante
un cursor confirmado. Si el proceso cae después de escribir y antes del commit,
el reintento trunca los bytes no confirmados hasta el tamaño persistido y
continúa sin duplicar registros.

La verificación también es incremental: cada job consume como máximo 1 MiB y
cada lectura se limita a 64 KiB; offset y estado SHA-256 quedan en PostgreSQL.
Por eso ni la exportación, ni la verificación, ni la promoción dependen del
tamaño total de un artefacto. Los límites operativos se exponen en
`FINALIZATION_RECORDS_PER_JOB` y
`FINALIZATION_VERIFICATION_BYTES_PER_JOB`.

La exportación aplica redacción estructurada y recursiva a todos los artefactos
JSON. Reconoce objetos y arreglos JSON completos aunque estén almacenados como
string, fragmentos JSON, encabezados `Authorization`, `Proxy-Authorization`,
`Cookie` y `Set-Cookie` sin depender de capitalización o espacios, credenciales
incrustadas en URI y claves exactas relacionadas con passwords, secretos,
tokens, `APP_KEY`, claves privadas y `resume_token`. La coincidencia de claves
está acotada para no borrar valores inocentes como `tokenizer`, `secretary` o
`monkey`.

Cada reclamación escribe en staging privado por Execution, command y
`lease_owner`. Sólo el lease vigente puede avanzar cursores o promover; un
propietario vencido es rechazado incluso si despierta tarde. La promoción local
usa creación atómica sin reemplazo y se registra por artefacto, de modo que un
reintento reconoce lo ya promovido. Sólo después de comprobar los cuatro
archivos, sus tamaños y checksums, una transacción crea los registros
definitivos y pasa Project y Execution a `COMPLETED`. Un fallo no crea registros
que aparenten validez.

La finalización define el instante oficial cuando todos los artefactos previos
ya fueron generados, verificados y promovidos y se construye el resumen final.
Ese `closure_ready_at` se persiste de forma coherente en
`Execution.finished_at`, el step final, `completion_summary`, auditoría, el
evento `execution.completed`, el estado reanudable y `FINAL_SUMMARY` como
`completed_at`. `finalization_requested_at` conserva la fecha del comando y
`finalization_started_at` el inicio real del procesamiento; ninguno se presenta
como si fuese el cierre.

`DownloadArtifact` vuelve a autorizar al actor, comprueba la relación exacta
Project → Execution → Artifact, existencia, tamaño y SHA-256, y registra la
descarga idempotente. Abre el archivo una sola vez, verifica ese mismo handle,
lo rebobina y lo entrega al `StreamedResponse`; cualquier ruta de error o fin de
respuesta lo cierra. Un archivo faltante devuelve 410; uno alterado devuelve 409. Cambiar identificadores de la URL no cruza el ámbito de route model
binding.

`FinalizationGarbageCollector` y el comando
`artifacts:cleanup-finalization` eliminan staging antiguo y finales huérfanos de
forma repetible. La antigüedad conservadora predeterminada es 86.400 segundos,
configurable con `FINALIZATION_CLEANUP_MINIMUM_AGE_SECONDS` o
`--minimum-age`. Siempre se protegen artefactos referenciados, temporales y
finales persistidos por un cierre activo, el prefijo final activo y el staging
del lease no vencido.

El worker de Compose se ejecuta como `www-data`. Esto garantiza que los
directorios privados creados por Flysystem sean legibles por PHP-FPM sin
ampliar sus permisos. Una prueba cruzada en el stack aislado confirmó que el
worker (UID 33) escribió el archivo, el proceso web (UID 33) leyó los mismos
bytes y SHA-256, y luego lo eliminó.

## Permisos y modo consulta

ADMIN dispone de control global. Un OPERATOR sólo puede proponer, validar y
finalizar si está asignado. Un AUDITOR asignado puede consultar el árbol,
historial, verificaciones, resumen y descargar artefactos, pero nunca puede
mutar la ejecución. Un usuario inactivo o no asignado pierde el acceso.

Confirmar la contraseña sólo renueva el permiso temporal de modificación; no
cambia el rol ni la asignación. En `COMPLETED`, React no monta controles de
acción y `ExecutionActions` también retorna `null` como defensa. El backend y
los triggers siguen siendo la autoridad si una petición se fabrica fuera de la
interfaz.

## Seguridad y persistencia PostgreSQL

La migración `2026_09_04_010000_add_iteration_1f_verification_closure.php`
incorpora:

- versión, fingerprint validado y datos de finalización en `executions`;
- resultados de verificación versionados;
- `academic_snapshots`, `academic_proposals` y `artifact_downloads`;
- comando `PROPOSE` y estado activo `VERIFYING`;
- índice parcial de ejecución activa actualizado;
- unicidad parcial de los cuatro artefactos finales;
- constraints de versión, fingerprints, tamaños y SHA-256;
- triggers append-only y de pertenencia entre ejecuciones;
- ampliación de los triggers de sólo lectura para `COMPLETED`.

La migración `2026_09_05_010000_add_idempotency_receipts.php` incorpora el
registro durable de llaves aceptadas y hace backfill de comandos y descargas 1F
ya existentes. PostgreSQL protege sus hashes, estados HTTP e inmutabilidad.

La migración `2026_09_06_010000_add_resumable_finalizations.php` incorpora el
estado durable de finalización, sus cursores, lease reflejado, manifiestos de
temporales/artefactos, verificación SHA-256 reanudable y los instantes separados
de inicio, preparación del cierre y cierre confirmado. Constraints de etapa,
cursores y terminalidad, además del trigger de sólo lectura, preservan el
contrato aun ante escrituras directas.

El upgrade soporta filas 1E existentes y artefactos legacy. El rollback 1F → 1E
es transaccional, retira primero comandos `PROPOSE` incompatibles, restaura el
constraint de tipos y los triggers 1E, y permite reaplicar 1F. La pérdida de
propuestas, descargas y verificaciones creadas por 1F es deliberada: esas filas
no tienen una representación íntegra en el esquema 1E. Las verificaciones 1E
migradas originalmente se conservan.

## Iteración correctiva

Las regresiones se ejecutaron primero contra
`c68a25a176a86d9f55c48a0878fff7c446689124` y reprodujeron los seis defectos:

1. los valores Bearer, Basic, Proxy-Authorization, cookies y credenciales URI
   permanecían en los bytes de `LOG_EXPORT`;
2. el contrato sólo aceptaba strings completos y generación, verificación y
   descarga materializaban el contenido completo;
3. dos workers compartían rutas finales y el cleanup obsoleto podía borrar el
   resultado posterior;
4. un `PROPOSE` real hacía fallar el rollback al restaurar el constraint 1E;
5. la llave distinta que perdía una carrera de validación no quedaba persistida
   y podía reutilizarse con otra versión;
6. `completed_at` copiaba la creación del comando `FINALIZE` en lugar del cierre
   oficial.

Las causas fueron, respectivamente, una redacción genérica insuficiente, un
contrato de almacenamiento basado en strings, ausencia de propiedad privada de
staging, restauración del constraint antes de depurar datos incompatibles,
idempotencia ligada únicamente al comando ganador y reutilización semántica de
`created_at`. Las correcciones descritas en las secciones anteriores cubren
cada causa y cuentan con regresiones que inspeccionan bytes reales, instrumentan
el tamaño de chunks, simulan el relevo de lease sobre PostgreSQL, usan datos 1F
antes del rollback, reutilizan la llave perdedora tras una nueva propuesta y
controlan una demora de quince minutos antes del procesamiento.

## Corrección final reanudable

La segunda batería de regresión se ejecutó primero contra
`b68e55b8d33e62ecbece4dfdc93a73a8e9f2f541`, sin modificar la base de la rama,
y reprodujo seis defectos adicionales:

1. secretos dentro de strings que contenían JSON completo o fragmentos JSON
   todavía llegaban a `LOG_EXPORT`;
2. un productor podía entregar 560.112 bytes y provocar un `fwrite` físico del
   mismo tamaño, aunque el consumidor leyera después en bloques pequeños;
3. el primer job de finalización hacía todo el trabajo y cambiaba `REVIEW` a
   `COMPLETED`, sin cursores ni etapas durables;
4. la descarga abría una vez para verificar y otra vez para responder, dejando
   una ventana TOCTOU entre ambos handles;
5. `completed_at` podía reflejar el inicio de generación y no el instante en que
   el cierre quedaba íntegro;
6. no existía una operación segura para retirar staging abandonado y finales
   huérfanos.

Las pruebas correctivas obligan a que el primer job sólo prepare el estado,
fuerzan múltiples lotes y etapas, interrumpen un append después de escribir
bytes sin commit, expiran y relevan el lease, despiertan al propietario viejo,
comparan cada representación temporal y ejecutan el recolector dos veces. La
implementación conserva los cursores confirmados, trunca el append parcial al
reintentar, no duplica logs, eventos, artefactos ni el evento terminal, y sólo
publica `COMPLETED` en la transacción final.

## Recorrido real de navegador

El recorrido se hizo contra la aplicación de Docker Compose con PostgreSQL,
Redis Queue y Reverb reales:

- login de ADMIN, creación de `QA 1F — Verificación y cierre`, wizard,
  preflight y confirmación;
- worker detenido para observar `QUEUED`, luego reanudado para observar
  `RUNNING` y la frontera de verificación;
- Reverb detenido: la insignia cambió a `Recuperación activa` y el estado pasó
  a `RUNNING` por polling; al levantar Reverb volvió a `Tiempo real conectado`
  después del backoff, sin recarga;
- llegada a `REVIEW`, render del árbol y rechazo backend de una colisión de
  nombre;
- propuesta válida con marcador determinista, doble clic en validar y un solo
  comando; el replay informó que no duplicó efectos;
- recarga completa durante `VERIFYING`, con reconstrucción de eventos y árbol;
- validación rechazada, explicación del check fallido, corrección mediante una
  nueva propuesta y revalidación aprobada dentro de la misma Execution;
- expiración controlada únicamente de `auth.password_confirmed_at`, apertura
  del modal en la misma pantalla, confirmación y reintento de la finalización
  original;
- doble clic en finalizar, un solo comando y mensaje de replay sin efectos
  duplicados;
- `COMPLETED` al 100 %, cuatro artefactos, resumen y sólo lectura;
- descarga correcta como ADMIN y como AUDITOR asignado, ambas auditadas;
- URL con UUID de otro proyecto y artefacto real: 404;
- vista de AUDITOR sin formulario de propuesta ni controles de ejecución.

El recorrido detectó y corrigió dos problemas antes del cierre. Las
actualizaciones en vivo podían conservar tarjetas de acción antiguas; se
eliminó la clave dinámica del componente y se añadió una frontera explícita
para no renderizarlo en `COMPLETED`. La verificación posterior al rebuild y dos
ciclos de polling no volvió a mostrar controles. Además, el worker creaba
directorios privados como root; ahora corre como `www-data`, y la descarga real
pasó desde los dos roles autorizados.

Los artefactos del recorrido final fueron:

| Tipo                  |     Tamaño | SHA-256                                                            |
| --------------------- | ---------: | ------------------------------------------------------------------ |
| `JSON_REPORT`         | 1610 bytes | `c7077309dee6c0d343c421df41ad1d515284e919c4f0c782e18b0e07322c66d6` |
| `VERIFICATION_REPORT` | 1704 bytes | `ab82846701483116a23157c266e1edb96cbd9b169275731d15df1087759289bf` |
| `LOG_EXPORT`          | 5947 bytes | `b628916f2c0447471401f6a44c09fb4b95259fef87af8cefb1abcc28ce0706d7` |
| `FINAL_SUMMARY`       |  682 bytes | `d267c7f70e3cfc447fdbbd934de29457a55dc2399156acde99e7a1870e99c40e` |

## Validación automatizada

La aceptación destructiva se ejecutó sólo sobre PostgreSQL efímero y
contenedores con prefijo `mt1f-`. La base de desarrollo existente no se eliminó
ni se recreó.

- `docker compose config --quiet`: aprobado.
- Imagen final `moodle-toolkit-app:local`: reconstruida desde el árbol
  correctivo con todas las dependencias fijadas.
- Servicios aislados: PostgreSQL, PostgreSQL de pruebas, Redis, Mailpit, app,
  queue-worker, scheduler, Reverb, Vite y Nginx respondieron a sus healthchecks.
- PostgreSQL desde cero: 15 migraciones, incluida la nueva persistencia
  reanudable 1F.
- Suite PHP: los 221 casos fueron ejercitados localmente; todos quedaron verdes
  al combinar la ejecución completa y las particiones exigentes descritas
  abajo. La ejecución integrada definitiva corresponde al CI del SHA publicado.
- Batería específica 1F y SHA-256 reanudable: 19 pruebas, 381 aserciones.
- Upgrade/rollback/reaplicación 1F después de uso real: 1 prueba, 45
  aserciones.
- Concurrencia PostgreSQL multiproceso y relevo de lease: 11 pruebas, 182
  aserciones.
- Reverb y revocación real de WebSocket: 2 pruebas, 23 aserciones.
- Casos heredados 1E: 11 pruebas, 98 aserciones.
- Transiciones: 15 pruebas, 45 aserciones.
- Vitest: 1 archivo, 5 pruebas.
- Pint: 218 archivos.
- Larastan/PHPStan: configuración completa en modo secuencial, sin errores.
- TypeScript: sin errores.
- ESLint: sin warnings ni errores.
- Vite Plus: 86 archivos formateados y 72 sin diagnósticos.
- Build de producción: 2319 módulos transformados.
- `git diff --check`: sin errores.
- `BaseLine/`: 131 archivos, SHA-256 canónico
  `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`.
- Escritura en `BaseLine/`: rechazada por filesystem read-only desde `app`,
  `queue-worker`, `scheduler`, `reverb`, `vite` y `nginx`.

La VM Podman disponible localmente tiene 898 MiB y no tiene swap. Por ello los
diez servicios se validaron en grupos y las seis conexiones PHP multiproceso se
ejecutaron con Reverb detenido; mantener ambos grupos simultáneos provoca
presión de memoria externa al repositorio. No se aumentó el timeout de 120
segundos ni se relajaron asserts. GitHub Actions ejecuta el stack y la suite
integrados sobre el SHA exacto de publicación.

## Decisiones y limitaciones

Toda la información académica, las verificaciones y los artefactos son
simulados y deterministas. No se ejecutó ni modificó `BaseLine/`. No se añadió
SSH, SFTP, S3, SSM, Secrets Manager, procesos remotos, edición de Moodle ni
integración real con Recolector, Consolidador o Integrador. Tampoco se añadió
Playwright como infraestructura del repositorio.

Los artefactos usan el almacenamiento local compartido por los servicios del
deployment. Una futura topología multinodo necesitará sustituir el binding de
`ArtifactStorage` por almacenamiento compartido durable, conservando el mismo
contrato, integridad y autorización. Esa sustitución y toda supervisión de
procesos remotos pertenecen a fases posteriores: no se inició trabajo de 1G.
