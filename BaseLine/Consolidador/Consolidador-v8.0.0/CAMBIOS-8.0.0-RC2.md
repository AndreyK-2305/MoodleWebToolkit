# Consolidador 8.0.0 Linux RC2

Base: `8.0.0-linux-rc1`.

Esta candidata aplica correcciones localizadas al flujo V8. Mantiene los
paquetes fuente y MBZ inmutables, `DestinationWriteLockPath`, la monotonicidad
post-write, OAuth2, identidades, roles, themes, piloto, pins, hashes y
checkpoints aprobados.

## Correcciones base de RC2

1. Centraliza `destination-write.lock.json` en `ConfigAccess.ps1` y conserva el
   piso monotónico desde la primera escritura.
2. Acepta evidencia OAuth derivada solo bajo `/exports`, con whitelist y
   protección de traversal.
3. Prepara y prueba el transporte de themes con los permisos mínimos del
   consumidor real `www-data`, sin `chmod 777`.
4. Mantiene la selección foreground/background, el handoff reanudable y el
   bloqueo de ejecuciones duplicadas.
5. Conserva la verificación defensiva del pin dentro de
   `target-enable-plugin.php`; `--pin-verified=1` es solo un dato diagnóstico.
6. Mantiene hashes locales de resoluciones mediante `course_resolution_sha256`
   y políticas `known_degraded`, `expected_complete` y `unknown`.

## Ajustes posteriores a revisión RC2

### Upgrade Moodle serializado

- El entrypoint elimina y publica `/run/moodle-startup-ready` alrededor de la
  instalación o upgrade. El healthcheck exige el marker y que `config.php`
  cargue correctamente.
- Tanto el entrypoint como cualquier upgrade posterior usan
  `/run/moodle-upgrade.lock` con `flock`.
- El auditor espera readiness, consulta `upgrade.php --is-pending` y solo
  ejecuta el upgrade serializado si Moodle lo requiere.
- Un error termina inmediatamente en `PLUGIN_UPGRADE_FAILED` y conserva primer
  error, componente, exit code, stdout, stderr, restart count y estado Moodle.

### Core retirado y plugins adicionales

- Una necesidad `removed_core_component` queda
  `REMOVED_CORE_COMPONENT_RESOLVED` cuando existe el mismo componente adicional,
  coincide exactamente con el pin aprobado, su upgrade está completo y queda
  habilitado.
- El caso `mod_chat` emite `PLUGIN_REPLACEMENT_FOUND`,
  `TARGET_PLUGIN_PIN_VERIFIED`, `TARGET_PLUGIN_ENABLE_OK` y
  `REMOVED_CORE_COMPONENT_RESOLVED`.
- La versión, release, commit, `tree_sha256` y submódulos siguen comprobándose
  dentro del ejecutor antes de habilitar un plugin adicional.

### Revisión fuzzy operable desde Linux

- El wizard presenta `[P] Preparar`, `[I] Importar` y `[S] Salir`.
- `./APLICAR-REVISION-IDENTIDADES.sh` ejecuta la importación dentro de
  `assistant-runtime`; el host Ubuntu no necesita `pwsh`.
- Al importar, Fase 3 y Fase 4 recalculan sus hashes y planes dependientes.

### Fase 12 ligera y precheck por curso

- Fase 12 crea el manifiesto ligero, valida referencias, sellos disponibles,
  políticas, espacio, permisos, workers y checkpoints. Termina con
  `BATCH_READY`, `MBZ_DEEP_OPEN_COUNT=0` y `PHASE12_READY`.
- Fase 12 no llama globalmente a `phase6-prepare-package-course.php` ni a
  `phase6-analyze-degradations.php`.
- Cada worker prepara, indexa y analiza exclusivamente el curso que toma;
  después ejecuta el restore nativo, verifica y escribe su checkpoint.
- Los planes conservan hashes por curso. Una resolución local reconstruye
  únicamente el afectado y un cambio de política alcanza solo esa fuente.

### Degradaciones físicas y diferencias estructurales

- Un payload físico ausente en una fuente `known_degraded` se omite de forma
  puntual y auditable. En `expected_complete` o `unknown` queda
  `WAITING_MANUAL`, salvo resolución exacta con curso y `file_id`.
- Las diferencias transformables de foros, asignaciones, cuestionarios,
  question bank e IDs producen `STRUCTURAL_OBSERVATION`, `blocking=false` y
  `action=delegate_to_moodle_native_restore`.
- Random questions se observan mediante
  `p6_observe_legacy_random_questions()` y se delegan al restore nativo; el
  pipeline activo no llama a `p6_normalize_legacy_random_questions()`.

### Continuación de workers

- Cada worker devuelve `SUCCESS`, `WARNING`, `WAITING_MANUAL` o
  `FATAL_SYSTEMIC`, junto con `SOURCE_DATA_DEFECT`,
  `MOODLE_RESTORE_INCOMPATIBILITY`, `PRECONDITION_BUG` o
  `TOOL_INTERNAL_ERROR`.
- Un fallo aislado deja ese curso en `WAITING_MANUAL` y permite que continúen
  los demás. Solo un fallo sistémico emite `STOP_ASSIGNING`.

## Compatibilidad y validación

Los checkpoints válidos se reutilizan cuando sus entradas y sellos coinciden.
La distribución se entrega solo después de ejecutar
`tests/verify-package.sh` sobre el ZIP final extraído y obtener
`DISTRIBUTION_OK`. La integración real Docker/MariaDB se ejecuta con
`V8_RUN_LIVE_INTEGRATION=1 tests/v8-live-integration.sh`; exige Moodle real y
los fixtures reales de plugins, fuzzy, random questions y foros.
