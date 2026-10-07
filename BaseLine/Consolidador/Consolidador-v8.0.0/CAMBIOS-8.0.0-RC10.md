# Consolidador 8.0.0-linux-rc10

RC10 deriva directamente de RC9.

## Alcance

No modifica la lógica académica, identidades, OAuth2, plugins,
roles, restauración Moodle, scheduler, workers, checkpoints,
normalización, reconciliación ni verificaciones de Fase 13.

## Heartbeat periódico

Se agrega notificación periódica de progreso para procesos largos.

Configuración:

- CONSOLIDATION_PROGRESS_EMAIL_ENABLED
- CONSOLIDATION_PROGRESS_EMAIL_INTERVAL_MINUTES
- intervalo predeterminado: 15 minutos

El notifier se ejecuta como proceso auxiliar mediante el mismo
assistant-runtime Docker del Consolidador. El host no requiere
PowerShell.

## Fase 1

Durante la extracción de respaldos de cursos se publica un snapshot
observacional en:

reports/fase-1-progress.json

El snapshot no participa en decisiones ni validaciones.

## Fase 13

No se modifica scripts/phase6-apply.ps1.

El notifier consume el snapshot ya existente:

reports/fase-6-workers-status.json

y reporta cursos completados, progreso, advertencias, fallidos,
activos, pendientes y workers.

## Seguridad operacional

El correo continúa siendo fail-open: un fallo SMTP no puede detener
la consolidación.

RC9 permanece como baseline inmediatamente anterior y conserva la
validación de persistencia systemd --user / Linger.
