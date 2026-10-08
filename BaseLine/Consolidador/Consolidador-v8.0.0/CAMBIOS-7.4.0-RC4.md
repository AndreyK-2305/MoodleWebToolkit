# Consolidador 7.4.0-linux-rc4

**Candidata técnica. No es una versión estable ni ha superado aún una nueva corrida del benchmark real.** Conserva las políticas, diagnósticos y pruebas de RC2 y RC3, incluida la identificación del intento de quiz faltante y el seguimiento determinista de usuarios históricos eliminados entre cursos.

## OAuth y reanudación

- El wizard carga `Common.ps1` en su propio ámbito antes de ejecutar las etapas. `Get-TargetSite` vuelve a estar disponible cuando se reintenta OAuth tras `WAITING_MANUAL`.
- La regresión ejecuta el wizard completo con el comando interactivo `reintentar` después de una pausa OAuth inicial y después de aprobar plugins, durante la revalidación en vivo. También comprueba las mismas transiciones con la reanudación automática.

## Importación de paquetes

- Fase 1 requiere para cada `nombre.zip` el sello exterior `nombre.zip.sha256` emitido por el Recolector 7.4.1. Comprueba el formato, el nombre del ZIP, el contrato, el productor, el `source_id`, el manifiesto, `checksums.sha256` y los metadatos extraídos. La importación sigue empezando desde `copias/` y comunica sus etapas y el progreso de extracción.
- El modo normal usa el SHA-256 del sello exterior sin volver a recorrer el ZIP completo. El ajuste `package_policy.strict_integrity=true` recalcula y contrasta el SHA-256 exterior antes de extraer. Los dos modos comprueban los hashes de los metadatos; el modo normal no recalcula cada MBZ.
- El sello SHA-256 no es una firma digital. La procedencia del par ZIP/sello debe estar establecida por el operador; la comprobación estricta detecta una diferencia entre ambos, pero no autentica un par reemplazado coordinadamente.
- Los fixtures cubren modo normal, estricto, ZIP modificado después de sellarlo, discrepancia de hash exterior, `source_id` interno contradictorio con hashes coherentes, sello ausente, malformado o asociado a otro nombre. Instrumentan las llamadas a `Get-FileHash` para impedir una segunda lectura completa del ZIP en modo normal.

## Criterio de distribución

`tests/verify-package.sh` comprueba hashes, sintaxis PHP, Bash y PowerShell, JSON, YAML, contratos y todas las regresiones de RC2, RC3 y RC4. La entrega exige ejecutar ese script sobre un ZIP RC4 recién extraído y obtener `DISTRIBUTION_OK` con código de salida 0. La compatibilidad con el entorno real y los paquetes voluminosos debe confirmarse durante la próxima corrida de benchmark.
