# Consolidador 7.4.0-linux-rc2

**Candidata de pruebas; no es una versión estable ni se declara apta para el benchmark real.** Parte de RC1 y conserva sus mejoras para `users.xml`, `auxiliary_mapped`, el mapa global de Fase 4, `historical_deleted`, el preflight previo a categorías, `course_completions`, diagnóstico de quiz, checkpoints, `restored_pending_verification`, `verification_failed`, propagación de workers y permisos compartidos.

## Correcciones de RC2

- Se completó `scripts/phase4-verify.php`: comprueba el mapa por cuenta y el usuario destino, privilegios `siteadmin`, linked logins OAuth, cuentas manuales `pending_relink`, hashes y resumen consumido por Fase 5.
- Una cuenta externa sin `google_sub` verificado ni linked login utilizable se conserva individualmente con `identity_method=pending_relink`, `decision=keep_pending_relink`, autenticación manual y sin inventar un `google_sub`. Un correo inválido o compartido recibe una dirección técnica única `@example.invalid`; el correo de origen sigue en los archivos de trazabilidad. No se fusionan cuentas por coincidencia de correo. Solo los conflictos reales de identidad bloquean la continuidad.
- Fase 2 distingue `missing`, `missing_installed`, `installed_disabled`, `enabled`, `incompatible`, `older_version` y `wrong_or_unpinned_version`. Un módulo utilizado y deshabilitado bloquea la restauración. Los plugins adicionales deben estar declarados en `docker/custom-plugins/approved-plugins.json`, con commit, versión, release, SHA-256 del árbol y submodules recursivos (HVP/H5P incluidos). Docker verifica los pins y el preflight comprueba el código instalado. No se incluyen commits aprobados de plugins institucionales en esta candidata: deben registrarse con su evidencia antes de una restauración que los requiera.
- La validación OAuth comprueba `wwwroot` HTTPS, el hostname público de `target.url`, `reverseproxy`, `sslproxy`, callback, issuer, estado de `auth_oauth2` y credenciales presentes sin copiarlas. El asistente la exige antes de conciliar y aplicar usuarios.
- Se revalida el SHA-256 de cada MBZ sellado antes de procesarlo, incluso si la modificación conserva el tamaño; los checkpoints de restauración verificada fallida se reanudan sin repetir el restore.

## Puerta de distribución

`tests/verify-package.sh` debe ejecutarse desde el **ZIP final recién extraído**, terminar con `DISTRIBUTION_OK` y código 0. Valida todos los checksums, `php -l` sobre **todos** los PHP, Bash, parser PowerShell, JSON, YAML y contratos. Los fixtures incluyen 206 cuentas sin identidad fuerte, correo compartido o inválido, `siteadmin` con rol académico, OAuth/proxy, plugin disabled/incompatible/submodule, resume, dos workers, envío de correo deshabilitado, artefacto sellado modificado y `verification_failed`.

## Alcance de la validación

Los contratos sintéticos de esta distribución no sustituyen un benchmark de Moodle 5.2 con los dos ZIP originales del Recolector. La divergencia histórica de intento de cuestionario `487/486`, el comportamiento real de restauración de cuentas eliminadas y la comparación de los 365 cursos requieren verificación en el destino nuevo. No reusar el destino ni los checkpoints de 7.3.0.
