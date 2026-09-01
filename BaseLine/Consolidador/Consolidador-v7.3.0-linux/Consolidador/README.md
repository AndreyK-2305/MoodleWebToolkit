# Consolidador Moodle UFPS — Linux

Versión `7.3.0-linux` para consolidar entre 2 y 32 paquetes de origen
sellados en un Moodle 5.2.1 nuevo sobre Linux/Ubuntu.

Esta distribución contiene únicamente la herramienta de consolidación. No
contiene utilidades ni documentación para producir los paquetes de origen.

Esta es la versión estable de la línea 7.3.0. Conserva el contrato de entrada
del Recolector 7.4.1 y fue promovida después de completar una consolidación de
laboratorio de extremo a extremo con cero diferencias.

## Mejoras y correcciones de 7.3.0

- Empaqueta `scripts/` con permiso de recorrido `0755` y sus archivos de
  lectura con `0644`; el lanzador normaliza esos permisos al preparar o
  reanudar para que `www-data` pueda leer `/opt/consolidator`.
- Recupera automáticamente la propiedad de `exports/phase5` y
  `exports/phase6` después de una interrupción, sin borrar planes ni
  checkpoints aprobados.
- Devuelve al anfitrión la propiedad de `apply_preflight.json` antes de leerlo
  y concede nuevamente escritura al contenedor solo al aplicar el lote.
- Acepta la reasignación técnica de archivos cuyo origen declara
  `source_user_id=0`, pero conserva la comparación estricta de entregas y
  archivos realmente asociados a usuarios.
- Conserva el runtime completo de fase 6: matrículas, roles efectivos, rol
  seguro `personalizado`, normalización de la extracción y comparación
  académica del lote.
- Verifica explícitamente que `www-data` pueda abrir `target-plugins.php` antes
  de inventariar los plugins del destino.

## Mejoras de rendimiento de 7.3.0

- Restaura cursos con un pool dinámico de procesos PHP. `--workers=auto` usa
  `min(CPU lógicas disponibles, 4)`; `--workers=1..4` fija la concurrencia.
- Ordena los cursos por peso estimado y asigna el siguiente pendiente al worker
  que quede libre. El orden no reduce el trabajo total, pero evita una cola
  residual dominada por un curso grande.
- Cada `.mbz` se extrae una sola vez, directamente en el directorio temporal
  que consume `restore_controller`.
- Normaliza `users.xml` y `roles.xml` dentro de esa extracción. Ya no crea una
  copia `raw`, un segundo árbol extraído ni un `.mbz` normalizado intermedio.
- Confía en el SHA-256 sellado por el Recolector y aceptado por el contrato de
  importación. En fase 6 comprueba referencia y tamaño, sin volver a recorrer
  hasta ocho veces cada archivo grande.
- Genera un trabajo liviano por curso con solo el plan, los usuarios, roles,
  matrículas y convergencias necesarios. Cada worker evita recargar los planes
  e inventarios de todos los demás cursos.
- La verificación exhaustiva de contenido se realiza inmediatamente después de
  restaurar cada curso y queda en un checkpoint atómico. La verificación final
  reutiliza esa evidencia y no reconstruye por segunda vez todo el inventario.
- Elimina la consulta N+1 usada para obtener el nombre de cada actividad; ahora
  consulta en lote por tipo de módulo.
- Monta `scripts/` una sola vez en `/opt/consolidator:ro`; las etapas ya no
  ejecutan `docker compose cp` para cada script PHP.
- Reduce cambios recursivos de propietario durante la fase paralela y escribe
  JSON/CSV críticos mediante archivo temporal y renombrado atómico.
- La copia integral final usa `pigz` y streaming para la base de datos, código
  y `moodledata`; no conserva un SQL plano temporal y reutiliza los hashes ya
  calculados para construir `checksums.sha256`.
- El retraso automático entre etapas es `0` por defecto. Puede restaurarse con
  `CONSOLIDATION_AUTO_DELAY_SECONDS` cuando se requiera una pausa operativa.

## Reanudación de la fase paralela

Cada curso terminado conserva un checkpoint independiente. Si el asistente o
uno de sus workers se detiene de forma forzada, repita el mismo comando:

```bash
./INICIAR-SEGUNDO-PLANO.sh
```

El preflight valida los checkpoints, elimina de forma segura el directorio de
restauración temporal registrado por un curso interrumpido y, si quedó un curso
contenedor incompleto, lo retira antes de reintentarlo. No es necesario borrar
artefactos manualmente. Los cursos con checkpoint válido se reutilizan.

## Cambios heredados de 7.2.1-rc6

- Corrige la planificación masiva de fase 6: interpreta `siteadmin_required`
  mediante una utilidad propia y validada, sin llamar a la función inexistente
  `p5_bool()`. El plan conserva los administradores aprobados y rechaza valores
  booleanos ambiguos en lugar de degradarlos silenciosamente.
- Trata únicamente `assignfeedback_editpdf/combined`, `pages` y `partial`
  como derivados regenerables al comparar Moodle 4.5 con 5.x. Los conteos y
  las relaciones aplican el mismo filtro; `stamps`, `submission_files` y las
  demás áreas continúan verificándose estrictamente, tanto en el piloto como
  en el lote masivo.
- Conserva y reanuda un curso piloto ya restaurado pero aún sin marcador si la
  finalización se interrumpió por esta diferencia de compatibilidad; no crea
  una copia adicional del curso.
- Fusiona automáticamente cuentas entre instancias por el correo normalizado
  cuando el Recolector confirma el vínculo del mismo emisor OAuth. La llave es
  `emisor + correo`; un correo nunca se inventa ni se guarda como `google_sub`.
- Conserva `siteadmin` como privilegio de sitio, sin generar una revisión
  imposible de aprobar en `identity_resolutions.csv`, y nunca retira
  administradores que ya existan en el destino.
- Normaliza estudiantes como `student`, docentes como `editingteacher`,
  administradores como `manager` y cualquier rol no estándar como
  `personalizado`, con un perfil seguro de solo lectura en cursos.
- Propaga las decisiones de identidad y roles a los planes, restauraciones y
  verificaciones posteriores, incluida la convergencia de matrículas de cuentas
  fusionadas por correo OAuth.
- Conserva el nombre de los cursos cuando es único. Si dos cursos comparten el
  mismo nombre, usa `[Instancia de origen] Nombre original` y audita el nombre
  objetivo en `exports/phase6/course_plan.csv`.
- Administra temporalmente los permisos de `exports/phase3` a `exports/phase6`
  entre el contenedor Moodle (`www-data`) y el operador, evitando los fallos de
  escritura y lectura observados desde las fases intermedias en adelante.
- Corrige la preparación de `exports/oauth2`: el comando enviado a `sh -lc`
  viaja como un único argumento y ya no se divide después de `&&`.
- Corrige los permisos de la fase 2: Moodle genera su inventario como
  `www-data` y, antes de crear los informes de compatibilidad, la herramienta
  devuelve `exports/phase2` al UID/GID del operador.
- Fija el proyecto Compose `moodle-consolidation-production` en todas las rutas
  Bash y PowerShell. Una variable `COMPOSE_PROJECT_NAME` heredada de otro
  despliegue ya no puede mezclar el destino con una instancia de origen.
- Solicita por separado el correo remitente autorizado por el proveedor SMTP;
  ya no se deduce del correo del administrador ni requiere editar `.env`.
- Al finalizar `CONFIGURAR.sh`, permite ejecutar inmediatamente Preparar destino
  o muestra `./PREPARAR-DESTINO.sh` como siguiente comando.
- El gestor comprueba directamente la misma referencia de imagen que construye
  Compose, aunque todavía no exista un contenedor asociado.
- Exige `identidades.json` 1.2 del Recolector y rechaza el campo obsoleto
  `google_sub_candidate`.
- Distingue un `google_sub` comprobado de un correo almacenado realmente por
  Moodle en `auth_oauth2_linked_login.username`.
- Usa el correo OAuth confirmado como llave secundaria para cualquier dominio;
  los dominios institucionales siguen declarados para trazabilidad de política.
- Incorpora una etapa obligatoria para configurar manualmente Google OAuth2 en
  el panel administrativo del Moodle destino.
