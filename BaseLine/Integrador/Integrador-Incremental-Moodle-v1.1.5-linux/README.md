# Integrador Incremental Moodle 1.1.5

Herramienta CLI para incorporar un paquete sellado por **Recolector 7.4.1** a
un Moodle que ya existe y está en producción. Esta herramienta es independiente
del Consolidador 7.3.0: no vuelve a consolidar el destino ni modifica cursos
anteriores.

| Componente | Versión / estado |
|---|---|
| Integrador | `1.1.5-linux` — estable |
| Paquete de entrada | Recolector `7.4.1-linux` |
| Destino | Consolidador `7.3.0-linux` o `7.3.0-linux-rc4` |
| Moodle origen validado | `4.5.x` |
| Moodle destino validado | `5.2.1` |
| Sistema operativo | Linux/Ubuntu de 64 bits |
| Publicación automática | No; los cursos quedan ocultos |

## Correcciones acumuladas hasta la 1.1.5

La 1.1.5 amplía la reserva determinista de `fullname`: admite hasta 1000
colisiones del mismo nombre de curso y nombre de instancia, sin depender de que
el destino esté vacío. La primera importación conserva el sufijo normal, la
segunda añade el ID del curso origen y las siguientes añaden también un contador
estable. Cada candidato respeta el límite de 254 caracteres de Moodle.

También consolida el runtime endurecido y la compatibilidad de reanudación con
los planes 1.0.0 a 1.1.4. Se distribuye junto al Kit 1.3.1, que contrasta el
`fullname` real con el plan sellado y siempre publica su reporte.

La 1.1.3 vincula una reanudación al SHA-256 y al tamaño del ZIP ya validado, no
a la ruta ni a la fecha del archivo. El mismo paquete sellado puede continuar
después de actualizar o mover la suite, pero cualquier cambio de contenido
sigue bloqueado. El lanzador tampoco reemplaza su copia si ya es idéntica.

La 1.1.2 compara los archivos restaurados por su relación académica, nombre,
componente, área, tamaño y SHA-1. Si cambia el propietario técnico del registro,
solo acepta que Moodle lo haya reasignado al administrador que ejecutó el
restore; cualquier otro cambio sigue bloqueado. Entregas, autores, matrículas y
roles continúan verificándose por sus relaciones específicas.

La 1.1.1 ejecuta las operaciones internas mediante los identificadores reales
de los contenedores. Esto evita que un `docker compose exec` suspendido por el
control de trabajos de la terminal deje el preflight esperando indefinidamente.

- acepta `VERSION.txt` descriptivo leyendo únicamente su primera línea;
- reconoce el destino estable y `7.3.0-linux-rc4`;
- exporta `ASSISTANT_PROJECT_ROOT` con el directorio real del destino;
- carga explícitamente el `.env` del Consolidador en todas las llamadas Compose;
- prepara `/exports/integrator` como `UID del operador:www-data` con `0770`,
  de modo que el contenedor escriba y el operador conserve las evidencias;
- valida que `www-data` pueda escribir antes de procesar el ZIP;
- permite revalidar cierres 1.0.0/1.0.1 sin restaurar otra vez los cursos;
- admite durante la revalidación un `category-map.json` 1.0.0 sin sello
  documental, pero solamente después de vincularlo al plan y contrastar en
  Moodle cada categoría, marcador, nombre, padre, visibilidad y manager;
- mantiene bloqueado ese mismo artefacto sin sello para restauraciones o
  reanudaciones normales;
- compara inventarios de archivos legados únicamente por las columnas que
  quedaron realmente selladas, sin tratar `bytes` y `content_sha1` añadidos
  después como archivos diferentes;
- recalcula por streaming el SHA-1 y el tamaño físico de cada blob actual antes
  de declarar `file_content_hashes_verified=true`;
- corrige la comparación estricta entre claves numéricas y textuales del mapa
  de categorías;
- regenera evidencia explícita de contenido y hashes mediante
  `REVALIDAR-CIERRE.sh`;
- conserva las protecciones de copia previa, aislamiento y reanudación.

## Estado de validación

La entrega fue aprobada el 30 de agosto de 2026 mediante dos recorridos de
extremo a extremo contra una instancia creada por el Consolidador con los lotes
de laboratorio de 1 GB + 200 MB:

1. reanudación segura del lote `lab-inc-stable-20260829t211252z` después de un
   bloqueo previo al mantenimiento;
2. ejecución nueva, en una sola pasada, del lote
   `lab-inc-stable-20260830t035749z`.

Ambas terminaron con:

```text
INCREMENTAL_INTEGRATION_OK
KIT_VERIFY_OK
STABLE_TEST_OK
```

