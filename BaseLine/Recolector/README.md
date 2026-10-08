# Recolector Moodle — 7.4.2-linux

Herramienta CLI de **solo lectura** para exportar una instancia Moodle de origen a un paquete ZIP estructurado, sellado y consumible por el Consolidador V8.

La versión estable actual es:

```text
7.4.2-linux
```

Está orientada a Moodle 4.5.x y conserva cursos, identidades, plugins, inventarios, archivos, datos académicos, checkpoints y metadata visual requerida por el Consolidador.

## Estado

`7.4.2` es una revisión localizada de `7.4.1-linux`.

El cambio principal es la incorporación del contrato de themes requerido por el Consolidador V8. No cambia el formato MBZ ni la lógica académica de backup.

## Ubicación en el repositorio

Código extraído:

```text
Recolector/Recolector-v7.4.2/
```

Paquete publicado:

```text
Recolector/Recolector-v7.4.2-linux-corregido.zip
```

## Qué hace

El Recolector:

- lee una instancia Moodle existente;
- no modifica usuarios, cursos, matrículas ni configuración;
- inventaría la instancia;
- extrae identidades;
- registra plugins;
- registra themes;
- crea o reutiliza backups `.mbz`;
- calcula hashes;
- conserva checkpoints;
- sella un ZIP exterior;
- genera un sidecar `.zip.sha256`.

## Mejoras de 7.4.2

La versión agrega:

- theme global en `inventario-origen.json`;
- políticas de themes disponibles;
- perfiles de configuración visual seguros;
- asignaciones por curso;
- `course.theme` en inventario global e individual;
- redacción de passwords, secrets, tokens, API keys y claves privadas;
- `capabilities.theme_inventory=1.0` en el manifiesto;
- cruces de validación entre themes, plugins, inventarios y checkpoints;
- enriquecimiento de metadata visual al reanudar trabajos 7.4.1 sin regenerar MBZ.

Si una reanudación ya declara schema de themes `1.0` pero el inventario quedó incompleto, el siguiente intento vuelve a ejecutar únicamente el enriquecimiento de metadata.

## Sin cambios respecto a la lógica académica 7.4.1

7.4.2 no modifica:

- `source-plugins.php`;
- formato MBZ;
- creación de backups;
- adopción de backups existentes;
- cola de workers;
- máximo de workers;
- identidades;
- SMTP;
- perfil académico.

## Requisitos

- Linux con Bash.
- PHP CLI 8.1 o posterior.
- Extensión PHP `zip` / `ZipArchive`.
- Extensión DOM.
- Acceso de lectura al `config.php` de Moodle.
- Acceso de lectura a la instalación y datos requeridos por Moodle.
- Espacio suficiente para trabajo temporal, MBZ y ZIP final.
- `sudo`, `systemd-run` y `systemctl` únicamente para `--background`.

## Preparación

Desde el repositorio:

```bash
cd Recolector/Recolector-v7.4.2
chmod +x EXPORTAR-ORIGEN.sh VALIDAR-PAQUETE.sh
```

Comprobar la versión:

```bash
cat VERSION.txt
```

Resultado:

```text
7.4.2-linux
```

## Uso mínimo

Con el `config.php` predeterminado:

```bash
./EXPORTAR-ORIGEN.sh pregrado
```

Indicando `config.php`:

```bash
./EXPORTAR-ORIGEN.sh \
  pregrado \
  /srv/moodle/config.php
```

En segundo plano:

```bash
sudo ./EXPORTAR-ORIGEN.sh \
  --background \
  --workers=auto \
  --output-dir=/mnt/exportaciones \
  pregrado \
  /srv/moodle/config.php
```

## Opciones

```text
./EXPORTAR-ORIGEN.sh [opciones] nombre.zip [/ruta/moodle/config.php]
```

| Opción | Descripción |
|---|---|
| `--background` | Ejecuta mediante systemd y continúa al cerrar SSH |
| `--workers=auto\|1\|2\|3\|4` | Número de cursos procesados simultáneamente |
| `--notify-every=MINUTOS` | Intervalo de correo de progreso; `0` desactiva solo periódicos |
| `--output-dir=RUTA` | Directorio real del ZIP, sidecar, estado, logs y trabajo |
| `--temp-dir=RUTA` | Temporal rápido opcional para Moodle |
| `--reuse-backups=RUTA` | Busca MBZ existentes compatibles |
| `--reuse-only` | Exige MBZ compatibles para todos los cursos pendientes |
| `--restart` | Descarta deliberadamente el trabajo guardado e inicia de cero |
| `-h`, `--help` | Ayuda integrada |