- Valida el proveedor, obtiene su `issuerid` y crea los vínculos nativos usando
  exactamente el identificador externo comprobado: `sub` o correo OAuth.
- Bloquea las fases posteriores si existen identificadores OAuth duplicados,
  enlaces a otro usuario, mapeos incompatibles o vínculos no verificados.
- La importación confía en el contrato del paquete sellado. Conserva las
  defensas de ZIP, rutas, manifiesto y estructura, pero no recalcula todos los
  SHA-256 internos ni vuelve a auditar cada `.mbz` recibido.
- Elimina los topes artificiales de bytes por curso, entrada ZIP y paquete.
  Siguen aplicando la capacidad real de disco, filesystem, Docker y Moodle.
- Puede continuar automáticamente en segundo plano después de cada etapa
  aprobada, con un retraso configurable.
- Puede enviar correos al completar una etapa, al fallar o cuando se requiere
  intervención manual. Un fallo SMTP se registra, pero no altera el resultado
  de la consolidación.
- Registra el bloqueo de paquetes antes de la primera escritura en el destino.
- La publicación exige OAuth2 verificado y la copia integral de fase 8 sellada.
- Incorpora `GESTIONAR-CONFIG.sh` como utilidad autónoma, distribuida e
  integrada con el mismo despliegue, pero fuera de las 16 etapas de migración.
- Los ajustes posteriores de `config.php` se declaran en JSON, se compilan de
  forma determinista, se versionan y se montan desde el host en solo lectura.
- La aplicación reinicia únicamente Moodle y cron, comprueba el healthcheck,
  purga cachés y revierte automáticamente a la versión anterior si falla.
- `config.php` y su copia de ajustes quedan `root:www-data` con modo `0640`; el
  proceso web puede leerlas, pero no modificarlas.

## Requisitos

- Linux/Ubuntu de 64 bits.
- Docker Engine operativo.
- Docker Compose v2 (`docker compose`).
- `flock` de `util-linux` para impedir ejecuciones simultáneas.
- Usuario autorizado para utilizar Docker.
- Espacio suficiente para los ZIP, Moodle, MariaDB, temporales de una extracción
  por worker y la copia integral final. La fase 6 ya no duplica cada `.mbz`.
- Salida a Internet durante la construcción inicial de las imágenes.
- Dominio final y acceso administrativo al proyecto Google Cloud que proveerá
  el inicio de sesión institucional.
- SMTP accesible desde el servidor, solo si se activarán notificaciones.
- Un directorio persistente y protegido en el host para
  `MOODLE_MANAGED_CONFIG_DIR`; no debe ubicarse dentro de un volumen Docker.

La advertencia de menos de 20 GiB en el preflight es informativa. No representa
un límite de la herramienta. Dimensione el servidor para el volumen completo,
incluidas copias temporales y crecimiento de la base de datos.

## Estructura

```text
copias/                      Paquetes ZIP sellados de entrada
config/                      Políticas y resoluciones manuales auditables
config-manager/              Motor y plantilla del gestor de config.php
docker/                      Moodle destino y runtime del asistente
scripts/                     Motor de las 16 etapas
exports/                     Resultados, checkpoints y copia integral
reports/                     Estado, logs e instrucciones de intervención
moodle-consolidation.sh      Comando principal
GESTIONAR-CONFIG.sh          Utilidad autónoma de mantenimiento posterior
```

## Preparación

Desde la raíz del paquete:

```bash
chmod +x ./*.sh
./moodle-consolidation.sh verificar
./CONFIGURAR.sh
```

`CONFIGURAR.sh` crea `.env` con permisos `600`, solicita la URL pública,
genera las claves de base de datos y ofrece configurar las notificaciones por
correo, incluido el remitente autorizado por el proveedor SMTP. También solicita
la ruta persistente de la configuración administrada. El archivo no se
sobrescribe si ya existe. Al finalizar pregunta si debe ejecutar de inmediato
Preparar destino; si se responde que no, continúe después con:

```bash
./PREPARAR-DESTINO.sh
```

`PREPARAR-DESTINO.sh` construye las imágenes e inicializa automáticamente la
primera versión declarativa antes de arrancar Moodle. Si `target_code` se
elimina y después se prepara o inicia nuevamente el destino, `config.php` se
regenera desde `.env` y vuelve a incluir la última versión conservada en el
host.

