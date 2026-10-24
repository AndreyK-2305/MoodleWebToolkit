# CAMBIOS — Consolidador 8.0.0-linux (final)

La versión final deriva exclusivamente de RC13 y corrige un único defecto,
observado en la prueba de punta a punta desde MoodleWebToolkit.

## Consolidación sin plugins

Cuando las fuentes no necesitan ningún plugin, `v8-plugin-resolver.ps1 -Mode
Prepare` resolvía un lock vacío y reescribía
`docker/custom-plugins/approved-plugins.json`, pero reconstruía `moodle-target`
solo si había instalado algún plugin. La imagen conservaba el manifiesto
anterior en `/opt/approved-plugins.json`. La revalidación comparaba ambos
manifiestos y fallaba con `PLUGIN_TECHNICAL_VALIDATION_FAILED: destino, URL o
pins inconsistentes`. A partir de ahí archivaba las necesidades, no alcanzaba
`READY_FOR_APPROVAL` y el lock nunca se sellaba. Un reintento tampoco lo
resolvía, porque `Prepare` reutilizaba el lock y omitía la reconstrucción.

Ahora `Prepare` reconstruye y recrea `moodle-target` cuando instala plugins o
cuando el manifiesto de la imagen difiere del archivo, también al reutilizar
un lock existente. Con un lock vacío la etapa de plugins se revalida, se sella
y se aprueba sola, como con plugins.

La prueba `tests/v8-800-zero-plugin-rebuild.ps1` ejecuta las funciones reales
del resolver con un `docker` simulado.

No se modifica conciliación, restore, scheduler, workers, themes, identidades,
checkpoints, heartbeat ni notificaciones.
