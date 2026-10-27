# Guía: preparar el servidor de destino para el Consolidador

Para quien prepara el servidor de destino. La plataforma despliega el Consolidador V8 verificado por hash. Un **perfil de destino** con un nombre (por ejemplo `campus`) nombra el runtime del Consolidador para ese sitio. Desde el agente 1.17.0 un administrador de la plataforma lo crea desde la web (§ 2); el script `mwt-destination-profile` sigue disponible como alternativa manual (§ 3). Las claves del sitio quedan siempre en el servidor.

## 1. Requisitos del servidor

| Requisito     | Detalle                                                                                                                                                      |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Sistema       | Linux/Ubuntu de 64 bits con systemd, Bash 4 o superior y `flock` (`util-linux`)                                                                              |
| Acceso        | Agente de AWS Systems Manager en línea (recomendado), o SSH con llave fijada                                                                                 |
| Docker        | Docker Engine en marcha y Docker Compose v2 (`docker compose`)                                                                                               |
| AWS CLI v2    | Instalado en el servidor: trae los paquetes de S3 con el rol de la instancia                                                                                 |
| Rol IAM (EC2) | `AmazonSSMManagedInstanceCore`, lectura del prefijo `mwt/` (`s3:GetObject`) y escritura en `mwt/*/consolidated/*` y `mwt/_pruebas/*` (`s3:PutObject`), § 1.2 |
| Dominio y TLS | El dominio definitivo del sitio con su certificado y un proxy TLS hacia `127.0.0.1:<puerto>` (8090 por defecto). Lo monta usted (ver § 1.1)                  |
| Internet      | Salida a Internet durante la primera preparación: se construyen las imágenes de Moodle 5.2.1, MariaDB y el runtime del asistente                             |
| Memoria       | 4 GiB o más; con menos, la comprobación lo advierte                                                                                                          |
| Disco         | 20 GiB libres como mínimo en el runtime, más el tamaño de los paquetes, de la base de datos y de la copia integral final                                     |
| Google OAuth2 | Un cliente OAuth de Google con la URI `https://<dominio>/admin/oauth2callback.php`. Se configura en Moodle durante la ejecución                              |

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

