# Consolidador 7.4.0-linux-rc5

**Candidata para repetir el benchmark. No es versión estable.** Parte del ZIP RC4 y conserva su importación `trusted_package_integrity` / `strict_integrity`, el progreso de extracción y las correcciones anteriores de RC2–RC4.

## Arranque y reintento OAuth

El wizard de RC4 cargaba `Common.ps1` al arrancar para obtener `Get-TargetSite`. Como `Common.ps1` interpreta inmediatamente `config.yaml`, la primera ejecución fallaba antes de Fase 1, cuando ese archivo todavía no existía.

- `ConfigParser.ps1` contiene las mismas funciones de interpretación que estaban en `Common.ps1`, sin ejecutar la importación al cargarse. Ambos módulos utilizan el mismo parser.
- `ConfigAccess.ps1` solo carga ese parser y define `Get-TargetSite`: interpreta `config.yaml` al llamar la función. No crea archivos ni exige configuración durante su importación.
- El wizard carga `ConfigAccess.ps1` en vez de `Common.ps1`; las comprobaciones OAuth devuelven `false` si aún no existe `config.yaml`. El primer paso genera la configuración mediante la importación normal, sin intervención manual previa.
- `Common.ps1` conserva la materialización inmediata de `$MigrationConfig` y `$Sites` y su `Get-TargetSite` para las otras fases.

## Pruebas añadidas

- `rc5-startup.ps1`: importación de `ConfigAccess.ps1` sin configuración ni archivos nuevos; arranque automático hasta la acción de Fase 1 sin `config.yaml`; lectura posterior del destino bajo demanda; contrato de `Common.ps1`.
- `rc5-launcher.sh`: ejecuta el comando `INICIAR-CONSOLIDACION.sh` con un Docker simulado, `.env` válido y sin `config.yaml`, y comprueba que entra a la acción de Fase 1. El runtime de pruebas impide crear sockets Unix; **solo en la copia temporal del fixture** se acepta un archivo regular para simular el socket. Este test no instala Moodle ni prueba Docker real.
- Las regresiones de RC4 vuelven a comprobar `WAITING_MANUAL → reintentar → OAuth listo`, la comprobación OAuth posterior a plugins y el modo trusted SHA sin volver a calcular el ZIP completo.

La suite de distribución debe ejecutarse sobre el ZIP final recién extraído y terminar con `DISTRIBUTION_OK`, código 0. La preparación real de Moodle 5.2.1, los paquetes de cientos de GB y el benchmark completo se confirman únicamente en la EC2 de prueba.
