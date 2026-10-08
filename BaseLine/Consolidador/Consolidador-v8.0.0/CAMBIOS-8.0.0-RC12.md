# CAMBIOS — Consolidador 8.0.0-linux-rc12

RC12 deriva exclusivamente del ZIP congelado RC11.

Durante la E2E de RC11 se reprodujo un fallo en el traspaso a
segundo plano cuando `run-background-with-progress.sh` no tenía
bit ejecutable.

La invocación directa mediante systemd falló con:

- `status=203/EXEC`
- `Permission denied`

Una vez recuperado el bit ejecutable, la misma ejecución quedó
activa mediante systemd y Fase 13 continuó procesando cursos con
cuatro workers y heartbeat normal.

RC12 elimina esa dependencia invocando el wrapper explícitamente
mediante `/usr/bin/env bash` tanto en systemd como en el fallback
nohup.

No se modifica Fase 13, restore, scheduler, workers, identidad,
plugins, checkpoints, heartbeat, notifier ni Linger.
