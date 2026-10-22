# CAMBIOS — Consolidador 8.0.0-linux-rc13

RC13 deriva exclusivamente de RC12 y agrega un único cambio, pedido por la
plataforma web MoodleWebToolkit.

En modo automático (`ejecutar-automatico`), `v8-readiness.ps1` generaba los
candidatos fuzzy de identidad y los omitía sin detenerse (`--review=skipped`),
porque la opción `[P]` solo existía en el modo interactivo. Quien opera sin
terminal no podía revisar posibles duplicados antes de escribir usuarios.

Con `CONSOLIDATION_FUZZY_REVIEW=required` en el entorno del asistente, el modo
automático toma la opción `[P]`: prepara `exports/identity_candidate_review.csv`
y se detiene con `V8_IDENTITY_REVIEW_REQUIRED` antes de cualquier escritura de
usuarios. La revisión se aplica después con `v8-identity-review.ps1 -Mode Apply`,
el mismo validador de RC12.

`compose.yaml` transmite la variable al contenedor del asistente. Sin la
variable, el comportamiento es idéntico al de RC12.

No se modifica conciliación, restore, scheduler, workers, plugins, themes,
checkpoints, heartbeat ni notificaciones.