Sin `--workers`, `auto` utiliza como máximo cuatro workers según los procesadores lógicos disponibles.

## Salida

Por defecto:

```text
salidas/pregrado.zip
salidas/pregrado.zip.sha256
salidas/pregrado.status.json
salidas/logs/pregrado-*.log
salidas/.moodle-collector-work-pregrado/
```

Con:

```bash
--output-dir=/mnt/exportaciones
```

la salida queda exactamente en esa ruta.

El evento inicial `RECOLECTOR_PATH` imprime las rutas absolutas efectivas.

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

El ZIP exterior se acompaña de:

```text
nombre.zip.sha256
```

## Reutilización de backups Moodle

Cuando Moodle ya generó los `.mbz`, puede evitar repetir esa etapa costosa:

```bash
./EXPORTAR-ORIGEN.sh \
  --reuse-backups=/mnt/respaldos-moodle \
  pregrado
```

Para prohibir la generación de faltantes:

```bash
./EXPORTAR-ORIGEN.sh \
  --reuse-backups=/mnt/respaldos-moodle \
  --reuse-only \
  pregrado
```

El Recolector acepta candidatos únicamente después de validar:

- archivo regular y legible;
- contenedor Moodle compatible ZIP o TGZ;
- curso correcto;
- identidad y nombre corto esperados;
- fecha no anterior al último cambio conocido;
- usuarios y actividades incluidos;
- perfil académico requerido;
- perfil de archivos requerido;
- estabilidad del archivo durante la copia;
- que no pertenezca al propio directorio de trabajo.

Diferencias limitadas a `logs` o `histories` se registran como advertencias de auditoría y no equivalen a pérdida de entregas, notas, intentos o finalización.

## Flujo recomendado con backups existentes

```text
Moodle genera MBZ
        ↓
Directorio externo de respaldos
        ↓
Recolector --reuse-only
        ↓
Inventarios + identidades + themes + checkpoints
        ↓
ZIP sellado + SHA-256
        ↓
Validación
        ↓
Consolidador V8
```

Ejemplo:

```bash
sudo MOODLE_COLLECTOR_RUN_AS_USER=www-data \
  ./EXPORTAR-ORIGEN.sh \
  --background \
  --workers=auto \
  --notify-every=10 \
  --output-dir=/mnt/exportaciones \
  --reuse-backups=/mnt/respaldos-moodle \
  --reuse-only \
  pregrado \
  /srv/moodle/config.php
```

## Reanudación

Cada curso completado conserva un checkpoint.

Si el proceso se interrumpe, repita **el mismo nombre y las mismas rutas**.

Ejemplo:

```bash
sudo ./EXPORTAR-ORIGEN.sh \
  --background \
  --workers=auto \
  --output-dir=/mnt/exportaciones \
  pregrado \
  /srv/moodle/config.php
```

El Recolector:

1. valida el manifiesto guardado;
2. reutiliza identidades y plugins;
3. enriquece metadata de themes si procede;
4. valida checkpoints ya completados;
5. retira únicamente artefactos parciales sin checkpoint válido;
6. continúa con cursos pendientes;
7. sella el ZIP cuando todos terminan correctamente.

No use `--restart` salvo que realmente quiera iniciar una corrida nueva.

7.4.2 puede reanudar trabajos compatibles de la línea 7.4.1 enriqueciendo metadata visual sin regenerar los MBZ aprobados.

## Background y monitoreo

Estado de systemd:

```bash
sudo systemctl status moodle-recolector-pregrado.service --no-pager -l
```

Journal:

```bash
sudo journalctl -u moodle-recolector-pregrado.service -f
```

Log:

```bash
sudo tail -f /mnt/exportaciones/logs/pregrado-*.log
```

Estado JSON:

```bash
sudo cat /mnt/exportaciones/pregrado.status.json
```

Eventos útiles:

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

## Themes

7.4.2 agrega el contrato:

```text
capabilities.theme_inventory=1.0
```

El paquete conserva:

- theme global;
- plugins `theme_*`;
- versión y release;
- configuración segura permitida;
- asignaciones por curso;
- `course.theme`;
- ámbitos no soportados declarados explícitamente.