Cuando la URL es HTTPS, Moodle escucha localmente en `127.0.0.1:8090` y espera
un proxy TLS institucional. Ese loopback no se usa como URL pública.

## Paquetes de entrada

Coloque todos los ZIP de una misma ejecución en `copias/`. Cada paquete debe:

- ser legible y declarar `package_status=sealed`;
- conservar el contrato `moodle-consolidation-source` versión `1.0`;
- tener un `source_id` válido, único y diferente del destino;
- incluir manifiesto, lista de hashes, identidades, inventario, plugins y los
  tres artefactos declarados por curso;
- usar `identity_scope=all` en modo producción.

La etapa 1 comprueba esas precondiciones, las rutas seguras y la correspondencia
exacta entre rutas y manifiesto. Calcula una sola vez el SHA-256 del ZIP para
vincular la ejecución, pero deliberadamente no relee el contenido completo para
repetir la auditoría interna de la fase previa.

No existe un máximo artificial de bytes por curso o por paquete. Permanece un
máximo configurable de cantidad de entradas ZIP (`100000`) como defensa de
estructura; no limita el tamaño de los anexos.

Después de la primera intención de escritura, no añada, quite ni reemplace ZIP.
La herramienta lo impedirá mediante `reports/destination-write.lock.json`.

## Ejecución interactiva

```bash
./INICIAR-CONSOLIDACION.sh
./INICIAR-CONSOLIDACION.sh --workers=2
```

En cada etapa están disponibles:

```text
continuar | reintentar | abrir | salir
```

`salir` conserva los checkpoints. El mismo comando reanuda desde la primera
etapa que no esté aprobada.

## Ejecución en segundo plano

```bash
./INICIAR-SEGUNDO-PLANO.sh
./INICIAR-SEGUNDO-PLANO.sh --workers=4
```

Si se omite el parámetro, `--workers=auto` es el valor efectivo. Use un valor
explícito para limitar presión sobre MariaDB, I/O o memoria. El estado agregado
de los workers queda en `reports/fase-6-workers-status.json` y cada proceso
conserva su log bajo `reports/fase-6-workers/`.

El lanzador usa una unidad de usuario de `systemd` cuando está disponible y
recurre a `nohup` en los demás casos. En modo automático:

- una etapa solo habilita la siguiente si su prueba de éxito queda aprobada;
- el retraso se toma de `CONSOLIDATION_AUTO_DELAY_SECONDS` (0 segundos por
  defecto, entre 0 y 3600);
- los conflictos o fallos detienen la ejecución con estado `blocked`;
- la etapa OAuth2 se detiene con estado `waiting_manual` cuando falta
  configuración;
- después de corregir la causa, vuelva a ejecutar
  `./INICIAR-SEGUNDO-PLANO.sh`; los checkpoints aprobados se reutilizan.

Supervisión:

```bash
./ESTADO.sh
./moodle-consolidation.sh logs
./DETENER.sh
```

`DETENER.sh` intenta detener también el proceso en segundo plano y conserva
volúmenes, ZIP, resultados y checkpoints.

## Notificaciones por correo

`CONFIGURAR.sh` solicita por separado el destinatario, el remitente autorizado
por el proveedor y el servidor SMTP, con TLS/STARTTLS opcional. El remitente no
se toma del correo administrativo del Moodle. Se notifican:

- inicio o reanudación del asistente;
- terminación correcta de cada etapa;
- fallo o conflicto bloqueante;
- necesidad de configuración manual OAuth2;
- cierre completo de la consolidación.

Las credenciales SMTP, si se usan, se codifican en base64 dentro del `.env`
protegido con permisos `600`. Base64 no es cifrado: el archivo debe permanecer
restringido y fuera de respaldos o repositorios no autorizados. Las credenciales
no se incluyen en los reportes ni en la copia integral.

## Gestor autónomo de config.php

La utilidad se entrega en el mismo ZIP para conservar compatibilidad con la
imagen y `compose.yaml`, pero no es una etapa del consolidador. Continúa siendo
utilizable durante toda la vida del Moodle publicado:

