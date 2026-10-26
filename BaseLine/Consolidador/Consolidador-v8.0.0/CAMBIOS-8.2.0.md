# CAMBIOS — Consolidador 8.2.0-linux

8.2.0 deriva exclusivamente de 8.1.0-linux y añade una sola capacidad: copiar
en el destino la personalización del theme global (SCSS, colores, presets) de
un origen elegido.

## Problema

- El Recolector 7.4.2 inventaria el perfil de configuración de cada theme
  (`themes.profiles` en `inventario-origen.json`) y 8.0.0/8.1.0 lo validaban,
  lo sellaban en `exports/theme-profiles/` y lo cruzaban contra el paquete,
  pero no lo escribían en el destino.
- El theme global elegido quedaba con su configuración de fábrica: sin el SCSS,
  los colores ni el preset de la institución.

## Política

`config/theme-policy.json` admite `global_theme_profile_source`:

- vacío (predeterminado): el comportamiento es idéntico a 8.1.0;
- el `source_id` de un paquete: se copia el perfil que ese origen tenía para el
  theme global elegido.

La plataforma web lo escribe con `mwt-agent theme-policy-set` (agente 1.16.0)
junto con la selección del theme.

## Plan sellado

`--mode=themes` (`v8-preparation.php`) añade `global_theme_profile` a
`exports/theme-plan.json`:

- `state`: `not_requested` o `requested`;
- `settings`: solo los ajustes con estado `config_value` y valor escalar;
- `skipped`: lo que nunca se aplica, con su motivo: `redacted` (secretos),
  `file_reference` (logos, fondos, favicons: el Recolector guarda la referencia,
  no el archivo), `not_available`, `plugin_version` (`version`) e
  `invalid_name`;
- `profile_sha256`: huella del perfil copiado.

Un origen que no está entre los paquetes, o que no trae perfil del theme
global, bloquea con `V8_THEME_PROFILE_SOURCE_INVALID` antes de escribir el
destino.

## Aplicación

`scripts/v8-theme-profile.php` corre dentro de `moodle-target` en la etapa
`09b-themes-piloto` (`v8-course-themes.ps1 -Scope Pilot`), antes de las
asignaciones por curso:

- comprueba que el plan está sellado y que el theme efectivo es el global;
- escribe cada ajuste con `set_config(..., 'theme_<nombre>')`, como la página
  del theme, y lo verifica con `get_config`;
- es idempotente: los ajustes iguales se registran como `unchanged`;
- llama a `theme_reset_all_caches()` para recompilar el SCSS;
- deja `exports/theme-profile-application.json`, que entra en la evidencia del
  cierre.

Los valores viven en `config_plugins` y no en la configuración administrada de
solo lectura: el administrador puede cambiarlos después desde Moodle
(Apariencia › Temas) sin reinstalar nada. Los themes no elegidos siguen
instalados y seleccionables.

## Pruebas

- `tests/v8-820-theme-profile.php`: origen vacío, ajustes copiados, exclusiones
  y orígenes inválidos; se ejecuta en `verify-package.sh`.
- La validación de punta a punta queda para la prueba con copias
  institucionales.
