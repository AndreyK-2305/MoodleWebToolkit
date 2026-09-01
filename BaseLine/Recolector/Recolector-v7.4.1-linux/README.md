# Recolector Moodle 7.4.1

Recolector de solo lectura para exportar una instancia Moodle de origen a un
paquete ZIP portable y compatible con el **Consolidador 7.0.1**.

La versión estable es `7.4.1-linux`. Está diseñada para los Moodle 4.5.x de
origen del proyecto de consolidación y no modifica usuarios, cursos,
matrículas, calificaciones ni configuración del sitio.

> **Seguridad:** el paquete contiene identidades, matrículas, actividad
> académica y archivos institucionales. Restrinja sus permisos, transporte y
> conservación como información sensible.

## Mejoras principales de la versión 7.4.1

| Mejora | Comportamiento |
|---|---|
| Workers automáticos | Detecta CPU disponible y ejecuta hasta cuatro cursos simultáneos. `--workers=N` permite fijar de 1 a 4. |
| Cola dinámica | El worker que termina toma el siguiente curso; no divide la lista en mitades ni pares/impares. |
| Inventario sin repetición completa | El inventario global se construye una vez y se entrega como semilla a los workers. |
| Fases y heartbeat | Registra inventario, validación/reutilización, backup Moodle, copia+SHA, limpieza y checkpoint. Publica avance cada minuto. |
| SMTP operativo | Notifica inicio, progreso periódico y finalización. Los errores SMTP nunca cancelan la exportación. |
| Ruta de salida corregida | Normaliza rutas absolutas y respeta `--output-dir`; evita que el ZIP termine accidentalmente dentro de `salidas/.../../`. |
| Temporal opcional | `--temp-dir` permite usar almacenamiento rápido sin cambiar el comportamiento predeterminado de Moodle. |
| Reutilización de MBZ | Adopta copias directas de Moodle en ZIP o TGZ con `--reuse-backups`. |
| Modo exclusivamente reutilizable | `--reuse-only` garantiza que ningún curso sea respaldado otra vez por el recolector. |
| Reanudación rápida | Conserva inventarios base y checkpoints; al repetir el mismo comando continúa con los cursos pendientes. |
| Limpieza segura | Retira únicamente el artefacto parcial del curso interrumpido y elimina el `stored_file` temporal creado por Moodle después de copiarlo y verificarlo. |
| Integridad de una sola lectura | Copia y calcula SHA-256 en el mismo recorrido; el sellado reutiliza los hashes y no recomprime los MBZ. |
| Perfil académico estricto | Exige usuarios, actividades, archivos y componentes académicos. Diferencias en `logs` o `histories` se registran como advertencias de auditoría. |

## Requisitos

- Linux con Bash.
- PHP CLI 8.1 o posterior.
- Extensiones PHP `zip` (`ZipArchive`) y `dom` (`DOMDocument`).
- Acceso de lectura al `config.php` y a la instalación Moodle.
- Espacio suficiente para MBZ, directorio de trabajo y ZIP final.
- `systemd-run`, `systemctl` y permisos `sudo` para `--background`.
- Consolidador 7.0.1 para consumir el ZIP generado.

El modo `--background` está pensado para un servidor Linux con systemd. En un
contenedor Docker sin systemd se debe iniciar el comando mediante el mecanismo
de segundo plano del contenedor, por ejemplo `docker compose exec -d`.

## Instalación

```bash
unzip Recolector-v7.4.1-linux.zip -d Recolector-v7.4.1-linux
cd Recolector-v7.4.1-linux
chmod +x EXPORTAR-ORIGEN.sh VALIDAR-PAQUETE.sh
```

Comprobar la versión:

```bash
cat VERSION.txt
```

Resultado esperado:

```text
7.4.1-linux
```

## Uso mínimo

Si Moodle usa `/var/www/html/config.php`, el siguiente comando es suficiente:

```bash
sudo ./EXPORTAR-ORIGEN.sh --background pregrado
```

Valores predeterminados:

| Parámetro | Valor predeterminado |
|---|---|
| Identificador | Nombre indicado, sin `.zip`; en el ejemplo, `pregrado`. |
| Configuración Moodle | `/var/www/html/config.php`. |
| Directorio de salida | `salidas/`, dentro del recolector. |
| Workers | `auto`: CPU lógica/cgroup disponible, máximo 4. |
| Correos de progreso | Cada 10 minutos si SMTP está habilitado. |
| Temporal | El configurado por Moodle. |
| Reutilización de MBZ | Desactivada. |

La salida será:

```text
salidas/pregrado.zip
salidas/pregrado.zip.sha256
salidas/pregrado.status.json
salidas/logs/pregrado-*.log
salidas/.moodle-collector-work-pregrado/
```

El nombre puede indicarse como `pregrado` o `pregrado.zip`; ambos producen
`pregrado.zip`.

Si `config.php` está en otra ruta:

```bash
sudo ./EXPORTAR-ORIGEN.sh --background \
  pregrado \
  /srv/moodle/config.php
```

## Opciones

```text
./EXPORTAR-ORIGEN.sh [opciones] nombre.zip [/ruta/moodle/config.php]
```

| Opción | Descripción |
|---|---|
| `--background` | Crea una unidad transitoria de systemd que continúa al cerrar SSH. |
| `--workers=auto\|1\|2\|3\|4` | Selección automática o cantidad fija de cursos simultáneos. |
| `--notify-every=MINUTOS` | Intervalo de correo de progreso; `0` desactiva únicamente los periódicos. |
| `--output-dir=RUTA` | Ruta real del ZIP, SHA, estado, logs y trabajo. |
| `--temp-dir=RUTA` | Almacenamiento temporal rápido opcional para los backups Moodle. |
| `--reuse-backups=RUTA` | Directorio de MBZ existentes, tratado como solo lectura. |
| `--reuse-only` | Exige un MBZ compatible por curso y prohíbe generar faltantes. |
| `--restart` | Descarta deliberadamente el trabajo guardado de ese origen e inicia de cero. |
| `-h`, `--help` | Muestra la ayuda integrada. |

Las opciones pueden escribirse antes o después del nombre. El identificador
debe iniciar en minúscula y usar solo `a-z`, `0-9`, `_` o `-`.

## Precedencia de la ruta de salida

La ruta se decide en este orden:

1. `--output-dir=RUTA`.
2. Variable `MOODLE_COLLECTOR_OUTPUT_DIR`.
3. Directorio `salidas/` dentro del recolector.

Todas las rutas se convierten a absolutas y se imprimen al iniciar:

```text
RECOLECTOR_PATH output_dir=... output_zip=... work_dir=...
```

Ejemplo explícito:

```bash
sudo ./EXPORTAR-ORIGEN.sh --background \
  --output-dir=/mnt/exportaciones \
  pregrado \
  /srv/moodle/config.php
```

El resultado queda exactamente en:

```text
/mnt/exportaciones/pregrado.zip
```

## Flujo óptimo recomendado para producción

El camino más eficiente es adelantar durante una ventana nocturna la etapa más
costosa: la generación de los MBZ por Moodle. Después, el recolector valida y
adopta esas copias, genera identidades e inventarios, calcula hashes y sella el
ZIP compatible con el Consolidador.

```text
Moodle genera MBZ
        ↓
Directorio externo de respaldos
        ↓
Recolector --reuse-only
        ↓
Inventarios + identidades + checkpoints
        ↓
ZIP sellado + SHA-256
        ↓
Validación exhaustiva
        ↓
Consolidador 7.0.1
```

### 1. Preparar un directorio de respaldos

Use una ruta diferente del directorio de trabajo del recolector:

