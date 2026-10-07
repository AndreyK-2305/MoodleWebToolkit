# Evidencia de entrega — Consolidador 8.0.0-linux-rc4

## Trazabilidad

- Base recibida: `Consolidador-v8.0.0-linux-rc2 (3)(1).zip`.
- SHA-256 base:
  `e048910e14005765ef9cecf7d21762c91bfb9a20931bd4310581aaca2e4e705a`.
- La base no contenía `.git`; por tanto, la entrega se identifica mediante el
  SHA-256 del ZIP y su sidecar, no mediante un commit inventado.
- Versión final: `8.0.0-linux-rc4`.

## Defectos corregidos

1. Plugins: el falso missing provenía de concatenar un pin `public/...` sobre
   un `$CFG->dirroot` que ya terminaba en `public`. RC4 usa el `rootdir` real
   entregado por Moodle, normaliza el único prefijo coincidente y conserva la
   verificación exacta de pin, versión, release, árbol y submódulos.
2. SourceId: el scheduler confiaba en `source` del objeto del manifiesto al
   crear cada proceso. RC4 construye un mapa inmutable desde `course_plan.csv`,
   valida el manifiesto y pasa el SourceId explícito. HF3 queda en el worker
   como invariante de última línea.
3. `qtype=random`: la ruta activa solo observaba y delegaba a Moodle 5.2 un
   backup híbrido declarado 4.5. RC4 ejecuta el normalizador conservador sobre
   la extracción temporal y bloquea cualquier caso no demostrablemente seguro.

## Hotfixes portados desde las pruebas RC3

- HF2: `plan_sha256` se toma de `COURSE_PRECHECK_OK`; no hay rehash host.
- HF3: comparación defensiva con `course_plan.csv` y evento auditable.
- HF4: `source_state_sha256` proviene del `course_job` firmado.
- HF5: `COURSE_PRECHECK_BLOCKED` expone `first_blocking` sin relajar el bloqueo.

## Evidencia automatizada

```text
V8_RC4_PLUGIN_PATHS_OK types=8 core=1 missing=1 unknown=1 pin_public=1
V8_RC4_PLUGIN_LOCATION_OK core=1 additional=1 missing=blocked unknown=blocked
V8_RC4_SOURCE_ROUTING_OK sources=2 jobs=40 workers=4 corrections_normal=0 defense=1
V8_RC4_RANDOM_CASES_OK case1=1 case20=20 case80=80 sealed_mbz=unchanged
V8_RC4_HOTFIX_CONTRACTS_OK hf2=1 hf3=1 hf4=1 hf5=1
V8_RC4_PHASE13_RESTART_OK archived=transient preserved=packages,contracts,states,checkpoints sealed=blocked
DISTRIBUTION_OK
```

La integración live queda deliberadamente sin ejecutar durante la construcción:
`V8_LIVE_INTEGRATION_NOT_RUN reason=V8_RUN_LIVE_INTEGRATION_not_1`. La corrida
de los 365 cursos corresponde a la auditoría posterior solicitada.

## Archivos añadidos o modificados frente a la base

- `AUDITORIA-8.0.0-RC4.md`
- `CAMBIOS-8.0.0-RC4.md`
- `FILES.sha256`
- `PRUEBA-V8-RC4.md`
- `README.md`
- `REINICIAR-FASE13.sh`
- `VERSION.txt`
- `docker/managed-config.php`
- `moodle-consolidation.sh`
- `scripts/Notifications.ps1`
- `scripts/Phase6SourceRouting.ps1`
- `scripts/PluginIntervention.ps1`
- `scripts/consolidation-wizard.ps1`
- `scripts/export-consolidated-site.ps1`
- `scripts/phase6-analyze-course.php`
- `scripts/phase6-apply-course.php`
- `scripts/phase6-apply.ps1`
- `scripts/phase6-course-worker.ps1`
- `scripts/phase6-lib.php`
- `scripts/phase6-random-normalization.php`
- `scripts/target-plugin-pin.php`
- `scripts/target-plugins.php`
- `scripts/v8-preparation.php`
- `tests/v8-live-integration.sh`
- `tests/v8-native-restore-delegation.php`
- `tests/v8-rc2-plan-contracts.py`
- `tests/v8-rc2-static.py`
- `tests/v8-rc4-hotfix-contracts.py`
- `tests/v8-rc4-phase13-restart.sh`
- `tests/v8-rc4-plugin-location.ps1`
- `tests/v8-rc4-plugin-paths.php`
- `tests/v8-rc4-random-cases.php`
- `tests/v8-rc4-source-routing.ps1`
- `tests/v8-runtime-tags.py`
- `tests/verify-package.sh`

No se modificaron MBZ, exports, inventarios, jobs, manifiestos, planes,
resoluciones, checkpoints ni datos del Moodle destino durante la construcción.
