# Protocolo de auditoría — Consolidador 8.0.0-linux-rc5

## Validación de la distribución

Desde una extracción nueva del ZIP:

```bash
cd Consolidador-v8.0.0-linux-rc5
./tests/verify-package.sh
```

La única salida aceptable termina en `DISTRIBUTION_OK` con código 0. La suite
verifica checksums, PHP, Bash, PowerShell, JSON, YAML, contratos heredados y
las regresiones RC5.

## Evidencia específica RC5

- `tests/v8-rc5-quiz-attempts.php`: matched, proyección 1→0, múltiples residuos,
  MBZ sobrante, activity distinto, preview físico y filtro del productor.
- `tests/v8-rc5-precheck.php`: clasificación ready/rehydrate/block, secuencia
  completa, warnings, cleanup, contrato mutable `stdClass` de `role_mappings`,
  bloqueo fail-closed ante forma inválida y prohibición de mapping temporal
  post-plan.
- `tests/v8-rc5-historical-users.php`: fingerprint estricto, OAuth/custom
  fields, Phase4, normalización de auth, registry y recovery interrumpido.
- `tests/v8-rc5-random-order.php`: top vacío, top con random seguro, contenido
  académico, parent inválido, referencia activa e invariancia set-reference.
- Toda la suite RC1–RC4 continúa ejecutándose sin retirar pruebas.

## Despliegue conservador sobre RC4

Las variables siguientes son ejemplos y deben ajustarse a la instalación:

```bash
old_root=/srv/consolidador-v8.0.0-rc4
new_root=/srv/consolidador-v8.0.0-rc5
dist_root=/srv/consolidador-v8.0.0-rc5-distribucion
cd "$old_root"
./DETENER.sh

test ! -e "$dist_root" && mkdir -p "$dist_root"
unzip /srv/Consolidador-v8.0.0-linux-rc5.zip -d "$dist_root"
cd "$dist_root"
./tests/verify-package.sh

test ! -e "$new_root"
mv "$old_root" "$new_root"
rsync -a \
  --exclude='.env' \
  --exclude='config.yaml' \
  --exclude='config/' \
  --exclude='exports/' \
  --exclude='reports/' \
  --exclude='copias/' \
  --exclude='managed-config/' \
  --exclude='cache/plugins/' \
  "$dist_root/" "$new_root/"
sed -i "s#${old_root}#${new_root}#g" "$new_root/.env"
```

No usar `rsync --delete`. Este procedimiento conserva paquetes, contratos,
planes, jobs, inventarios, checkpoints, estados, configuración y caché de
plugins en el mismo filesystem; no regenera ni copia MBZ.

Validar antes de reanudar:

```bash
cd "$new_root"
test "$(head -n 1 VERSION.txt)" = '8.0.0-linux-rc5'
test -f exports/phase6/course_plan.csv
test -f exports/phase6/batch_manifest.json
test -d exports/packages
./REINICIAR-FASE13.sh ARCHIVAR-FASE13
./INICIAR-CONSOLIDACION.sh
```

`REINICIAR-FASE13.sh` archiva telemetría transitoria y conserva los estados y
checkpoints necesarios para cleanup/reuse/resume sin duplicados. No ejecutar
la consolidación masiva durante el despliegue técnico; la corrida de los 365
cursos pertenece a la auditoría operativa.

## Señales esperadas

- El caso legacy 1→0 registra `status=projected_from_transport`.
- Warnings puros registran `MOODLE_PRECHECK_REHYDRATED` y continúan.
- Un histórico OAuth validado registra `HISTORICAL_USER_AUTH_NORMALIZED`.
- Recovery válido registra `HISTORICAL_AUDIT_RECOVERED` sin segundo restore.
- Top con random seguro registra `LEGACY_RANDOM_QUESTION_NORMALIZED`.
- Diferencias reales, fingerprints ambiguos y referencias activas bloquean.
- El SHA-256 del MBZ fuente permanece idéntico.