El contrato de aceptación verificó dos cursos ocultos y, en conjunto, 71
actividades, 111 matrículas, 111 asignaciones de rol, una entrega con su nota,
11 discusiones, 22 mensajes de foro, un intento de cuestionario, 74 archivos de
módulos y cuatro membresías de grupo. También aprobó reutilización por correo,
colisión de username, creación de una identidad nueva, degradación segura de
`siteadmin`, jerarquía de categorías y repetición de nombres de curso.

## Contrato de la V1

- El Moodle destino existente es la autoridad.
- El correo normalizado es la llave primaria de identidad.
- Mismo correo: se reutiliza exactamente el usuario destino; no cambia username,
  nombres, `auth`, estado, contraseña ni perfil.
- Mismo username con correo diferente: son personas distintas y el usuario nuevo
  recibe un username único y determinista.
- Un `siteadmin` del origen **nunca recibe** `siteadmin` global por la
  importación. Se asigna como `manager` de la categoría padre del lote. Si ese
  mismo correo ya era administrador global del destino, conserva ese privilegio
  porque el destino es la autoridad.
- Cada fullname queda como `Nombre original - [Nombre de la instancia]`. Si el
  nombre ya existe, se añade una variante determinista con el ID del curso y,
  en repeticiones posteriores, un contador.
- Todo entra bajo `Consolidacion-NombreInstancia-Fecha` y permanece oculto.
- La jerarquía original de categorías se conserva bajo esa categoría padre.
- Los `.mbz` de una versión posterior a la del destino se bloquean antes de
  entrar en mantenimiento.
- Un curso que ya tenga el marcador del mismo `source_id + source_course_id`
  se bloquea: la V1 no lo actualiza, duplica ni adopta desde otra ejecución.
- Los plugins usados que falten o sean incompatibles bloquean el plan. El
  preflight cubre módulos, formatos, métodos de matrícula, tipos y comportamientos
  de pregunta y filtros activos inventariados. Un plugin ausente y no utilizado
  solo genera advertencia.
- Las matrículas `cohort` y `meta` bloquean antes del mantenimiento porque
  dependen de cohortes globales u otros cursos, que están fuera del lote.
- No se importan siteadmins globales, configuración del sitio, temas, tareas
  programadas históricas, cohortes globales, secretos ni actualizaciones sobre
  cursos que ya existían.

## Contenido del paquete

```text
Integrador-Incremental-Moodle-v1.1.5-linux/
├── INTEGRAR.sh                 Ejecución principal y reanudación
├── ESTADO.sh                   Consulta de estado, unidad, log y checkpoints
├── REVALIDAR-CIERRE.sh         Recalcula el cierre sin restaurar nuevamente
├── RECUPERAR-PERMISOS.sh       Recuperación acotada de exports/integrator
├── scripts/                    Validación, plan, aplicación y verificación
├── paquetes/                   Ubicación recomendada para ZIP del Recolector
├── evidencias/                 Documentación de evidencias producidas
├── VERSION.txt                 Versión legible por scripts
├── FILES.sha256                Integridad de todos los archivos distribuidos
├── LEEME-PRIMERO.txt           Inicio rápido
└── README.md                   Esta documentación
```

## Requisitos

1. Linux con Bash 5.1 o posterior, Docker Engine, Docker Compose v2, Python 3, `gzip`, `tar` y
   `sha256sum`.
2. Moodle destino creado y publicado por **Consolidador 7.3.0-linux** o
   **7.3.0-linux-rc4**.
3. Su `Consolidador/compose.yaml`, `.env` y volúmenes deben conservarse.
4. Un ZIP sellado y validable de **Recolector 7.4.1-linux**.
5. Espacio para el paquete, temporales de un `.mbz` por worker y la copia previa.
6. Ejecutar con `sudo`. Al terminar, la herramienta devuelve los artefactos al
   UID/GID del usuario que llamó a `sudo`.

Antes de producción haga snapshot de la instancia o confirme espacio suficiente:

```bash
docker system df
df -h
```

## Uso rápido

Compruebe primero la integridad del descargable desde el directorio que contiene
el ZIP y su sidecar:

```bash
sha256sum -c Integrador-Incremental-Moodle-v1.1.5-linux.zip.sha256
```

Extraiga el ZIP en un directorio distinto al Consolidador, verifique los
archivos internos y prepare los ejecutables:

```bash
unzip Integrador-Incremental-Moodle-v1.1.5-linux.zip
cd Integrador-Incremental-Moodle-v1.1.5-linux
sha256sum -c FILES.sha256
chmod +x ./*.sh
```

