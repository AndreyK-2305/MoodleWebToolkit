# Etapa C — Recolector en la EC2 de origen

Base: `main` en `1d17cd8`. Continúa la [Etapa B](ETAPA-B-PROVEEDOR-REMOTO.md): la
plataforma ya alcanza la EC2 por SSM y mantiene operaciones durables con `mwt-agent`.

## Cortes

| Corte | Alcance                                                                                                               | Estado    |
| ----- | --------------------------------------------------------------------------------------------------------------------- | --------- |
| C0    | Moodle sintético 4.5.10 en la EC2 de laboratorio, como origen de prueba                                               | Este PR   |
| C1    | Distribución del Recolector 7.4.2 en la EC2, verificada por hash, y operación `collector.742` registrada en el agente | Pendiente |
| C2    | Configuración no interactiva desde la plataforma y exportación bajo systemd con progreso real                         | Pendiente |
| C3    | Subida del ZIP y su `.sha256` a S3 con el rol de la instancia y registro del `SourcePackage` por referencia           | Pendiente |

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

## Límites

- Es un origen sintético pequeño: no prueba volumen, archivos grandes ni plugins de
  terceros. Una copia institucional pequeña (por ejemplo, Pregrado) queda para después de C3.
- El Recolector todavía no se ha ejecutado en la EC2 (C1–C2).
