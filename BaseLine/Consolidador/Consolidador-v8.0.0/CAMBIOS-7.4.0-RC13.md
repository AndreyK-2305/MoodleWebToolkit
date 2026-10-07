# Consolidador 7.4.0-linux-rc13

Base: distribución verificada `7.4.0-linux-rc12` (SHA256 `4adaab9b9239a8c8b62fc1fa3c97f977db595bc883bd100783806c7da1ec6671`). Candidata para continuar el benchmark; no es versión estable.

## Bloqueo resuelto

En Fase 3, RC12 sí generó `shared_email_audit.csv` y selló su SHA256. Después, `Test-IdentityReconciliation` construyó una ruta física con `Join-Path $ProjectRoot`, pero llamó `Get-LowerFileHash` con esa ruta absoluta. Este helper también antepone `$ProjectRoot`, duplicando la raíz Linux y bloqueando `04-identidades` tras una conciliación correcta.

El call-site conserva dos valores: la ruta relativa `exports/phase3/shared_email_audit.csv` para `Get-LowerFileHash` y la ruta absoluta para `Test-Path`. No se modificó `Get-LowerFileHash` ni `Test-SealedFileHash`, y el SHA256 sigue siendo obligatorio. No se modificaron `reconcile-identities.php`, `phase4-lib.php` ni los datos o algoritmos de identidad de RC12.

`tests/rc13-wizard-paths.ps1` carga las funciones reales del wizard en una ruta Linux absoluta de estilo `/srv/consolidador-v7.4.0-rc13/Consolidador`, verifica que Fase 3 reconoce un audit auténtico, observa la ruta física usada para el hash, rechaza un audit modificado y acepta un reintento tras restaurar sus bytes. Comprueba también que `Test-SealedFileHash` conserva su contrato de rutas relativas. La revisión de otros call-sites no encontró nuevas rutas absolutas entregadas a `Get-LowerFileHash`; `target_shared_email_audit.csv` se comprueba dentro de PHP con el path ya resuelto, sin reanteponer `$ProjectRoot`.

Se actualizaron las etiquetas de imágenes y los metadatos a RC13. `PREPARAR-DESTINO.sh` migra la etiqueta generada del destino desde RC11 o RC12 a RC13 de forma atómica, sin alterar los demás ajustes. La prueba heredada de migración valida ambos orígenes y el reintento. Todas las protecciones, métricas, artefactos y pruebas funcionales RC12 siguen incluidas.

La integración real con Docker y Moodle debe comprobarse en la siguiente corrida de benchmark. El paquete se entrega únicamente cuando `tests/verify-package.sh` sobre el ZIP recién extraído termina con `DISTRIBUTION_OK` y exit code 0.
