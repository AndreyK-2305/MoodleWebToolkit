# Guía: preparar el servidor de destino para el Consolidador

Para el administrador del servidor de destino. La plataforma despliega el Consolidador V8 verificado por hash, pero no escribe la configuración del sitio ni recibe sus claves. El administrador crea un **perfil de destino** con un nombre (por ejemplo `campus`) y la plataforma solo usa ese nombre.

## 1. Requisitos del servidor

| Requisito     | Detalle                                                                                                                                       |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Sistema       | Linux/Ubuntu de 64 bits con systemd, Bash 4 o superior y `flock` (`util-linux`)                                                               |
| Acceso        | Agente de AWS Systems Manager en línea (recomendado), o SSH con llave fijada                                                                  |
| Docker        | Docker Engine en marcha y Docker Compose v2 (`docker compose`)                                                                                |
| AWS CLI v2    | Instalado en el servidor: trae los paquetes de S3 con el rol de la instancia                                                                  |
| Rol IAM (EC2) | `AmazonSSMManagedInstanceCore`, lectura del prefijo `mwt/` (`s3:GetObject`) y escritura en `mwt/*/consolidated/*` (`s3:PutObject`), ver § 1.2 |
| Dominio y TLS | El dominio definitivo del sitio con su certificado y un proxy TLS hacia `127.0.0.1:<puerto>` (8090 por defecto). Lo monta usted (ver § 1.1)   |
| Internet      | Salida a Internet durante la primera preparación: se construyen las imágenes de Moodle 5.2.1, MariaDB y el runtime del asistente              |
| Memoria       | 4 GiB o más; con menos, la comprobación lo advierte                                                                                           |
| Disco         | 20 GiB libres como mínimo en el runtime, más el tamaño de los paquetes, de la base de datos y de la copia integral final                      |
| Google OAuth2 | Un cliente OAuth de Google con la URI `https://<dominio>/admin/oauth2callback.php`. Se configura en Moodle durante la ejecución               |

### 1.1 Proxy TLS

El `.env` activa el proxy inverso de Moodle (`reverseproxy`) y SSL (`sslproxy`), como pide el Consolidador. Con `reverseproxy`, Moodle rechaza con «Proxy inverso habilitado para que no se pueda acceder directamente al servidor» toda petición cuyo `Host` sea el de su URL pública. El proxy debe reenviar el `Host` del destino local, no el del cliente. Con Caddy, que obtiene el certificado por su cuenta cuando el DNS ya apunta al servidor:

```caddyfile
campus.example.edu {
    reverse_proxy 127.0.0.1:8090 {
        header_up Host {upstream_hostport}
    }
}
```

Con nginx el equivalente es `proxy_set_header Host 127.0.0.1:8090;` junto con `proxy_set_header X-Forwarded-Proto https;`.

### 1.2 Permisos S3 del rol del destino