Coloque el paquete del origen en `paquetes/` y ejecute:

```bash
sudo ./INTEGRAR.sh \
  --workers=auto \
  --consolidador-dir=/ruta/Consolidador-v7.3.0-linux/Consolidador \
  paquetes/origen-nuevo.zip
```

En segundo plano:

```bash
sudo ./INTEGRAR.sh \
  --background \
  --workers=auto \
  --consolidador-dir=/ruta/Consolidador-v7.3.0-linux/Consolidador \
  paquetes/origen-nuevo.zip
```

`auto` detecta los procesadores lógicos visibles en el contenedor y usa como
máximo cuatro workers. También puede fijar `--workers=1`, `2`, `3` o `4`.

### Opciones de `INTEGRAR.sh`

| Opción | Comportamiento |
|---|---|
| `--consolidador-dir=RUTA` | Directorio `Consolidador/` configurado que contiene `.env` y `compose.yaml`. |
| `--workers=auto\|1\|2\|3\|4` | Concurrencia; `auto` usa como máximo cuatro workers. |
| `--prebackup=auto` | Genera una copia previa integral y sellada. Es el valor predeterminado. |
| `--prebackup=existing:RUTA` | Vincula una copia integral existente y comprueba tamaño y SHA-256 al reutilizarla. |
| `--background` | Ejecuta mediante una unidad transitoria de systemd. |
| `-h`, `--help` | Muestra la ayuda integrada. |

`--internal-run` está reservado al lanzador de segundo plano y no debe usarse
manualmente.

Seguimiento:

```bash
sudo journalctl -u moodle-integrador-origen-nuevo.service -f
```

```bash
./ESTADO.sh \
  --consolidador-dir=/ruta/Consolidador-v7.3.0-linux/Consolidador \
  origen-nuevo
```

## Copia previa obligatoria

El valor predeterminado es:

```bash
--prebackup=auto
```

Después de activar mantenimiento y detener temporalmente cron, genera:

- `database.sql.gz`;
- `moodledata.tar.gz`, sin cachés regenerables;
- `moodle-code.tar.gz`, sin `config.php`;
- `manifest.json` con tamaño y SHA-256.

Queda en:

```text
Consolidador/exports/integrator/run-<paquete>/prebackup/
```

Si ya existe una copia integral actual obtenida inmediatamente antes del lote,
puede indicarla explícitamente:

```bash
--prebackup=existing:/ruta/paquete-sitio-consolidado.zip
```

Esta opción traslada al operador la responsabilidad de que la copia sea actual.
No existe una opción silenciosa para omitir toda protección previa.

## Flujo y rendimiento

Antes del mantenimiento:

1. Inicia o confirma el destino.
2. Instala los scripts una sola vez en `/opt/integrator-v1`.
3. Valida integralmente el ZIP una sola vez.
4. Extrae el paquete exterior una sola vez.
5. Lee usuarios, cursos, categorías, versión y plugins del destino en bloque.
6. Construye y sella `plan.json` sin escrituras.

Durante mantenimiento:

1. Detiene cron si estaba activo y conserva su estado anterior.
2. Obtiene o registra la copia previa obligatoria.
3. Crea/reutiliza usuarios por correo y la jerarquía oculta.
4. Procesa primero el curso más pesado como piloto.
5. Aplica los demás mediante una cola dinámica de workers.
6. Cada worker extrae su `.mbz` una sola vez, normaliza `users.xml` y
   `roles.xml` dentro de esa extracción, restaura y verifica.
7. No genera un `.mbz` normalizado ni árboles raw/normalized duplicados.
8. Verifica contenido, actividades, archivos (nombre, área, tamaño y hash de
   contenido cuando el Recolector lo aporta), matrículas, roles y relaciones
   académicas cubiertas por el inventario sellado.
9. Confirma que no se añadió ningún siteadmin global.
10. Restaura mantenimiento y cron al estado anterior si el cierre es seguro.

## Reanudación después de matar el proceso

Repita **exactamente el mismo comando y el mismo nombre de ZIP**.

- Se reutilizan la validación, extracción, plan y copia previa ya sellados; el
  ZIP staged, el hash canónico del plan y el snapshot destino se revalidan antes
  de entrar otra vez en mantenimiento.
- Al reutilizar la copia previa, se vuelven a comprobar tamaños y SHA-256 antes
  de permitir cualquier escritura.
- Los cursos con checkpoint se verifican y se omiten.
- Si un curso quedó a mitad de `restore`, se elimina únicamente ese contenedor
  incompleto y su temporal, pero solo si conserva una marca de propiedad del
  worker en la base de datos; luego se vuelve a procesar.