```bash
./GESTIONAR-CONFIG.sh ver
./GESTIONAR-CONFIG.sh editar
./GESTIONAR-CONFIG.sh aplicar --motivo "Ajustar sesiones institucionales"
./GESTIONAR-CONFIG.sh historial
./GESTIONAR-CONFIG.sh restaurar 20260805T160000Z-0123456789ab
./GESTIONAR-CONFIG.sh verificar
```

`editar` abre `pending.json` con `$EDITOR`. El documento admite propiedades
`$CFG` con valores y estructuras JSON. Las variables de conexión, rutas,
URL y proxy administradas por `.env` están reservadas y no pueden sobrescribirse
desde este mecanismo.

`aplicar` exige un motivo, crea una versión completa en `history/`, conmuta el
enlace `active` atómicamente y, si el sitio está levantado, reinicia únicamente
`moodle-target` y `moodle-cron`. Si Moodle no recupera salud o no puede purgar
sus cachés, reactiva la versión anterior y envía el correo correspondiente.

El archivo `audit.jsonl` conserva fecha, operador, motivo, nombres de ajustes y
hashes, pero no sus valores. Los correos tampoco incluyen valores. El origen se
monta en `/run/moodle-config` como solo lectura; al iniciar, el contenedor
verifica el manifiesto y copia la versión activa con permisos no escribibles por
`www-data`.

El directorio indicado por `MOODLE_MANAGED_CONFIG_DIR` no depende de
`target_code`, `target_data` ni `db_data`. Debe incluirse en el respaldo seguro
del servidor. La copia integral de fase 8 incorpora únicamente
`managed-config-manifest.json` para identificar su versión y hashes; no incluye
los valores declarados ni secretos.

## Configuración manual de Google OAuth2

La etapa 3 crea instrucciones en
`reports/oauth2-configuracion-manual.txt` y pausa cuando el servicio aún no está
listo. El administrador debe:

1. Registrar en Google Cloud la URI exacta mostrada por la herramienta:
   `https://dominio-final/admin/oauth2callback.php`.
2. Abrir en Moodle `Administración del sitio > Servidor > Servicios OAuth 2`.
3. Crear o editar el servicio Google institucional, introducir `Client ID` y
   `Client secret`, habilitarlo y mostrarlo en la página de acceso.
4. Habilitar el método en `Plugins > Autenticación > Gestionar autenticación`.
5. Reanudar el consolidador.

La herramienta no solicita, lee ni guarda esas credenciales. Si existe más de
un servicio Google, escriba el ID elegido en `config/oauth2.json`; nunca escriba
secretos allí.

Cuando se aplican usuarios, la herramienta utiliza el `issuerid` del destino y
el mismo tipo de identificador comprobado en los orígenes. `google_sub` solo se
usa cuando `google_sub_verified=true`; si Moodle usa un correo, se conserva como
`oauth_email` y nunca se escribe en `google_sub`. La fase 7 de usuarios verifica
cada enlace antes de permitir cursos o matrículas.

`config/identity-policy.json` declara los dominios institucionales autorizados.
La distribución UFPS incluye `ufps.edu.co`. Si la institución cambia de dominio,
actualice ese archivo antes de iniciar la etapa 4 de conciliación.

La comprobación automática no sustituye un intercambio OIDC real con Google.
Antes de publicar un destino institucional, deben iniciar sesión cuentas
representativas de estudiante, docente y gestor para comprobar el intercambio
OIDC real y la política aplicada en Google Cloud.

## Las 16 etapas

1. Importar paquetes y validar su contrato sellado.
2. Comprobar Moodle y compatibilidad de plugins.
3. Configurar y validar manualmente Google OAuth2.
4. Conciliar identidades, roles y matrículas.
5. Simular usuarios canónicos.
6. Aplicar usuarios y linked logins Google.
7. Verificar usuarios y linked logins.
8. Preparar y simular el curso piloto.
9. Restaurar el piloto.
10. Verificar el piloto.
11. Simular el lote consolidado.
12. Referenciar los MBZ y crear trabajos/checkpoints livianos, sin copiarlos.
13. Aplicar el lote mediante workers y una única extracción por curso.
14. Verificar la consolidación mediante la evidencia incremental sellada.
15. Consolidar evidencias y cerrar.
16. Generar y sellar la copia integral del sitio.