El destino descarga los paquetes de los orígenes y, al final, sube la copia integral del sitio consolidado a `mwt/<proyecto>/consolidated/<ejecución>/`. Con solo `s3:GetObject` la consolidación funciona, pero el respaldo final falla. La política mínima, además de `AmazonSSMManagedInstanceCore` (cambie `NOMBRE-DEL-BUCKET` por el bucket de «Administración → AWS»):

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "DownloadPackages",
            "Effect": "Allow",
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::NOMBRE-DEL-BUCKET/mwt/*"
        },
        {
            "Sid": "UploadConsolidatedBackup",
            "Effect": "Allow",
            "Action": "s3:PutObject",
            "Resource": "arn:aws:s3:::NOMBRE-DEL-BUCKET/mwt/*/consolidated/*"
        }
    ]
}
```

## 2. Desplegar el Consolidador

En el proyecto, en «Conexiones remotas», sobre el servidor de destino:

1. **Probar conexión** e **Instalar agente** si hace falta.
2. **Desplegar Consolidador**. El árbol completo viaja en segundo plano y el agente lo instala en `/opt/mwt-agent/tools/` solo si su SHA-256 coincide con el del catálogo. El panel muestra el avance.

## 3. Crear el perfil

Copie `remote-agent/mwt-destination-profile` al servidor y ejecútelo como root:

```bash
sudo bash mwt-destination-profile campus --public-url https://campus.example.edu \
    --admin-email admin@example.edu
```

El script hace lo siguiente:

- Crea el runtime `/srv/mwt-destinations/campus`, una copia privada del Consolidador desplegado. `--runtime-dir` cambia la ruta.
- Crea, si no existe, el usuario de sistema sin privilegios `mwt-campus` (`--run-as` cambia el nombre) y lo agrega al grupo `docker`. El Consolidador se niega a ejecutarse como root; el agente lo ejecuta como este usuario, dueño del runtime. Pertenecer al grupo `docker` equivale a root en ese servidor.
- Escribe su `.env` (`0600`, del usuario anterior) con lo mismo que pide `CONFIGURAR.sh` para un sitio HTTPS:
    - claves de base de datos y del administrador generadas;
    - proxy inverso y SSL activados;
    - notificaciones desactivadas.
- Crea la configuración administrada de `config.php` dentro del runtime, en `managed-config/`, como propone `CONFIGURAR.sh`: el contenedor del asistente solo puede escribir en el runtime.
- Muestra **una sola vez** la clave del administrador de Moodle. Guárdela en el gestor institucional de credenciales.
- Escribe `/etc/mwt-agent/destinations/campus.conf` (`root:root 0600`) con la ruta del runtime, la URL, el árbol de origen y el usuario (`RUN_AS`).
- Si el agente ya está instalado, ejecuta la misma comprobación que hará la plataforma.

Opciones:

| Opción         | Uso                                                                                            |
| -------------- | ---------------------------------------------------------------------------------------------- |
| `--admin-user` | Usuario administrador de Moodle (`admin` por defecto)                                          |
| `--http-port`  | Puerto local donde escucha Moodle detrás del proxy TLS (8090 por defecto)                      |
| `--tree`       | Árbol del Consolidador a usar, cuando hay más de uno desplegado                                |
| `--run-as`     | Usuario sin privilegios que ejecuta el Consolidador (`mwt-<id>` por defecto)                   |
| `--force`      | Conserva un runtime existente (su `.env`, checkpoints y resultados) y solo reescribe el perfil |

Con `--force` el runtime conserva su contenido; solo pasa al usuario del perfil lo que pertenezca a root. La URL indicada debe coincidir con `MOODLE_PUBLIC_URL` de su `.env`.

## 4. Verificarlo desde la plataforma

1. **Detectar perfiles de destino**: lista los perfiles por nombre, con su URL pública. Señala los inválidos, por ejemplo con permisos abiertos, una URL sin HTTPS o claves desconocidas.
2. **Comprobar perfil**: reporta los siguientes hechos:
    - el `.env` existe, está protegido, completo y declara la misma URL;
    - el runtime no fue modificado;
    - el runtime pertenece al usuario del perfil y este está en el grupo `docker`;
    - el árbol coincide con el desplegado;
    - Docker, Compose v2, `flock` y `python3` están disponibles;
    - memoria y disco libre;
    - si Moodle destino está corriendo y si el destino ya recibió escrituras.

## 5. Preparar el destino

Con el perfil listo, **Preparar destino** ejecuta en el servidor el `preparar` del propio Consolidador: construye las imágenes, instala un Moodle 5.2.1 vacío y lo deja en marcha detrás de su proxy. La primera vez tarda varios minutos, por la descarga y construcción de las imágenes.

El proceso corre en el servidor, así que cerrar la página o perder la conexión no lo detiene, y la plataforma lo consulta cada minuto. El panel muestra:

- los pasos cumplidos;
- la última línea de error, si la hay;
- las últimas líneas del registro, con los valores sensibles ocultos.

**Cancelar preparación** detiene el proceso. Al terminar con éxito, la plataforma repite la comprobación del perfil.

Se recomienda un destino limpio, dedicado a la consolidación. **Preparar destino** abre un diálogo que lo recuerda. Si el destino ya tiene un sitio (Moodle en marcha, volúmenes de datos, paquetes en `copias/` o una consolidación previa), ofrece dos opciones:

- **Limpiar el destino y preparar**, la recomendada:
    - elimina los servicios, los volúmenes y el estado del sitio anterior;
    - restaura el Consolidador verificado;
    - conserva el `.env`, por lo que la clave del administrador de Moodle no cambia;
    - no toca las copias ya respaldadas en S3.

    El Moodle queda nuevo, así que el servicio Google OAuth2 se configura de nuevo cuando la Ejecución lo pida.

- **Preparar sobre el destino existente.** Es propenso a colisiones con el sitio anterior si Caddy, el DNS y los servicios relacionados no están bien configurados. No está disponible si el destino ya recibió una consolidación.

Cualquiera de las dos exige un administrador de la plataforma, la confirmación de su contraseña y escribir `CONFIRMAR`.

El nombre del proyecto Compose del Consolidador es fijo: un servidor aloja un solo runtime. Con más de un perfil en el mismo servidor, la comprobación lo advierte y la limpieza se rechaza.

## 6. Traer los paquetes

En **Paquetes en copias/**, **Traer desde S3** lista los paquetes sellados y verificados en S3 de los proyectos que usted puede ver. El servidor los descarga con su propio rol. Antes de dejar un paquete en `copias/`:

- la plataforma verifica el objeto en S3;
- el agente comprueba que el ZIP coincida con el SHA-256 y el tamaño esperados y que su sello `.zip.sha256` declare ese mismo hash.

Un paquete incompleto nunca llega a `copias/`. **Retirar** quita un paquete. Desde la primera escritura del Consolidador en el sitio, los paquetes quedan fijados y no se pueden agregar ni retirar.

## 7. Qué ve la plataforma y qué no

| La plataforma guarda                                               | Nunca recibe                                                              |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------- |
| Nombre del perfil, URL pública, hechos cerrados de la comprobación | Rutas del servidor, valores del `.env`, claves, Client ID/secret de OAuth |

## 8. OAuth2 durante la consolidación

Cuando el asistente llega a la etapa de OAuth2, la Ejecución se detiene en la intervención «Configurar OAuth2» y muestra las instrucciones del Consolidador. Usted configura el servicio de Google en Moodle (`Administración del sitio > Servidor > Servicios OAuth 2`) y pulsa **Continuar** en la plataforma. El asistente revalida en vivo y, si algo falta, la Ejecución vuelve a la misma intervención.
