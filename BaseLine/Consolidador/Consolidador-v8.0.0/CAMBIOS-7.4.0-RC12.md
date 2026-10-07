# Consolidador 7.4.0-linux-rc12

Base funcional: RC11. Candidata para repetir el benchmark; no se declara estable ni se afirma haber ejecutado el benchmark real en este entorno.

## Corrección del bloqueo de Fase 4

RC11 aprobó un plan de 7948 identidades, incluidas 154 identidades independientes que compartían 77 correos. El Moodle destino tenía `allowaccountssameemail=0` y rechazó una cuenta después de crear otras. RC12 configura `allowaccountssameemail=1` en la configuración administrada del destino. Una instalación nueva lo recibe desde `default-settings.json`; `PREPARAR-DESTINO.sh` migra automáticamente una configuración RC11 existente, conserva sus demás ajustes, registra una nueva versión auditada y comprueba el valor efectivo en Moodle. La evidencia queda en `reports/target-shared-email-policy.json` con el hash de los ajustes administrados. Repetir la preparación no crea otra versión si la política ya está activa.

Fase 3 distingue los correos válidos compartidos de las colisiones creadas por normalización. Conserva los dos `canonical_id`, el correo válido y `pending_relink` sin crear `google_sub`. Genera `shared_email_audit.csv`, su SHA256 y las métricas `shared_email_values` y `shared_email_identities`. Las colisiones creadas por normalización y los correos irrecuperables siguen sujetos al bloqueo RC11.

Fase 4 registra los correos compartidos, los usernames duplicados y `target_allows_shared_email` en el plan sellado. También genera `target_shared_email_audit.csv` para correos ya repetidos en destino; Apply verifica su hash. Un username duplicado sigue bloqueando. Cuando la política vale 0, Plan informa `FASE4_PLAN_BLOCKED` antes de solicitar autorización. Inmediatamente antes de crear campos o usuarios, Apply vuelve a comprobar la política y el plan, y se detiene si cambió. Un correo repetido permitido no causa adopción por correo ni colisión en Apply; se conservan el marcador canónico, la identidad fuerte y las reglas de username.

La instalación RC11 con `.env` generado por el propio paquete actualiza de manera atómica solamente la etiqueta de imagen destino a RC12 durante `PREPARAR-DESTINO.sh`; los demás valores permanecen idénticos. No se edita una etiqueta de imagen distinta de la etiqueta RC11 conocida.

## Comprobaciones

`tests/rc12-managed-config.php` prueba migración, compilación estricta e idempotencia. `tests/rc12-shared-email.php` genera 77 grupos y 154 identidades, incluye los `canonical_id` y usernames del caso real, OAuth y cuenta manual con el mismo correo, `pending_relink`, usuario previo con ese correo, bloqueo por política desactivada, bloqueo por username duplicado y dos aplicaciones reales de la función Apply con interrupción y reintento. `tests/verify-package.sh` ejecuta además las regresiones RC2–RC11 y valida checksums, sintaxis y contratos de la distribución extraída.

La prueba de integración con Moodle real y Docker queda para la siguiente corrida de benchmark; las pruebas incluidas usan un sustituto acotado de las operaciones de la base de datos para verificar la lógica de Fase 4.
