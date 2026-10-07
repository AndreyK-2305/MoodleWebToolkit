# Consolidador 7.4.0-linux-rc11

Base: distribución RC10. Candidata para una **nueva prueba de benchmark**; la aceptación con los paquetes reales todavía requiere ejecutar esa prueba. No se cambian los paquetes de origen ni se hace merge automático.

## Fallo y causa

RC10 autorizó en Fase 3 cinco cuentas con correos inválidos para el destino. Fase 4 seleccionaba `proposed_email` o, para un grupo con varios correos, un correo del inventario por `timemodified`, orden de fuente e ID local. Sólo entonces ejecutaba `validate_email`, por lo que cinco identidades aprobadas terminaron como `conflict_invalid_email`. El archivo de resoluciones manuales exige huellas de conflictos de identidad y no sirve para corregir mecánicamente un correo.

## Corrección

- Fase 3 valida el correo que seleccionará Fase 4, incluida la selección por fecha para una identidad con varias cuentas. Usa una regla conservadora compatible con el validador de correo del contrato Moodle: dirección ASCII con `FILTER_VALIDATE_EMAIL`. Recorta espacios externos, convierte el dominio a minúsculas, elimina puntos terminales del local-part y translitera sólo vocales latinas acentuadas, `ñ` y `ç` conocidos. Si no puede obtener un correo válido sin inventar caracteres, deja la identidad en `manual_review`. Dominios internacionales y caracteres sin transformación explícita también requieren revisión.
- El saneamiento se limita al correo técnico de la identidad canónica. `google_sub`, issuer, linked login, `identity_method`, `source_account`, roles y el inventario fuente quedan intactos. Se preserva `pending_relink`; si su correo inválido admite saneamiento, se usa el correo normalizado sin convertirla a OAuth. Los placeholders de `pending_relink` para correos compartidos válidos conservan la política previa.
- Si el correo normalizado coincide con el de otra identidad canónica aprobada, ambas quedan pendientes de revisión en Fase 3. Los correos compartidos preexistentes no se fusionan ni se bloquean por sí solos. Una normalización correcta emite `EMAIL_NORMALIZED_WARNING` y no agrega un conflicto de identidad.
- `exports/phase3/email_normalization_audit.csv` guarda `canonical_id`, cuenta, correo **original**, resultado, reglas, razón, colisión y estado. `summary.json` agrega `email_normalized`, `email_normalization_blocked` y SHA256 de esta auditoría; Fase 4 comprueba el SHA y suma el archivo a su contrato de hashes. La salida `FASE3_OK` conserva sus campos anteriores y suma ambas métricas.
- Fase 4 conserva `conflict_invalid_email` como validación defensiva ante corrupción posterior. Las decisiones `manual_review` de Fase 3 no avanzan a aplicación. El esquema y las columnas anteriores permanecen legibles.

## Pruebas

`tests/rc11-email-normalization.php` ejercita los cinco correos reales, un correo válido, espacios, un correo imposible, colisión, cuenta pendiente de relink, correo compartido, dos cuentas por identidad, rol académico/siteadmin, reintento, idempotencia, conservación del inventario, auditoría sellada y rechazo defensivo en Fase 4. `tests/rc11-resolution-regression.php` valida `merge`, `keep_separate`, `exclude` y huellas obligatorias. El fixture RC2 de 206 cuentas conserva las 206 identidades `pending_relink`; su única dirección irrecuperable se deja pendiente de revisión en Fase 3, según el nuevo contrato, mientras las demás mantienen su comportamiento. La prueba de tags runtime bloquea RC1–RC10. La distribución final se valida con `tests/verify-package.sh` sobre el ZIP extraído.

Los cinco correos conocidos del benchmark se cubren mediante fixtures, sin tener aquí los 8028 registros originales ni un destino Moodle real. Sólo una nueva ejecución puede confirmar `canonical=7948`, los 206 casos reales y la ausencia de otros impedimentos independientes; siguen vigentes todas las condiciones de plugins y OAuth documentadas en RC10.