```bash
sudo install -d -o www-data -g www-data -m 0770 \
  /mnt/respaldos-moodle/pregrado-20260824

sudo install -d -o www-data -g www-data -m 0770 \
  /mnt/exportaciones
```

El directorio no debe estar dentro de:

```text
/mnt/exportaciones/.moodle-collector-work-pregrado/
```

### 2. Revisar el perfil general de backup

```bash
sudo -u www-data php /srv/moodle/admin/cli/cfg.php \
  --component=backup |
grep -E '^backup_general_(users|anonymize|role_assignments|activities|blocks|files|filters|comments|badges|calendarevents|userscompletion|logs|histories|questionbank|groups|competencies|contentbankcontent|xapistate|legacyfiles)'
```

Para una migración académica completa deben permanecer habilitados usuarios,
roles, actividades, archivos, calificaciones, intentos, finalizaciones y los
componentes que use el sitio. El recolector comprobará el perfil efectivo de
cada MBZ antes de adoptarlo.

### 3. Generar los MBZ con Moodle

Moodle incluye el comando oficial `admin/cli/backup.php`:

```bash
sudo -u www-data php /srv/moodle/admin/cli/backup.php \
  --courseid=123 \
  --destination=/mnt/respaldos-moodle/pregrado-20260824
```

También se pueden usar los respaldos automáticos del administrador si se
envían a un directorio externo y se confirma que todos los cursos requeridos
terminaron como `OK`. Tenga presentes las reglas de omisión de cursos ocultos
o sin cambios configuradas en Moodle.

Ejemplo secuencial para todos los cursos, excluyendo la portada del sitio:

```bash
mapfile -t course_ids < <(
  sudo -u www-data php -r '
define("CLI_SCRIPT", true);
require "/srv/moodle/config.php";
$courses = $DB->get_records_select(
    "course",
    "id <> :siteid",
    ["siteid" => SITEID],
    "id ASC",
    "id"
);
foreach ($courses as $course) {
    echo $course->id, PHP_EOL;
}
'
)

for course_id in "${course_ids[@]}"; do
  echo "BACKUP_START course_id=$course_id"
  sudo -u www-data php /srv/moodle/admin/cli/backup.php \
    "--courseid=$course_id" \
    --destination=/mnt/respaldos-moodle/pregrado-20260824
  echo "BACKUP_OK course_id=$course_id"
done
```

Este ejemplo es secuencial para facilitar el diagnóstico. Moodle puede
programar sus respaldos con concurrencia durante la ventana nocturna.

No ejecute el recolector hasta que todos los archivos hayan terminado de
escribirse.

### 4. Confirmar el conjunto de MBZ

```bash
find /mnt/respaldos-moodle/pregrado-20260824 \
  -type f -name '*.mbz' -printf '%f %s bytes\n' |
sort
```

```bash
find /mnt/respaldos-moodle/pregrado-20260824 \
  -type f -name '*.mbz' |
wc -l
```

### 5. Ejecutar el recolector en modo óptimo

```bash
cd /srv/moodle-consolidation-toolkit/Recolector

sudo MOODLE_COLLECTOR_RUN_AS_USER=www-data \
  ./EXPORTAR-ORIGEN.sh \
  --background \
  --workers=auto \
  --notify-every=10 \
  --output-dir=/mnt/exportaciones \
  --reuse-backups=/mnt/respaldos-moodle/pregrado-20260824 \
  --reuse-only \
  pregrado \
  /srv/moodle/config.php
```

En este modo:

- cada curso debe tener un MBZ compatible;
- nunca se ejecuta `moodle-backup` para un curso faltante;
- un rechazo termina la ejecución con el curso y la causa exacta;
- los MBZ originales no se modifican;
- los checkpoints válidos se conservan si otro curso falla;
- el resultado esperado termina con `created=0` y `adopted=N`.

### 6. Monitorear

```bash
sudo systemctl status moodle-recolector-pregrado.service --no-pager -l
```