- Si alcanzó el marcador y la verificación, se adopta sin restaurar otra copia.
- Los usuarios creados antes de la interrupción se reutilizan por correo y no se
  eliminan.
- Nunca se eliminan cursos o usuarios que existían antes del lote.
- Si otro contenido intenta usar el mismo nombre de ZIP/directorio de ejecución,
  se bloquea en vez de mezclar evidencias o reutilizar una copia previa ajena.

Si un diagnóstico indica que la limpieza no fue segura, el sitio permanece en
mantenimiento. Revise:

```text
Consolidador/exports/integrator/run-<paquete>/diagnostics/
```

## Autenticación de usuarios nuevos

- Usuarios ya existentes: su autenticación destino permanece intacta.
- Usuario nuevo con Google OAuth2 verificado y un issuer destino coincidente:
  se crea el vínculo OAuth2 nativo.
- Si no existe un issuer coincidente o la identidad externa no está verificada:
  se crea como `manual` con contraseña aleatoria y cambio obligatorio.

El Integrador no adivina ni descarga configuración OAuth2.

## Resultados

Todos los artefactos quedan en:

```text
Consolidador/exports/integrator/run-<paquete>/
```

Archivos principales:

- `validation.json`: validación del Recolector.
- `target-snapshot.json`: lectura masiva previa del destino.
- `plan.json`: plan sellado.
- `prebackup/manifest.json`: copia previa.
- `category-map.json`: categorías y managers aplicados.
- `checkpoints/`: un checkpoint por curso.
- `audits/`: reescritura de identidades/roles dentro del `.mbz`.
- `inventories/`: inventario profundo del curso restaurado.
- `diagnostics/`: causa y seguridad de reintento si falla.
- `final-report.json`: evidencia de cierre.
- `status.json` e `integrador.log`: seguimiento.

La finalización correcta se reconoce por:

```text
INCREMENTAL_INTEGRATION_OK
```

## Revalidar un cierre anterior

Si un lote 1.0.0 terminó correctamente pero su `final-report.json` no contiene
las banderas reforzadas de la 1.1.5, no vuelva a restaurarlo. Ejecute:

```bash
sudo ./REVALIDAR-CIERRE.sh \
  --consolidador-dir=/ruta/Consolidador \
  --run=nombre-del-paquete-sin-zip
```

La revalidación es de solo lectura sobre Moodle, no activa mantenimiento y
termina con `INCREMENTAL_REVALIDATION_OK`. Cuando el mapa legado no tenga SHA,
el informe registra `legacy_unsigned_live_revalidation` y solo aprueba después
de comparar su estructura completa con el plan sellado y el estado real.

## Publicación

La V1 no publica automáticamente. En Moodle:

1. Entre como administrador del destino.
2. Abra `Administración del sitio > Cursos > Administrar cursos y categorías`.
3. Localice `Consolidacion-NombreInstancia-Fecha`.
4. Revise cursos, usuarios, roles y evidencias.
5. Mueva categorías si corresponde.
6. Haga visibles los cursos y la categoría únicamente después de aprobarlos.

## Permisos

La ejecución normal recupera automáticamente la propiedad. Si se corta el host
o queda un archivo con dueño `root`/`www-data`:

```bash
sudo ./RECUPERAR-PERMISOS.sh \
  /ruta/Consolidador-v7.3.0-linux/Consolidador
```

No use `chmod -R 777`: los paquetes y reportes contienen datos sensibles.

## Seguridad y límites

- El ZIP se trata como no confiable hasta validar paths, symlinks, estructura,
  tamaños y hashes.
- Cada inventario y MBZ extraído vuelve a contrastarse con el SHA-256 sellado
  inmediatamente antes de usarlo.
- No se descargan plugins de Internet. Instálelos por el procedimiento controlado
  del Consolidador y repita el preflight.
- Los cambios globales de plugins requieren aprobación del administrador.
- La recuperación destructiva desde la copia previa no es automática.
- Esta V1 agrega cursos; no sincroniza cambios posteriores ni actualiza cursos
  importados anteriormente.
- Los badges de curso y otros datos soportados viajan mediante el restore oficial
  de Moodle. Cohortes y badges globales quedan fuera del alcance.

Use el ZIP **Kit-Prueba-Integrador-Incremental-v1.3.1** para generar un paquete
real con dos cursos y validar las reglas contra su instancia 1 GB + 200 MB.
El kit construye ese paquete en la máquina de prueba porque captura, en modo de
solo lectura, un correo y un username reales del destino; así los casos de
reutilización y colisión no son ficticios ni dependen de datos inventados.
