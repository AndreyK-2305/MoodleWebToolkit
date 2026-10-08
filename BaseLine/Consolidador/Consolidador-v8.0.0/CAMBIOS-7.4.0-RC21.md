# Consolidador 7.4.0-linux-rc21

RC21 parte exactamente de RC20 y corrige de forma localizada la clasificación
de usuarios exigibles en `users.xml` durante las etapas 12–14 de Fase 6. Sigue
siendo una candidata técnica; no se declara estable ni apta para benchmark
hasta completar la ejecución real.

## Corrección

- `required_backup_user_ids` es ahora la unión determinista de participantes
  planificados y usuarios declarados explícitamente por el MBZ mediante
  `inforef.xml/userref`.
- Los IDs presentes en relaciones continúan en `referenced_source_user_ids`,
  conservan mappings y siguen disponibles para auditoría y verificación, pero
  su sola presencia en el inventario no inventa transporte en `users.xml`.
- La etapa 12 lee selectivamente `users.xml` e `inforef.xml` tanto en MBZ tar
  como ZIP. No copia el archive, no lo extrae por completo y no recalcula su
  SHA-256 sellado.
- La evidencia académica efectiva continúa marcada como estricta y la
  verificación posterior no se relaja. El cambio solo corrige la inferencia de
  presencia obligatoria en `users.xml`.

## Caso real

En `COURSE-POSGRADOS-2025-05-02-DIRECTO-9DEF55F74A72`, el usuario 245 aparece
en `discussion.userid` y posee mapping global a `CAN-7DBBA0B17EFB` / target
3853, pero no es participante ni aparece en `users.xml` o `inforef.userref`.
RC21 lo conserva como referencia no transportada y no produce `missing=245`.
El autor efectivo 171, declarado mediante `userref`, permanece exigible.

## Checkpoints y reanudación

- El contrato queda versionado como `phase6-backup-users-v3` y
  `backup_user_contract_version=3`.
- Jobs, checkpoints y manifiestos RC20 se consideran incompatibles y se
  regeneran en etapa 12 desde el plan y el MBZ sellados, sin repetir Fases 1–5.
- Se mantiene el invariante: un checkpoint `referenced` implica siempre una
  clasificación actual, sellada y `blocked=false`.

## Regresión

`tests/rc21-phase6-mbz-user-references.php` y su fixture cubren el caso 245/171,
lectura selectiva tar/ZIP, participantes por usuario y rol, `files`, ambas
semánticas de `course_completions`, usuario activo irresoluble,
`historical_deleted` e invalidación de checkpoints RC20.

Se conserva la suite RC1–RC20. No cambian Fases 1–5, identidad global, OAuth2,
restore, módulos, archivos, categorías, workers, hashes ni verificación final.
