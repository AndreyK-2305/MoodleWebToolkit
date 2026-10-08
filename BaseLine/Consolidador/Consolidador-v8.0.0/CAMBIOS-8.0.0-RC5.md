# Consolidador 8.0.0-linux-rc5

**Candidata para auditoría de Fase 13; no es una versión estable.** RC5 parte
exclusivamente del ZIP RC4 con SHA-256
`3cc84642f34a83c2771f8b6046878add9e804d79a2800255016623d4b86574ce`.
No cambia Fases 1–12, scheduler, workers, plugins, Docker, políticas de
degradación ni el tratamiento `user/icon`.

## Intentos de cuestionario legacy

- Los inventarios nuevos excluyen `qa.preview = 1` desde la consulta fuente.
- Los paquetes legacy se proyectan contra los intentos físicamente
  transportados por el MBZ usando la firma `source_user_id`, `activity_key`,
  `state`, `sumgrades`.
- Solo se permite `inventory_count >= backup_count`; una fila MBZ no explicada
  o un candidato físico `preview != 0` continúa bloqueando.
- `relations.quiz_attempts` y `counts.quiz_attempts` se reconstruyen desde la
  población transportada. La auditoría usa `projected_from_transport` cuando
  excluye residuos legacy.

## Precheck e identidades históricas

- `precheck=false`, cero errores y warnings activa rehidratación explícita de
  `inforef`, usuarios, roles, bancos, categorías y preguntas en el orden del
  restore de Moodle.
- La rehidratación conserva el contrato nativo de Moodle para `role_mappings`
  (`stdClass` con `modified` y `mappings`) y bloquea fail-closed si ese contrato
  no está disponible; no lo convierte a array.
- Una excepción antes de `execute_plan()` limpia las tablas temporales y la
  caché de IDs; después de `execute_plan()` no se consulta `backup_ids_temp`.
- La adopción y recuperación histórica usa un fingerprint persistente estricto:
  clasificación `historical_deleted`, username/email exactos, `deleted=1`,
  host local y ausencia de Phase4, OAuth linked login, `google_sub` y
  `pending_relink`.
- Un histórico eliminado validado con `auth=oauth2` se normaliza a `manual`.
  La ruta `finalizing_interrupted` puede reconstruir registry y auditoría sin
  volver a restaurar el curso.

## Categoría top con `qtype=random`

La jerarquía no se relaja. Sin random se valida inmediatamente. Con candidatos
random se ejecutan primero todas las comprobaciones fail-closed, se retiran
solo residuos demostrablemente seguros en la extracción temporal y después se
valida la jerarquía final. `question_set_references` debe permanecer idéntico.

## Compatibilidad

Los MBZ, inventarios, manifiestos, planes, jobs, resoluciones, checkpoints y
estados válidos anteriores se reutilizan. Ningún artefacto sellado de origen es
reescrito y no se ejecuta una consolidación masiva durante la construcción.
