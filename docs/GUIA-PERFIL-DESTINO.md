# Guía: preparar el servidor de destino para el Consolidador

Para el administrador del servidor de destino. La plataforma despliega el Consolidador V8 verificado por hash, pero no escribe la configuración del sitio ni recibe sus claves. El administrador crea un **perfil de destino** con un nombre (por ejemplo `campus`) y la plataforma solo usa ese nombre.

## 1. Requisitos del servidor

| Requisito     | Detalle                                                                                                                          |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Sistema       | Linux/Ubuntu de 64 bits con systemd, Bash 4 o superior y `flock` (`util-linux`)                                                  |
| Acceso        | Agente de AWS Systems Manager en línea (recomendado), o SSH con llave fijada                                                     |
| Docker        | Docker Engine en marcha y Docker Compose v2 (`docker compose`)                                                                   |
| Dominio y TLS | El dominio definitivo del sitio con su certificado y un proxy TLS hacia `127.0.0.1:<puerto>` (8090 por defecto). Lo monta usted  |
| Internet      | Salida a Internet durante la primera preparación: se construyen las imágenes de Moodle 5.2.1, MariaDB y el runtime del asistente |
| Memoria       | 4 GiB o más; con menos, la comprobación lo advierte                                                                              |
| Disco         | 20 GiB libres como mínimo en el runtime, más el tamaño de los paquetes, de la base de datos y de la copia integral final         |
| Google OAuth2 | Un cliente OAuth de Google con la URI `https://<dominio>/admin/oauth2callback.php`. Se configura en Moodle durante la ejecución  |

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
- Escribe su `.env` (`root:root 0600`) con lo mismo que pide `CONFIGURAR.sh` para un sitio HTTPS:
    - claves de base de datos y del administrador generadas;
    - proxy inverso y SSL activados;
    - notificaciones desactivadas.
- Crea la configuración administrada de `config.php` en `/srv/mwt-destinations/campus-managed-config`.
- Muestra **una sola vez** la clave del administrador de Moodle. Guárdela en el gestor institucional de credenciales.
- Escribe `/etc/mwt-agent/destinations/campus.conf` (`root:root 0600`) con la ruta del runtime, la URL y el árbol de origen.
- Si el agente ya está instalado, ejecuta la misma comprobación que hará la plataforma.

Opciones:

| Opción         | Uso                                                                                            |
| -------------- | ---------------------------------------------------------------------------------------------- |
| `--admin-user` | Usuario administrador de Moodle (`admin` por defecto)                                          |
| `--http-port`  | Puerto local donde escucha Moodle detrás del proxy TLS (8090 por defecto)                      |
| `--tree`       | Árbol del Consolidador a usar, cuando hay más de uno desplegado                                |
| `--force`      | Conserva un runtime existente (su `.env`, checkpoints y resultados) y solo reescribe el perfil |

Con `--force` el runtime no se toca. La URL indicada debe coincidir con `MOODLE_PUBLIC_URL` de su `.env`.

## 4. Verificarlo desde la plataforma

1. **Detectar perfiles de destino**: lista los perfiles por nombre, con su URL pública. Señala los inválidos, por ejemplo con permisos abiertos, una URL sin HTTPS o claves desconocidas.
2. **Comprobar perfil**: reporta los siguientes hechos:
    - el `.env` existe, está protegido, completo y declara la misma URL;
    - el runtime no fue modificado;
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

Una vez que el Consolidador escribió en el destino, la preparación queda bloqueada: repetirla recrearía sus servicios a mitad de la migración.

## 6. Qué ve la plataforma y qué no

| La plataforma guarda                                               | Nunca recibe                                                              |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------- |
| Nombre del perfil, URL pública, hechos cerrados de la comprobación | Rutas del servidor, valores del `.env`, claves, Client ID/secret de OAuth |

## 7. OAuth2 durante la consolidación

Cuando el asistente llega a la etapa de OAuth2, la Ejecución se detiene en la intervención «Configurar OAuth2» y muestra las instrucciones del Consolidador. Usted configura el servicio de Google en Moodle (`Administración del sitio > Servidor > Servicios OAuth 2`) y pulsa **Continuar** en la plataforma. El asistente revalida en vivo y, si algo falta, la Ejecución vuelve a la misma intervención.
