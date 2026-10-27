# Guía: preparar un servidor Moodle institucional como origen

Para quien prepara un servidor Moodle como origen. Un **perfil de origen** con un nombre (por ejemplo `pregrado`) le dice al agente dónde está el `config.php` de Moodle y cómo ejecutar su PHP. Desde el agente 1.17.0 un administrador de la plataforma lo crea desde la web (§ 2); el script `mwt-source-profile` sigue disponible como alternativa manual (§ 3). La plataforma nunca recibe contraseñas ni lee `config.php`.

## 1. Requisitos del servidor

| Requisito      | Detalle                                                                                                                        |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Sistema        | Linux con systemd y Bash 4 o superior                                                                                          |
| Acceso         | Agente de AWS Systems Manager en línea (recomendado), o SSH con llave fijada                                                   |
| Rol IAM (EC2)  | `AmazonSSMManagedInstanceCore` y escritura **solo** en el prefijo `mwt/` del bucket (`s3:PutObject`), `mwt/_pruebas/` incluido |
| AWS CLI v2     | Instalado en el servidor: la publicación del paquete lo usa con el rol de la instancia                                         |
| PHP del Moodle | 8.1 a 8.3, con `zip` y `dom`, el mismo que usa Moodle (nativo o en el contenedor)                                              |
| `moodledata`   | Escribible por el usuario de Moodle: los backups de curso se generan ahí                                                       |
| Disco          | Libre en `/var/lib/mwt-agent` para el paquete: como referencia, el tamaño de los backups de los cursos más un margen           |

Al terminar cada recolección, la plataforma libera el espacio de la exportación en el servidor. Se conservan solo la evidencia y los registros.

## 2. Preparar el origen desde la plataforma

Desde el agente 1.17.0 todo se hace en el paso 2 «Orígenes» del asistente, en la lista **Preparar origen** de cada origen. Cada punto muestra su estado (listo, advertencia, fallido, en curso, pendiente o bloqueado) y el botón que lo reintenta:

1. **Conexión SSM**: guarde el ID de instancia y la región, y pruebe la conexión.
2. **Agente instalado o actualizado**: instala el agente verificado por SHA-256 o lo actualiza si el servidor tiene otra versión.
3. **Requisitos**: AWS CLI v2, PHP (del servidor o de un contenedor) y disco libre, según el diagnóstico del agente.
4. **Puede subir a S3**: el servidor sube con el rol de la instancia un objeto mínimo a `mwt/_pruebas/<instancia>/<aleatorio>` del bucket de paquetes. La plataforma lo confirma con `HeadObject` usando su propia identidad y luego lo borra si su identidad lo permite.
5. **Recolector desplegado**: despliega el Recolector 7.4.2 verificado por hash.
6. **Moodle detectado**: **Buscar Moodle** recorre `/var/www/*`, `/opt/*` y `/srv/*`, y los proyectos de Docker Compose en marcha cuyos servicios montan un `config.php` de Moodle. Mira solo la presencia de `version.php`, `config.php` y `lib/setup.php` y su dueño; nunca lee `config.php`. Propone las instalaciones encontradas con su runtime (nativo, o Compose con su directorio y servicio) y el usuario de ejecución probable. Si no aparece la suya, escriba la ruta a mano.
7. **Perfil creado y comprobado**: un administrador de la plataforma elige o escribe la instalación, marca la confirmación y pulsa **Crear y comprobar el perfil**. La plataforma pide su contraseña si no la confirmó hace poco. El agente valida lo mismo que `mwt-source-profile` (ruta absoluta sin `..`, un `config.php` de Moodle legible por el usuario de ejecución, PHP presente o un servicio de Compose válido), no reemplaza un perfil existente salvo que se marque «Reemplazar», escribe `/etc/mwt-agent/sources/<perfil>.conf` como `root:root 0600` y ejecuta enseguida la misma comprobación de siempre: versión de Moodle, PHP, CPU, AWS CLI v2 y espacio libre.

Cuando el perfil queda comprobado, el origen muestra su selector de **workers**: «Automático» (CPU − 1, mínimo 1) o un número de 1 a CPU − 1. El Recolector 7.4.2 admite como máximo 4, así que ninguno de los dos pasa de 4. La plataforma valida el número contra las CPU que reportó la comprobación.

La creación del perfil queda auditada (`REMOTE_SOURCE_PROFILE_CREATED`) con el SHA-256 de las rutas, nunca las rutas en claro. La lista sirve igual para un proyecto de recolección y para la recolección que crea «Recolectar ahora».

## 3. Alternativa manual

El administrador del servidor puede crear el mismo perfil como root. Copie `remote-agent/mwt-source-profile` al servidor y ejecútelo:

Moodle instalado directamente en el servidor:

```bash
sudo bash mwt-source-profile pregrado --config /var/www/moodle/config.php --run-as www-data
```

Moodle en contenedores (Docker Compose):

```bash
sudo bash mwt-source-profile pregrado --config /var/www/html/config.php --runtime compose \
    --compose-dir /opt/moodle --compose-service php --run-as www-data
```

El script valida el nombre, la ruta y el usuario, escribe `/etc/mwt-agent/sources/pregrado.conf` como `root:root 0600`, no reemplaza un perfil existente sin `--force` y, si el agente ya está instalado, ejecuta la misma comprobación que la plataforma. Después, en la lista, el punto 6 reconoce el perfil existente y el 7 lo comprueba.

El perfil solo contiene la ruta de `config.php`, el runtime y el usuario. Las credenciales de la base de datos se quedan en el `config.php` de Moodle; para la comprobación, Moodle carga solo su configuración (`ABORT_AFTER_CONFIG`), sin conectarse a la base de datos.

## 4. Qué ve la plataforma y qué no

| La plataforma guarda                                            | Nunca recibe                                    |
| --------------------------------------------------------------- | ----------------------------------------------- |
| Nombre del perfil, si es válido, runtime y usuario de ejecución | El contenido de `config.php`, contraseñas       |
| Versiones de Moodle y PHP, CPU, AWS CLI v2; espacio libre       | Contenido de `moodledata` o de la base de datos |
| Las rutas de las instalaciones encontradas, para elegir una     | Las rutas en claro en la auditoría (solo hash)  |
| Estado y progreso estructurado del Recolector                   | Salida de consola del Recolector                |
| Hashes y ubicación del paquete en S3                            | Credenciales de AWS del servidor                |

## 5. Retirar un perfil

```bash
sudo rm /etc/mwt-agent/sources/pregrado.conf
```

Después vuelva a **Detectar perfiles** en la plataforma. Los paquetes ya publicados en S3 siguen a disposición del administrador del bucket.
