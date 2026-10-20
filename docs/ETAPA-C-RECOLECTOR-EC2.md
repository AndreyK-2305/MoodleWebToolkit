# Etapa C — Recolector en la EC2 de origen

Base: `main` en `1d17cd8`. Continúa la [Etapa B](ETAPA-B-PROVEEDOR-REMOTO.md): la
plataforma ya alcanza la EC2 por SSM y mantiene operaciones durables con `mwt-agent`.

## Cortes

| Corte | Alcance                                                                                                               | Estado          |
| ----- | --------------------------------------------------------------------------------------------------------------------- | --------------- |
| C0    | Moodle sintético 4.5.10 en la EC2 de laboratorio, como origen de prueba                                               | Fusionado (#17) |
| C1    | Distribución del Recolector 7.4.2 en la EC2, verificada por hash, y operación `collector.742` registrada en el agente | Fusionado (#18) |
| C2    | Configuración no interactiva desde la plataforma y exportación bajo systemd con progreso real                         | Este PR         |
| C3    | Subida del ZIP y su `.sha256` a S3 con el rol de la instancia y registro del `SourcePackage` por referencia           | Pendiente       |

## C0: origen sintético en la EC2

`docker/collector-lab-ec2/` prepara en la instancia el mismo origen que usa el laboratorio
local (`seed-moodle.php`, sin cambios), pero con la forma de un servidor institucional
real: un `config.php` propio con las credenciales de la base. La plataforma nunca lee ese
archivo; el Recolector recibirá solo su ruta.

| Componente    | Detalle                                                                                                                         |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Código        | `/opt/moodle-lab/code`, tag `v4.5.10` verificado contra el commit `b2c2f2a0…6b29bb77646`                                        |
| moodledata    | `/opt/moodle-lab/data`, `www-data` `0700`                                                                                       |
| Base de datos | `postgres:17-alpine`, publicada solo en `127.0.0.1:5432`, contraseña aleatoria en un archivo `0400`                             |
| PHP           | Imagen `moodle-lab-php:8.3` (8.3 CLI con las extensiones del laboratorio). Ubuntu 26.04 trae PHP 8.5, que Moodle 4.5 no soporta |
| Datos         | 2 cursos, 3 usuarios `nologin`, páginas y carpetas con archivos, temas Boost/Classic, emisor OAuth y vínculo sintético          |

```bash
# Requiere solo el AWS CLI con permisos de SSM; no usa SSH ni puertos entrantes.
bash docker/collector-lab-ec2/send-via-ssm.sh <instance-id> <perfil> <región>
bash docker/collector-lab-ec2/send-via-ssm.sh <instance-id> <perfil> <región> verify.sh
```

`provision.sh` es idempotente: al repetirlo conserva base, secretos y datos. Solo imprime
marcadores de etapa cerrados; nunca credenciales ni la salida de Moodle.

### Verificación (2026-10-20)

Instancia `t3.medium`, Ubuntu 26.04, `us-east-2`.

| Comprobación | Resultado                                                                              |
| ------------ | -------------------------------------------------------------------------------------- |
| Moodle       | 4.5.10 (Build 20260216) sobre PHP 8.3.35                                               |
| Contenido    | 2 cursos, 3 usuarios, 6 módulos, 2 archivos de carpeta, 1 emisor OAuth y 1 vínculo     |
| Temas        | Boost en el sitio, Classic en un curso                                                 |
| Integridad   | `fixture_id` coincide con el marcador                                                  |
| Base         | `healthy`, solo en `127.0.0.1:5432`                                                    |
| Permisos     | `config.php` `root:www-data 640`; moodledata `www-data 700`; secreto de la base `0400` |

## C1: distribución del Recolector en la EC2

### Despliegue verificado

`RemoteToolDeployer` toma una distribución del catálogo y la verifica localmente con
`ToolDistributionVerifier`. Después la empaqueta y la envía en fragmentos base64 de 16 KiB
al área de staging del agente. Por último, la instala con el subcomando nuevo
`tool-commit`, que:

1. comprueba el SHA-256 del paquete;
2. rechaza miembros con `/` inicial o `..`;
3. extrae en un directorio temporal y rechaza enlaces y archivos especiales;
4. recalcula el **hash canónico del árbol** con el mismo algoritmo que la plataforma
   (filas `ruta` + NUL + `sha256` ordenadas por bytes y unidas por saltos de línea);
5. solo si coincide con el catálogo, mueve el árbol a
   `/opt/mwt-agent/tools/<clave>/<sha>`.

Un segundo despliegue de la misma distribución se reconoce como `already_present`. Por
ahora solo se despliegan distribuciones sin archivos excluidos ni mutables, porque esos
cambiarían el hash. El Consolidador V8 necesitará su propio contrato en la etapa D.

### Operación `collector.742`

Registrada en el agente 1.1.0 y en `RemoteCommandRegistry`. Sus parámetros son solo
identificadores: `distribution` (hash), `source` (perfil), `package` (nombre sin extensión)
y `workers` (`auto` o 1–4). **Ninguna ruta viaja desde la plataforma.**

- La ruta de `config.php`, el usuario y el runtime de PHP salen de un **perfil de origen**
  que escribe el administrador del servidor: `/etc/mwt-agent/sources/<id>.conf`, de root,
  sin escritura para grupo ni otros. Solo admite claves conocidas y rutas sin `..`.
- Antes de cada lanzamiento, el agente vuelve a verificar el hash del árbol instalado:
  un árbol modificado nunca se ejecuta (`tool_hash_mismatch`).
- El área de trabajo (`ops/<uuid>/work/{output,tmp}`) se entrega al usuario del perfil.
  Los directorios de estado quedan en `0711`: se pueden atravesar, pero no listar.
- Runtime `native` (`runuser` con el usuario del perfil) o `compose` (el contenedor PHP del
  origen, con la herramienta montada como solo lectura y el área de trabajo en la misma
  ruta).

El subcomando `source-check` confirma que el perfil puede ejecutar el Recolector: versión
de PHP, extensiones Zip y DOM, y que `config.php` se pueda leer en ese runtime. La
plataforma (`CheckRemoteSource`) lo considera listo con PHP 8.1–8.3. El resultado no
incluye rutas.

```bash
php artisan remote:tool deploy <server_uuid>
php artisan remote:tool status <server_uuid>
php artisan remote:tool source-check <server_uuid> --source=synthetic-lab
```

### Verificación en la EC2 (2026-10-20)

| Paso                     | Resultado                                                                           |
| ------------------------ | ----------------------------------------------------------------------------------- |
| Agente                   | 1.1.0 instalado y verificado por SHA-256 en 19 s                                    |
| Prueba de conexión       | `VALID`                                                                             |
| Despliegue               | 8 fragmentos por SSM en 27,6 s                                                      |
| Estado de la herramienta | Hash del árbol en la EC2 `4daaa16…` = catálogo; 29 archivos; 0 enlaces o especiales |
| Perfil `synthetic-lab`   | Listo: runtime compose como `www-data`, Zip y DOM, `config.php` legible             |

`provision.sh` crea ahora el perfil `synthetic-lab` para el origen sintético de C0.

## C2: exportación real desde la plataforma

`RemoteCollectorExport` lanza `collector.742` y lee el estado estructurado del Recolector.

**Antes de lanzar**, exige dos cosas, ambas registradas en la evidencia del servidor:

- que el árbol desplegado tenga el hash del catálogo;
- que el perfil de origen haya superado `source-check`.

La operación usa la misma maquinaria durable de B2 (systemd, reconciliación, cancelación).

**Observación (agente 1.2.0)**

- `launch.json` registra los parámetros de la operación; todos son identificadores ya
  validados.
- El subcomando `collector-report` devuelve en base64, acotados a 16 KiB:
    - `<paquete>.status.json` y `export-progress.json`, los documentos que escribe el
      propio Recolector;
    - el listado del directorio de salida;
    - la línea del sidecar `.sha256`.
- La plataforma conserva solo campos permitidos: estado, etapa, código, versión, contadores
  de cursos y workers. Nunca guarda las rutas del documento, y el texto de consola nunca se
  interpreta.
- El progreso es real: `completed_courses / total_courses`. Si no hay un total conocido,
  queda `null`.
- El scheduler observa estas operaciones junto con su reporte, de modo que el reporte final
  se captura en la misma pasada que detecta la terminación. El panel de conexiones muestra
  etapa, cursos y si el paquete está listo.

**Paquete listo** solo cuando coinciden todas las señales independientes: la operación
`SUCCEEDED` con exit 0; el estado del Recolector `success/completed` con exit 0; el sidecar
nombra exactamente `<paquete>.zip` con un SHA-256 válido; y el ZIP existe y no está vacío.

### Exportación real en la EC2 (2026-10-20)

| Paso                                                         | Resultado                                                                                                                                                              |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Agente                                                       | 1.2.0 instalado y verificado                                                                                                                                           |
| Recolector                                                   | Ya presente (`already_present`), mismo hash del catálogo                                                                                                               |
| Perfil `synthetic-lab`                                       | Listo                                                                                                                                                                  |
| Lanzamiento                                                  | Unidad `mwt-op-3e0e1325-…`, `CONNECTED/RUNNING`                                                                                                                        |
| Resultado                                                    | `TERMINATED/SUCCEEDED`, exit 0; Recolector `success/completed` en 3 s                                                                                                  |
| Progreso                                                     | 2/2 cursos creados (100 %), 0 fallidos                                                                                                                                 |
| Paquete                                                      | `origen-ec2-lab.zip`, 29 411 bytes, **listo**, SHA-256 `ea8de81a…5c35c5`; 65 líneas de log guardadas                                                                   |
| Verificación independiente (por SSM, fuera de la plataforma) | `sha256sum` del ZIP igual al sidecar; propietario `www-data`, modo `600`; `VALIDAR-PAQUETE.sh` de la distribución: `VALIDACION_OK archivos=10 cursos=2 advertencias=0` |

La exportación del origen sintético es tan corta que terminó antes de la primera
observación: el progreso intermedio solo se comprobó con pruebas automáticas. Un origen
más grande (por ejemplo, una copia de Pregrado) lo mostrará en vivo.

## Límites

- Es un origen sintético pequeño: no prueba volumen, archivos grandes ni plugins de
  terceros. Una copia institucional pequeña (por ejemplo, Pregrado) queda para después de C3.
- El paquete queda en el área de trabajo de la operación en la EC2. Subirlo a S3 y
  registrar el `SourcePackage` es C3; la validación con `VALIDAR-PAQUETE.sh` todavía se hizo
  fuera de la plataforma.
- La exportación remota aún no actualiza los estados de la `Execution` ni genera
  `ExecutionEvent`; su estado vive en la `RemoteOperation`.
- Los fragmentos de un despliegue interrumpido quedan en el staging del agente hasta el
  siguiente intento; todavía no hay una limpieza periódica.
