# Consolidador 7.4.0-linux-rc6

**Candidata técnica; todavía no es versión estable.** Parte de RC5 y conserva sin cambios `ConfigAccess.ps1`, `ConfigParser.ps1`, la importación trusted/strict, los contratos de plugins e identidades, los diagnósticos históricos y de quiz, y las fases académicas.

## Paquetes sellados sobre hardlinks

`PackageIntegrity.ps1` acepta un ZIP y su `.zip.sha256` si cada uno es un archivo regular o un hardlink a un archivo regular. Sigue rechazando symlinks, junctions y demás puntos de análisis. Las regresiones usan ZIP normal, ambos hardlinks en modos trusted y strict, y los dos tipos de symlink. Trusted no calcula de nuevo el SHA-256 completo del ZIP; strict lo calcula una sola vez.

## Runtime y detención

- Las referencias a imágenes de Moodle destino y assistant usan RC6 en Compose, el configurador, el gestor de configuración y `.env.example`. Un test bloquea tags de runtime RC1–RC5 y exige las referencias RC6.
- `DETENER.sh` detiene los servicios del proyecto y también retira los contenedores one-off `assistant-runtime` cuyo proyecto, servicio y tipo de contenedor coinciden explícitamente con este despliegue. Conserva contenedores de otros proyectos, servicios ordinarios y volúmenes. La regresión lo verifica con Docker simulado.

La distribución debe superar `tests/verify-package.sh` desde el ZIP final recién extraído. El test de detención usa infraestructura Docker simulada; el cierre de una ejecución real se comprobará en la EC2 del benchmark.
