# Consolidador 7.4.0-linux-rc7

Candidata basada en RC6. No se declara versión estable ni apta para repetir el benchmark real sin verificar este ZIP final.

- Fase 2 acepta `dependencies: null`, `{}` y `[]` vacío. Un array no vacío o una versión inválida genera `PLUGIN_TECHNICAL_VALIDATION_FAILED` con componente, campo y valor; el inventario CLI nuevo emite `{}` sin dependencias.
- Revalidación R conserva AFTER y diff, escribe validación técnica y checkpoint incluso ante datos inválidos. Los fallos de adquisición del inventario se distinguen como `inventory_collection`.
- El launcher exporta su propia raíz y verifica en la configuración renderizada de Docker Compose que `assistant-runtime` recibe el bind, directorio de trabajo y variable de este mismo proyecto.
- Las imágenes target y assistant usan RC7. La prueba de etiquetas rechaza referencias activas RC1 a RC6.
- Los pins aprobados por el operador se mantienen tal como están; la distribución no incorpora plugins externos ni elige commits nuevos. Para benchmark, los commits validados indicados en el requerimiento RC7 deben constar en el manifiesto y en el inventario del entorno de destino.

Regresión: `tests/rc7-plugin-dependencies.ps1` cubre las nueve variantes de contrato; `tests/rc3-integration.ps1` cubre R con cuatro plugins, artefactos de fallo y recuperación; `tests/rc5-launcher.sh` comprueba raíz heredada de otra instalación.
