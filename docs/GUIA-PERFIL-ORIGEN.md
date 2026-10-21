# Guía: preparar un servidor Moodle institucional como origen

Para el administrador del servidor de origen. La plataforma no recibe contraseñas ni rutas internas del servidor, y nunca escribe en su configuración. El administrador crea un **perfil de origen** con un nombre (por ejemplo `pregrado`), y la plataforma solo usa ese nombre.

## 1. Requisitos del servidor

| Requisito      | Detalle                                                                                                              |
| -------------- | -------------------------------------------------------------------------------------------------------------------- |
| Sistema        | Linux con systemd y Bash 4 o superior                                                                                |
| Acceso         | Agente de AWS Systems Manager en línea (recomendado), o SSH con llave fijada                                         |
| Rol IAM (EC2)  | `AmazonSSMManagedInstanceCore` y escritura **solo** en el prefijo `mwt/` del bucket de paquetes (`s3:PutObject`)     |
| AWS CLI v2     | Instalado en el servidor: la publicación del paquete lo usa con el rol de la instancia                               |
| PHP del Moodle | 8.1 a 8.3, con `zip` y `dom`, el mismo que usa Moodle (nativo o en el contenedor)                                    |
| `moodledata`   | Escribible por el usuario de Moodle: los backups de curso se generan ahí                                             |
| Disco          | Libre en `/var/lib/mwt-agent` para el paquete: como referencia, el tamaño de los backups de los cursos más un margen |

Al terminar cada recolección, la plataforma libera el espacio de la exportación en el servidor. Se conservan solo la evidencia y los registros.

## 2. Crear el perfil

Copie `remote-agent/mwt-source-profile` al servidor y ejecútelo como root.

Moodle instalado directamente en el servidor:

```bash
sudo bash mwt-source-profile pregrado --config /var/www/moodle/config.php --run-as www-data
```

Moodle en contenedores (Docker Compose):

```bash
sudo bash mwt-source-profile pregrado --config /var/www/html/config.php --runtime compose \
    --compose-dir /opt/moodle --compose-service php --run-as www-data
```

El script hace lo siguiente:

- Valida el nombre, la ruta y el usuario.
- Escribe `/etc/mwt-agent/sources/pregrado.conf` como `root:root 0600`.
- No reemplaza un perfil existente sin `--force`.
- Si el agente ya está instalado, ejecuta la misma comprobación que hará la plataforma.

El perfil solo contiene la ruta de `config.php`, el runtime y el usuario. Las credenciales de la base de datos se quedan en el `config.php` de Moodle.

## 3. Verificarlo desde la plataforma

En el proyecto, en la tarjeta «Recolección desde el servidor de origen»:

1. **Probar conexión** del servidor e **Instalar agente** si hace falta.
2. **Desplegar Recolector 7.4.2**: el árbol se verifica por SHA-256 en el servidor.
3. **Detectar perfiles del servidor**: lista los perfiles por nombre e indica si alguno es inválido, por ejemplo con permisos abiertos o claves desconocidas.
4. **Comprobar perfil de origen**: muestra la versión de Moodle, la de PHP y el espacio libre en `moodledata` y en el directorio del agente.

Para la comprobación, Moodle carga solo su configuración (`ABORT_AFTER_CONFIG`), sin conectarse a la base de datos. Nada de esa configuración sale del servidor.

## 4. Qué ve la plataforma y qué no

| La plataforma guarda                                            | Nunca recibe                                    |
| --------------------------------------------------------------- | ----------------------------------------------- |
| Nombre del perfil, si es válido, runtime y usuario de ejecución | Rutas del servidor, `config.php`, contraseñas   |
| Versiones de Moodle y PHP; espacio libre                        | Contenido de `moodledata` o de la base de datos |
| Estado y progreso estructurado del Recolector                   | Salida de consola del Recolector                |
| Hashes y ubicación del paquete en S3                            | Credenciales de AWS del servidor                |

## 5. Retirar un perfil

```bash
sudo rm /etc/mwt-agent/sources/pregrado.conf
```

Después vuelva a **Detectar perfiles** en la plataforma. Los paquetes ya publicados en S3 siguen a disposición del administrador del bucket.