Las validaciones de planes, transformaciones XML, restauraciones y estado del
destino se conservan porque pertenecen al consolidador. Se elimina la
reauditoría exhaustiva y la duplicación del material de entrada ya sellado.

## Conflictos y plugins

Los conflictos de identidad se resuelven únicamente en:

```text
config/identity_resolutions.csv
```

Cuando el Recolector confirma en dos orígenes el mismo emisor y linked username
OAuth con forma de correo, la fusión es automática aunque el dominio sea
personal. `corrected_google_sub` queda vacío: el correo se reutiliza como llave
OAuth y nunca se convierte en un subject ficticio. Solo las ambigüedades reales
(por ejemplo, dos cuentas iguales dentro de un mismo origen o evidencias
incompatibles) requieren una resolución activa con responsable, fecha,
justificación y referencia de evidencia.

Un plugin utilizado y ausente bloquea el flujo. Instale una versión compatible
con Moodle 5.2.1 en `docker/custom-plugins/`, conservando su ruta dentro del
código Moodle, y repita la preparación y el inicio.

Los `shortname` no conflictivos se conservan. Las colisiones se desambiguan con
el identificador del origen. Los nombres completos se conservan cuando son
únicos y, si colisionan, se convierten en `[Instancia] Nombre original`. Ambas
decisiones quedan auditadas en `exports/phase6/course_plan.csv`.

## Cierre y publicación

Después de `CONSOLIDATION_ASSISTANT_OK`:

```bash
./PUBLICAR-SITIO.sh
```

La publicación exige:

- cierre de fase 7 en estado `evidence_consolidated`;
- cero linked logins OAuth2 pendientes;
- copia integral de fase 8 sellada y con SHA-256 correcto;
- nueva validación en vivo del proveedor OAuth2.

Solo entonces se activa el cron normal del Moodle consolidado.

La copia integral queda en:

```text
exports/phase8/paquete-sitio-consolidado.zip
```

Contiene base de datos, código/plugins, `moodledata` y evidencias. Excluye
`config.php`, los valores de configuración administrada y credenciales de
conexión. Incluye el manifiesto no sensible de la versión administrada, pero
sigue conteniendo información institucional sensible y debe protegerse como un
respaldo completo.

## Evidencia de aceptación de 7.3.0

La línea estable fue promovida después de una ejecución integral de laboratorio
con dos paquetes sellados del Recolector:

| Comprobación | Resultado |
|---|---:|
| Fuentes consolidadas | 2 |
| Cursos verificados | 15 |
| Curso piloto | 1 |
| Cursos del lote paralelo | 14 |
| Diferencias académicas y técnicas | 0 |
| Cursos fallidos | 0 |
| Estado de cierre | `evidence_consolidated` |
| Modo de mantenimiento restaurado | Sí |
| Tiempo total observado | 9 min 27 s |
| Copia integral de fase 8 | Generada y sellada |

```text
CONSOLIDATED_SITE_PACKAGE_OK courses=15 batch=14 failed=0 maintenance_restored=1
CONSOLIDATION_ASSISTANT_OK
Cursos verificados: 15 (piloto + 14 del lote). Diferencias: 0.
Estado: evidence_consolidated.
```

SHA-256 de la copia integral producida en esa prueba:

```text
3d4aeb5285e72425f3d057d5a2fd8a98a893f248c8894d67241a92e255c4bb50
```

## Integridad y alcance de la validación

```bash
./moodle-consolidation.sh verificar
```

El comando comprueba `FILES.sha256` y la presencia del motor requerido. Esta
edición fue sometida a comprobaciones estáticas de Bash, PHP, PowerShell, JSON,
YAML, permisos del montaje, referencias de archivos e integridad del ZIP. La
línea 7.3.0 también superó la ejecución integral descrita arriba. Antes de usar
un destino productivo deben validarse en el entorno institucional OAuth2,
SMTP, DNS/TLS, capacidad de almacenamiento y el procedimiento de recuperación.