El destino descarga los paquetes de los orígenes y, al final, sube la copia integral del sitio consolidado a `mwt/<proyecto>/consolidated/<ejecución>/`. Con solo `s3:GetObject` la consolidación funciona, pero el respaldo final falla. La prueba «S3: lectura y escritura del respaldo» de «Preparar destino» escribe y lee un objeto mínimo en `mwt/_pruebas/`, por eso ese prefijo también va en la política. La política mínima, además de `AmazonSSMManagedInstanceCore` (cambie `NOMBRE-DEL-BUCKET` por el bucket de «Administración → AWS»):

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
            "Resource": [
                "arn:aws:s3:::NOMBRE-DEL-BUCKET/mwt/*/consolidated/*",
                "arn:aws:s3:::NOMBRE-DEL-BUCKET/mwt/_pruebas/*"
            ]
        }
    ]
}
```

## 2. Preparar el destino desde la plataforma

Desde el agente 1.17.0 todo se hace en el paso 3 «Destino» del asistente, en la lista **Preparar destino** del servidor. Cada punto muestra su estado y el botón que lo reintenta:

1. **Conexión**: guarde el ID de instancia y la región, y pruebe la conexión.
2. **Agente**: instala o actualiza el agente verificado por SHA-256.
3. **Requisitos**: Docker, Docker Compose v2, memoria (advierte bajo 4 GiB) y disco libre (advierte bajo 20 GiB).
4. **S3: lectura y escritura del respaldo**: el servidor escribe con el rol de la instancia un objeto mínimo en `mwt/_pruebas/<instancia>/<aleatorio>` y lo vuelve a leer, como harán la transferencia de paquetes y el respaldo del sitio consolidado. La plataforma lo confirma con `HeadObject` usando su propia identidad y lo borra si puede.
5. **Consolidador desplegado, perfil re-apuntado solo**: **Desplegar** envía el árbol completo en segundo plano; el agente lo instala en `/opt/mwt-agent/tools/` solo si su SHA-256 coincide con el del catálogo. Al terminar, el agente re-apunta cada perfil de destino existente al árbol recién desplegado, lo mismo que hacía `mwt-destination-profile --force --tree <sha>`, pero solo mientras su sitio no haya recibido escrituras. El punto dice qué perfil se re-apuntó y, si alguno no, por qué: el destino ya fue escrito (use «Preparar destino» con limpieza para pasar a la nueva versión), falta su runtime o la URL del `.env` difiere de la del perfil.
6. **Perfil del destino creado y comprobado**: si el servidor no tiene perfil, un administrador de la plataforma indica el identificador, la **URL pública HTTPS** definitiva del sitio y el **correo del administrador** de Moodle (y, si hace falta, el usuario administrador y el puerto local), marca la confirmación y pulsa **Crear y comprobar el perfil**. La plataforma pide su contraseña si no la confirmó hace poco. El agente valida lo mismo que `mwt-destination-profile`, crea el runtime `/srv/mwt-destinations/<perfil>` copiando el árbol desplegado y verificado, crea el usuario sin privilegios `mwt-<perfil>` en el grupo `docker`, escribe el `.env` (`0600`) y el perfil (`root:root 0600`) y ejecuta la comprobación de siempre. **La clave generada del administrador de Moodle nunca sale del servidor**: queda solo en `MOODLE_ADMIN_PASSWORD` del `.env`, legible por root. La lista muestra dónde está; recupérela en el servidor (por ejemplo con Session Manager: `sudo grep MOODLE_ADMIN_PASSWORD /srv/mwt-destinations/<perfil>/.env`) y guárdela en el gestor institucional de credenciales.
7. **Preparar destino**: el flujo de siempre (§ 5), con su diálogo para limpiar o reutilizar un sitio existente.

La creación queda auditada (`REMOTE_DESTINATION_PROFILE_CREATED`) con la URL pública y el SHA-256 del correo, nunca la clave ni el correo en claro. El re-apuntado queda auditado como `REMOTE_DESTINATIONS_REPOINTED`.

## 3. Alternativa manual

El administrador del servidor puede crear el mismo perfil como root, después del despliegue. Copie `remote-agent/mwt-destination-profile` al servidor y ejecútelo:

```bash
sudo bash mwt-destination-profile campus --public-url https://campus.example.edu \
    --admin-email admin@example.edu
```

El script crea el mismo runtime, usuario, `.env` y perfil que la plataforma, con lo mismo que pide `CONFIGURAR.sh` para un sitio HTTPS (claves generadas, proxy inverso y SSL activados, notificaciones desactivadas, configuración administrada en `managed-config/` dentro del runtime). A diferencia de la plataforma, muestra **una sola vez** la clave del administrador de Moodle en la terminal.

| Opción          | Uso                                                                                            |
| --------------- | ---------------------------------------------------------------------------------------------- |
| `--admin-user`  | Usuario administrador de Moodle (`admin` por defecto)                                          |
| `--http-port`   | Puerto local donde escucha Moodle detrás del proxy TLS (8090 por defecto)                      |
| `--runtime-dir` | Ruta del runtime (`/srv/mwt-destinations/<id>` por defecto)                                    |
| `--tree`        | Árbol del Consolidador a usar, cuando hay más de uno desplegado                                |
| `--run-as`      | Usuario sin privilegios que ejecuta el Consolidador (`mwt-<id>` por defecto)                   |
| `--force`       | Conserva un runtime existente (su `.env`, checkpoints y resultados) y solo reescribe el perfil |

Con `--force` el runtime conserva su contenido; solo pasa al usuario del perfil lo que pertenezca a root, y la URL debe coincidir con `MOODLE_PUBLIC_URL` de su `.env`. Desde 1.17.0 ya no hace falta repetirlo con `--tree` tras cada despliegue: la plataforma re-apunta el perfil sola (§ 2, punto 5).

## 4. Qué comprueba la plataforma

**Comprobar perfil** reporta:

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