```bash
sudo journalctl -u moodle-recolector-pregrado.service -f
```

```bash
sudo tail -f /mnt/exportaciones/logs/pregrado-*.log
```

```bash
sudo cat /mnt/exportaciones/pregrado.status.json
```

Eventos relevantes:

```text
EXISTING_BACKUPS_PLAN
EXISTING_BACKUP_ADOPTED
EXISTING_BACKUP_PROFILE_WARNING
EXISTING_BACKUP_REJECTED
COURSE_PHASE_START
COURSE_PHASE_OK
EXPORT_HEARTBEAT
SOURCE_PACKAGE_OK
RECOLECTOR_OK
```

### 7. Validar el paquete

```bash
./VALIDAR-PAQUETE.sh \
  /mnt/exportaciones/pregrado.zip \
  /srv/moodle/config.php
```

Validación en segundo plano:

```bash
sudo ./VALIDAR-PAQUETE.sh --background \
  /mnt/exportaciones/pregrado.zip \
  /srv/moodle/config.php
```

Comprobar el SHA externo:

```bash
cd /mnt/exportaciones
sha256sum -c pregrado.zip.sha256
```

No entregue el paquete al Consolidador hasta obtener:

```text
VALIDACION_OK
pregrado.zip: OK
```

## Modos de reutilización

| Comando | Política |
|---|---|
| Sin `--reuse-backups` | El recolector genera todos los MBZ. |
| `--reuse-backups=RUTA` | Prefiere un MBZ existente; si falta o es incompatible, lo genera. |
| `--reuse-backups=RUTA --reuse-only` | Exige MBZ existentes compatibles y nunca genera faltantes. Es el modo recomendado cuando Moodle ya realizó las copias. |

La búsqueda es recursiva y no depende del nombre del archivo. Si hay varias
copias para un curso, se prueban desde la más reciente hasta encontrar una
compatible.

## Validación de los MBZ existentes

Antes de adoptar un respaldo, el recolector comprueba:

- que sea un archivo regular y legible;
- que abra como MBZ ZIP o mediante el empaquetador TGZ nativo de Moodle;
- que contenga `moodle_backup.xml`, curso, usuarios, archivos y gradebook;
- que el ID y el nombre corto correspondan al curso actual;
- que la fecha del backup no sea anterior al último cambio conocido;
- que usuarios y actividades estén incluidos;
- que el perfil académico y de archivos requerido coincida;
- que el archivo no cambie durante la copia;
- que no esté dentro del directorio de trabajo de esa ejecución.

Las diferencias en `logs` o `histories` se aceptan con:

```text
EXISTING_BACKUP_PROFILE_WARNING impact=audit_data_only
```

Son trazas de auditoría y no sustituyen las tablas que contienen entregas,
calificaciones, intentos o finalizaciones. Cualquier otro
`backup_profile_mismatch_AJUSTE` continúa siendo un rechazo estricto.

## Reanudación rápida después de una interrupción

Si el proceso se detiene por reinicio, urgencia operativa o terminación manual,
repita exactamente el mismo comando, conservando:

- identificador del origen;
- `--output-dir`;
- ruta `--reuse-backups` cuando se utilizó;
- `config.php` correspondiente.

Ejemplo:

```bash
sudo MOODLE_COLLECTOR_RUN_AS_USER=www-data \
  ./EXPORTAR-ORIGEN.sh \
  --background \
  --workers=auto \
  --output-dir=/mnt/exportaciones \
  --reuse-backups=/mnt/respaldos-moodle/pregrado-20260824 \
  --reuse-only \
  pregrado \
  /srv/moodle/config.php
```

El recolector:

1. valida el manifiesto guardado;
2. reutiliza identidades, plugins e inventario global;
3. valida rápidamente los checkpoints terminados;
4. elimina solamente el inventario o MBZ parcial sin checkpoint;
5. continúa con los cursos pendientes;
6. sella el ZIP únicamente cuando todos finalizan correctamente.

