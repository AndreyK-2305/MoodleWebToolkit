# Protocolo de prueba V8 RC2

## Validación automática de la distribución

Desde la raíz extraída del ZIP final:

```bash
./tests/verify-package.sh
```

El resultado obligatorio termina en:

```text
FILES_SHA256_OK
BASH_LINT_OK
PHP_LINT_OK
PLUGIN_UPGRADE_SERIALIZED_OK
MOD_CHAT_REPLACEMENT_OK
PLUGIN_PIN_EXECUTOR_OK
FUZZY_LINUX_FLOW_OK
PHASE12_LIGHTWEIGHT_OK
WORKER_CONTINUATION_OK
FORUM_NATIVE_RESTORE_OK
RANDOM_NATIVE_RESTORE_OK
PERMISSIONS_OK
BACKGROUND_OK
CHECKPOINT_RESUME_OK
POST_WRITE_MONOTONICITY_OK
RC1_FUNCTIONAL_REGRESSION_OK
RC2_PIPELINE_OK
JSON_YAML_OK
DISTRIBUTION_OK
```

## Contratos automatizados

1. El healthcheck no declara Moodle listo antes de
   `/run/moodle-startup-ready`; el entrypoint y el auditor comparten el lock de
   upgrade.
2. Un fallo de upgrade genera un solo `PLUGIN_UPGRADE_FAILED` con diagnóstico y
   no produce cascadas de versiones o dependencias.
3. `mod_chat` instalado como componente adicional exacto, pinneado, actualizado
   y habilitado termina `REMOVED_CORE_COMPONENT_RESOLVED`.
4. El claim simulado `--pin-verified=1` no autoriza por sí solo: versión,
   release, árbol y submodules se validan en el ejecutor.
5. El flujo fuzzy usa los comandos públicos de Linux y
   `./APLICAR-REVISION-IDENTIDADES.sh`; no exige `pwsh` en el host.
6. Fase 12 no itera profundamente los cursos ni llama al analizador global.
   Publica `BATCH_READY`, `MBZ_DEEP_OPEN_COUNT=0` y `PHASE12_READY`.
7. El worker ejecuta preparación, precheck, restore, verificación y checkpoint
   para un solo curso. La selección foreground/background ocurre tras
   `BATCH_READY` y antes del trabajo largo.
8. Un `SOURCE_DATA_DEFECT` o `MOODLE_RESTORE_INCOMPATIBILITY` aislado queda
   `WAITING_MANUAL`; los cursos siguientes continúan. Solo
   `PRECONDITION_BUG` global o `TOOL_INTERNAL_ERROR` emiten `STOP_ASSIGNING`.
9. Las random questions se observan y se delegan a Moodle sin modificar XML.
10. Un mismatch de `forum_discussions` o `forum_posts` produce
    `STRUCTURAL_OBSERVATION`, `blocking=false` y delegación al restore nativo.
11. Los payloads físicos ausentes mantienen las políticas: Pregrado
    `known_degraded` puede omitir de forma auditada; Posgrados
    `expected_complete` y una fuente `unknown` esperan resolución manual.
12. `course_resolution_sha256` hace que un cambio local reconstruya únicamente
    el afectado; orden, BOM y espacios irrelevantes del CSV no cambian el hash.
13. Permisos `www-data`, OAuth2, identidades, usuarios, roles, themes, piloto,
    background, checkpoints y monotonicidad conservan sus regresiones.

## Integración real obligatoria antes del benchmark

En un host con Docker, Compose, MariaDB/Moodle y los fixtures institucionales:

```bash
V8_RUN_LIVE_INTEGRATION=1 ./tests/v8-live-integration.sh
```

La prueba debe comprobar:

- `qtype_coderunner` y `mod_chat` con concurrencia de upgrade igual a 1,
  `restart_count=0`, `version_db` registrada, Moodle healthy y `mod_chat`
  resuelto;
- fuzzy en Ubuntu sin PowerShell local, con el resultado esperado del fixture
  (`3 MERGE`, `81 IGNORE`, `IDENTITY_REVIEW_IMPORTED`);
- fixture de muchos cursos con `MBZ_DEEP_OPEN_COUNT=0` en Fase 12 y comienzo
  inmediato de `COURSE_1_START`/`COURSE_1_RESTORE_START`;
- backup Moodle 4.5 con random questions: observación, restore y
  `VERIFY_QUIZ_OK`;
- backup Moodle 4.5 con foro, discusiones, posts y adjuntos:
  `STRUCTURAL_OBSERVATION`, restore y `VERIFY_FORUM_OK`.

Si el host o los fixtures no están disponibles, el harness informa
`V8_LIVE_INTEGRATION_NOT_RUN`; ese resultado no autoriza un benchmark real.

## Comprobación operativa

- Confirmar OAuth2, identidades, themes y piloto antes del lote.
- Revisar `exports/phase6/batch_manifest.json` y verificar
  `manifest_status=BATCH_READY` y `worker_course_precheck=true`.
- Revisar los planes conforme cada worker toma su curso; Fase 12 no genera 364
  planes previos. Al cambiar una resolución, reconstruye únicamente el afectado.
- Reanudar una ejecución interrumpida y comprobar que los checkpoints válidos
  se reutilizan.
- Rechazar la entrega si cualquier checksum, lint, contrato o gate falla, o si
  el ZIP final extraído no termina en `DISTRIBUTION_OK`.
