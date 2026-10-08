# Consolidador 7.4.0-linux-rc20

RC20 parte exactamente de RC19 y corrige de forma localizada el contrato de
clasificación de usuarios de backups durante las etapas 12–14 de Fase 6.
Continúa siendo una candidata técnica; no se declara estable ni apta para
benchmark hasta completar la ejecución real.

## Corrección

- Se separan explícitamente cinco conjuntos dentro del trabajo liviano:
  participantes planificados, evidencias académicas estrictas, referencias no
  obligatorias, usuarios requeridos en `users.xml` y usuarios referenciados
  para mapping/trazabilidad.
- Matrículas y roles del plan continúan siendo participantes obligatorios.
- Entregas, notas, discusiones, publicaciones, intentos, activity completions y
  `course_completions` con `completed=true` continúan siendo evidencia estricta.
- `relations.files.source_user_id` conserva autoría y verificación semántica,
  pero no convierte por sí solo al propietario histórico en participante.
- `course_completions` con `completed=false` reutiliza la vista efectiva de RC18
  y permanece como seguimiento no finalizado.
- Relaciones nuevas o desconocidas son estrictas por defecto para evitar una
  relajación accidental del contrato académico.

## Checkpoints y reanudación

- El contrato de clasificación queda versionado como
  `phase6-backup-users-v2`.
- Un checkpoint `referenced` solo es válido cuando la clasificación está
  aprobada, no bloqueada y su hash coincide con el job.
- Los jobs/checkpoints RC19 se detectan como obsoletos y se regeneran desde el
  MBZ y el plan sellado, sin repetir las fases anteriores.
- El asistente exige `backup_user_contract_version=2` en el manifiesto; por
  tanto, un manifiesto RC19 no permite saltar la etapa 12.
- Si la clasificación nueva continúa bloqueada, la etapa 12 falla antes de
  sellar el checkpoint como `referenced`.

## Regresión

`tests/rc20-phase6-user-classification.php` y su fixture cubren:

- el curso real con participantes `6, 78, 210, 211, 1317`;
- propietarios de archivos `24` y `25` ausentes de `users.xml`;
- seguimiento `77` con `completed=false`;
- `completed=true` y todas las relaciones académicas estrictas;
- participante real faltante e identidad activa sin mapping;
- `historical_deleted` y `historical_map_conflicts`;
- rechazo de `referenced + blocked=true`;
- invalidación y regeneración del contrato RC19.

Se conserva la suite RC1–RC19, incluida la lectura tar inequívoca de RC19, la
comparación efectiva de RC18 y las protecciones de módulos/files de RC14–RC17.
Fases 1–5, restore, workers, categorías, hashes e identidad global no cambian.
