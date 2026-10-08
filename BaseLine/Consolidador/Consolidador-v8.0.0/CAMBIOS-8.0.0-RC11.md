# CAMBIOS — Consolidador 8.0.0-linux-rc11

## Origen

RC11 deriva exclusivamente del artefacto congelado y verificado RC10.

## Corrección

RC10 ya generaba snapshots observacionales para Fase 1 y utilizaba el
snapshot existente de Fase 13, pero el notifier periódico nacía únicamente
con el runner de segundo plano.

RC11 completa el ciclo de vida del notifier:

- modo interactivo: el notifier se inicia junto al wizard;
- Fase 1 puede emitir heartbeat periódico mientras se leen/extraen cursos;
- al solicitar transición al tramo masivo, el notifier interactivo se apaga
  y se espera su terminación;
- después se invoca `start_background`;
- el runner background inicia su propio notifier para Fase 13;
- no pueden coexistir ambos notifiers durante el handoff.

## Invariantes

No se modifica:

- `scripts/phase6-apply.ps1`;
- scheduler;
- workers;
- restore;
- reconciliación;
- identidad;
- checkpoints;
- política de Linger;
- lógica académica.

El correo continúa siendo observacional y fail-open.