No elimine manualmente checkpoints ni cursos completos. Use `--restart` solo
si realmente desea comenzar una ejecución nueva con el mismo nombre.

La versión estable puede reanudar ejecuciones iniciadas con
`7.4.1-linux-rc3`, `7.4.1-linux-rc2`, `7.4.1-linux-rc1` y
`7.4.0-linux-rc2` cuando el manifiesto base sigue siendo compatible.

## Workers y rendimiento

`--workers=auto` detecta los procesadores lógicos disponibles para el proceso,
incluidos límites cgroup, y selecciona como máximo cuatro workers.

| Entorno orientativo | Inicio recomendado |
|---|---|
| 2 vCPU / 8 GiB | `--workers=2` o `auto`. |
| 4 vCPU / 16 GiB | `--workers=3` y luego probar `4`. |
| 4 o más vCPU | `auto` usa como máximo `4`. |

Los cursos tienen duraciones diferentes. Procesar primero los pesados no
reduce el trabajo total; solo puede mejorar ligeramente el final de la cola.
La cola dinámica ya evita que un worker quede desocupado mientras otro conserva
una lista fija de cursos pesados.

Supervise CPU, memoria, base de datos y EBS antes de incrementar concurrencia.
Más workers no garantizan una mejora lineal si MariaDB o el almacenamiento son
el cuello de botella.

## Almacenamiento temporal opcional

Sin `--temp-dir`, Moodle conserva su temporal habitual. Para utilizar un disco
local rápido:

```bash
sudo install -d -o www-data -g www-data -m 0770 /mnt/nvme/moodle-tmp

sudo ./EXPORTAR-ORIGEN.sh --background \
  --temp-dir=/mnt/nvme/moodle-tmp \
  --output-dir=/mnt/exportaciones \
  pregrado \
  /srv/moodle/config.php
```

Esta opción solo cambia los temporales internos de los workers. ZIP, estado,
logs y checkpoints continúan en `--output-dir`.

## SMTP opcional

Copie la plantilla:

```bash
cp smtp-config.example.json smtp-config.json
chmod 600 smtp-config.json
```

Ejemplo:

```json
{
  "enabled": true,
  "host": "smtp.example.org",
  "port": 587,
  "encryption": "tls",
  "auth": true,
  "username": "usuario-smtp",
  "password": "CAMBIAR",
  "from_email": "moodle@example.org",
  "from_name": "Recolector Moodle",
  "to": [
    "administrador@example.org"
  ],
  "timeout_seconds": 10
}
```

El recolector intenta enviar:

- correo de inicio después del preflight;
- correo de progreso cada 10 minutos por defecto;
- correo final de éxito o error;
- resultado de la validación exhaustiva.

Cambiar el intervalo:

```bash
./EXPORTAR-ORIGEN.sh --notify-every=30 pregrado
```

Desactivar únicamente los periódicos:

```bash
./EXPORTAR-ORIGEN.sh --notify-every=0 pregrado
```

Puede ubicar el archivo en otra ruta:

```bash
MOODLE_COLLECTOR_SMTP_CONFIG=/etc/moodle-collector/smtp-config.json \
  ./EXPORTAR-ORIGEN.sh pregrado
```

Los envíos tienen tiempo máximo y son no bloqueantes para el resultado. El log
siempre deja uno de estos diagnósticos:

```text
SMTP_OK
SMTP_SKIPPED reason=config_not_readable
SMTP_SKIPPED reason=disabled
SMTP_WARNING ...
```

## Contenido del paquete

```text
cursos/*.mbz
inventarios/*.json
checkpoints/*.json
identidades.json
inventario-origen.json
plugins.json
manifest.json
checksums.sha256
```

