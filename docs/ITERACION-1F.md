# Iteración 1F — Verificación y cierre

Fecha: 4 de septiembre de 2026.

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
idempotencia en su ámbito. Validar y finalizar persisten `ExecutionCommand`,
hash de solicitud y estado antes del despacho posterior al commit.

Una llave repetida con el mismo contenido devuelve el resultado ya existente;
la misma llave con contenido distinto devuelve conflicto. Los locks mantienen
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

`ProcessExecutionFinalization` genera primero los cuatro contenidos mediante
`GenerateFinalArtifacts` y el contrato `ArtifactStorage`:

1. `JSON_REPORT`;
2. `VERIFICATION_REPORT`;
3. `LOG_EXPORT`;
4. `FINAL_SUMMARY`.

`LocalArtifactStorage` sólo acepta claves relativas generadas por el servidor,
rechaza rutas absolutas, traversal y enlaces simbólicos, escribe a una clave
temporal, promueve el archivo final y calcula tamaño y SHA-256 sobre los bytes
definitivos. La exportación de logs redacta claves y valores relacionados con
passwords, secretos, tokens, cookies, autorización, `APP_KEY`, claves privadas
y `resume_token`.

Sólo después de comprobar los cuatro archivos, sus tamaños y checksums, una
transacción crea los registros definitivos y pasa Project y Execution a
`COMPLETED`. Un fallo limpia temporales y archivos parciales, no crea registros
que aparenten validez y permite repetir el mismo comando sin duplicados.

`DownloadArtifact` vuelve a autorizar al actor, comprueba la relación exacta
Project → Execution → Artifact, existencia, tamaño y SHA-256, y registra la
descarga idempotente. Un archivo faltante devuelve 410; uno alterado devuelve 409. Cambiar identificadores de la URL no cruza el ámbito de route model
binding.

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

El upgrade soporta filas 1E existentes y artefactos legacy, hace rollback y se
puede reaplicar. Los triggers se retiran sólo dentro del lock transaccional
necesario para el backfill y se reinstalan antes de liberar el esquema.

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

La aceptación destructiva se ejecutó sólo en el proyecto Compose
`moodle-toolkit-1f-validation`, sin puertos publicados y con volúmenes propios.
La base de desarrollo no se eliminó ni se recreó.

- `docker compose config --quiet`: aprobado.
- Servicios aislados: diez contenedores levantados; los servicios con
  healthcheck terminaron saludables.
- PostgreSQL desde cero: 13 migraciones, incluida 1F.
- Suite PHP completa: 209 pruebas, 1572 aserciones.
- Batería específica 1F: 8 pruebas, 213 aserciones.
- Upgrade/rollback/reaplicación 1F: 1 prueba, 37 aserciones.
- Concurrencia PostgreSQL multiproceso: 10 pruebas, 167 aserciones.
- Casos heredados 1E: 11 pruebas, 98 aserciones.
- Transiciones: 15 pruebas, 45 aserciones.
- Vitest: 1 archivo, 5 pruebas.
- Pint: 206 archivos.
- Larastan/PHPStan: 165 rutas, sin errores.
- TypeScript: sin errores.
- ESLint: sin warnings ni errores.
- Vite Plus: 86 archivos formateados y 72 sin diagnósticos.
- Build de producción: 2319 módulos transformados.
- `BaseLine/`: 131 archivos, SHA-256 canónico
  `5a996439d8432e13abecbc4ebf57f12654d15e14afef8b1160fe55dcf82ae1d3`.
- Escritura en `BaseLine/`: rechazada por filesystem read-only desde `app`,
  `queue-worker`, `scheduler`, `reverb`, `vite` y `nginx`.

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