Los secretos se redactan.

Esta metadata permite al Consolidador V8 diferenciar entre:

- theme transportable;
- theme ausente;
- fallback al global;
- metadata legacy no disponible.

## SMTP opcional

Copie:

```bash
cp smtp-config.example.json smtp-config.json
chmod 600 smtp-config.json
```

Configure el archivo y ejecute normalmente.

El Recolector puede enviar:

- inicio;
- heartbeat;
- resultado final;
- resultado de validación exhaustiva.

Cambiar intervalo:

```bash
./EXPORTAR-ORIGEN.sh --notify-every=30 pregrado
```

Desactivar solo periódicos:

```bash
./EXPORTAR-ORIGEN.sh --notify-every=0 pregrado
```

Un fallo SMTP no cancela la recolección.

## Validación del paquete

Primer plano:

```bash
./VALIDAR-PAQUETE.sh \
  /mnt/exportaciones/pregrado.zip \
  /srv/moodle/config.php
```

Segundo plano:

```bash
sudo ./VALIDAR-PAQUETE.sh \
  --background \
  /mnt/exportaciones/pregrado.zip \
  /srv/moodle/config.php
```

El validador exhaustivo recalcula:

- SHA-256 del ZIP exterior;
- sidecar;
- hashes internos;
- integridad de checkpoints;
- coherencia entre manifiesto e inventarios;
- cursos y MBZ;
- metadata de themes;
- estructura permitida.

Resultado esperado:

```text
VALIDACION_OK
pregrado.zip: OK
```

## Compatibilidad con Consolidador V8

La salida `7.4.2-linux` es la entrada recomendada para:

```text
Consolidador 8.0.0-linux-rc12
```

La metadata adicional de themes evita tratar el origen como `legacy_not_available`.

El Recolector no necesita conocer cómo se resolverán posteriormente identidades, plugins o themes en el destino: únicamente produce evidencia sellada del origen.

## Evidencia de aceptación de 7.4.2

La línea estable conserva la prueba limpia realizada sobre Moodle 4.5.13, PHP 8.3 y MariaDB 10.11:

| Comprobación | Resultado |
|---|---:|
| Cursos del laboratorio | 12 |
| MBZ disponibles | 12 |
| MBZ adoptados mediante `--reuse-only` | 12 |
| MBZ generados nuevamente | 0 |
| Cursos fallidos | 0 |
| Archivos internos validados | 40/40 |
| Advertencias del validador | 0 |
| Tamaño del paquete | 797,442,062 bytes |

Resultado representativo:

```text
EXPORT_HEARTBEAT completed=12/12 created=0 reused=12 adopted=12 failed=0
RECOLECTOR_OK source=laboratorio
VALIDACION_OK archivos=40 cursos=12 advertencias=0
laboratorio.zip: OK
```

## Seguridad

El paquete producido contiene información institucional sensible.

No publique:

```text
paquetes reales
identidades.json reales
inventarios institucionales
MBZ
logs con datos sensibles
smtp-config.json
credenciales
tokens
config.php
```

El repositorio solo debe conservar código, plantillas, fixtures sintéticos y documentación.

## Diagnóstico

### Backup existente demasiado antiguo

```text
EXISTING_BACKUP_REJECTED reason=backup_older_than_source
```

Genere o seleccione un MBZ más reciente.

### Perfil incompatible

```text
backup_profile_mismatch_AJUSTE
```

El respaldo no contiene algún componente requerido.

### Diferencia solo de auditoría

```text
EXISTING_BACKUP_PROFILE_WARNING
```

La diferencia está limitada a `logs` o `histories`.

### `--reuse-only` sin candidato

Falta un MBZ válido para el curso o la ruta es incorrecta.

### SMTP no configurado

```text
SMTP_SKIPPED reason=config_not_readable
```

No afecta el resultado académico.

### Permisos en background

Puede fijar el usuario:

```bash
sudo MOODLE_COLLECTOR_RUN_AS_USER=www-data \
  ./EXPORTAR-ORIGEN.sh \
  --background \
  pregrado \
  /srv/moodle/config.php
```

## Integridad de la distribución

Después de clonar o extraer:

```bash
sha256sum -c FILES.sha256
```

Ayuda integrada:

```bash
./EXPORTAR-ORIGEN.sh --help
./VALIDAR-PAQUETE.sh --help
```