Cada checkpoint relaciona curso, inventario, MBZ, tamaños, fechas y hashes. El
manifiesto final declara el origen de cada backup (`generated` o `existing`) y
las advertencias de perfil aceptadas.

## Validador exhaustivo

```bash
./VALIDAR-PAQUETE.sh /ruta/pregrado.zip
```

```bash
./VALIDAR-PAQUETE.sh \
  /ruta/pregrado.zip \
  /srv/moodle/config.php
```

El `config.php` es opcional y solo se utiliza para localizar PHPMailer si SMTP
está habilitado. La validación recalcula:

- SHA-256 del ZIP exterior y su sidecar;
- SHA-256 de cada archivo interno;
- integridad de checkpoints;
- cruces entre manifiesto, inventarios y cursos;
- número y metadatos de los MBZ;
- estructura permitida del paquete.

Genera:

```text
pregrado.validacion.json
pregrado.validacion.status.json
logs/pregrado-validacion-*.log
```

## Diagnóstico frecuente

### `EXISTING_BACKUP_REJECTED reason=backup_older_than_source`

El curso cambió después de generar el MBZ. Cree una copia más reciente.

### `backup_profile_mismatch_AJUSTE`

El respaldo no incluye un componente académico o de archivos requerido.
Revise los valores generales de backup y vuelva a generar ese curso.

### `EXISTING_BACKUP_PROFILE_WARNING`

La diferencia está limitada a `logs` o `histories`. El MBZ se adopta y la
advertencia queda auditada.

### `--reuse-only no encontró un MBZ candidato`

Falta una copia para uno o más cursos, el directorio es incorrecto o el backup
no terminó de escribirse.

### `SMTP_SKIPPED reason=config_not_readable`

No existe un `smtp-config.json` legible. No afecta la exportación ni la
validación.

### El ZIP aparece en una ruta inesperada

Revise la línea `RECOLECTOR_PATH` y utilice una ruta absoluta:

```bash
--output-dir=/mnt/exportaciones
```

### Permisos al usar `--background`

El lanzador intenta ejecutar como `SUDO_USER`. Puede fijar otro usuario:

```bash
sudo MOODLE_COLLECTOR_RUN_AS_USER=www-data \
  ./EXPORTAR-ORIGEN.sh --background pregrado /srv/moodle/config.php
```

Ese usuario debe poder leer `config.php` y recorrer los respaldos, además de
escribir en salida y temporal.

## Evidencia de aceptación de la versión estable

Prueba limpia realizada sobre Moodle 4.5.13, PHP 8.3 y MariaDB 10.11:

| Comprobación | Resultado |
|---|---:|
| Cursos del laboratorio | 12 |
| MBZ generados directamente por `admin/cli/backup.php` | 12 |
| MBZ adoptados por `--reuse-only` | 12 |
| MBZ generados nuevamente por el recolector | 0 |
| Cursos fallidos | 0 |
| Archivos internos validados | 40/40 |
| Advertencias del validador | 0 |
| Tamaño del paquete | 797,442,062 bytes |
| SHA-256 exterior | `501df88feebea68dd73f44b5242e5781799f12e97c09ef6d226a0ac725ab2bc1` |

Resultado observado:

```text
EXPORT_HEARTBEAT completed=12/12 created=0 reused=12 adopted=12 failed=0
RECOLECTOR_OK source=laboratorio
VALIDACION_OK archivos=40 cursos=12 advertencias=0
laboratorio.zip: OK
```

## Actualización desde una RC

Extraiga `Recolector-v7.4.1-linux.zip` en un directorio limpio. Conserve fuera
del directorio del programa la ruta indicada por `--output-dir` y, si aplica,
la ruta de MBZ. Repita el mismo comando: esta versión migrará el manifiesto de
ejecución compatible y continuará desde los checkpoints existentes.

## Ayuda integrada

```bash
./EXPORTAR-ORIGEN.sh --help
./VALIDAR-PAQUETE.sh --help
```
