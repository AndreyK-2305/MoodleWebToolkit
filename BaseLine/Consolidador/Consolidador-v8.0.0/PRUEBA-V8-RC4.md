# Protocolo de auditoría — Consolidador 8.0.0-linux-rc4

## Validación de la distribución

Desde una extracción nueva del ZIP:

```bash
cd Consolidador-v8.0.0-linux-rc4
./tests/verify-package.sh
```

La salida aceptable termina en `DISTRIBUTION_OK` y código 0. La suite incluye
lint PHP/Bash/PowerShell, contratos heredados, checksums, JSON/YAML y las
regresiones RC4.

## Evidencia RC4 específica

- `tests/v8-rc4-plugin-paths.php`: `mod`, `block`, `qtype`, `local`, `auth`,
  `enrol`, `filter`, `theme`, componente core, ausente y desconocido.
- `tests/v8-rc4-plugin-location.ps1`: el preflight técnico acepta ubicaciones
  reales y bloquea `declared_missing`/`unknown_component`.
- `tests/v8-rc4-source-routing.ps1`: 40 jobs intercalados, dos sources,
  cuatro workers, cero correcciones normales y defensa HF3 activa.
- `tests/v8-rc4-random-cases.php`: casos observados de 1, 20 y 80 random,
  cardinalidad/invariancia de referencias y MBZ fuente sin cambios.
- `tests/rc22-legacy-random-normalization.php` y
  `tests/rc23-random-normalization.php`: referencia activa, entrada mixta,
  filtros inválidos, semántica contradictoria, cardinalidad incorrecta,
  múltiples quizzes y validación posterior.
- `tests/v8-rc4-hotfix-contracts.py`: HF2, HF3, HF4 y HF5.
- `tests/v8-rc4-phase13-restart.sh`: archivo no destructivo de transitorios y
  conservación de contratos/checkpoints.

## Despliegue sobre el entorno actual

1. Definir las rutas y detener la ejecución actual:

   ```bash
   old_root=/srv/consolidador-v8.0.0-rc3
   new_root=/srv/consolidador-v8.0.0-rc4
   dist_root=/srv/consolidador-v8.0.0-rc4-distribucion
   cd "$old_root"
   ./DETENER.sh
   ```

2. Extraer y validar una copia limpia de la distribución antes de tocar el
   entorno con estado:

   ```bash
   test ! -e "$dist_root" && mkdir -p "$dist_root"
   unzip /srv/Consolidador-v8.0.0-linux-rc4.zip -d "$dist_root"
   cd "$dist_root"
   ./tests/verify-package.sh
   sha256sum -c FILES.sha256
   ```

3. Renombrar el entorno existente y superponer únicamente el código RC4. Las
   exclusiones conservan configuración, paquetes, contratos, resoluciones,
   checkpoints, reports y caché de plugins exactamente en el mismo filesystem;
   no hay copia de los MBZ:

   ```bash
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

   `rsync` no debe ejecutarse con `--delete`. El cambio de ruta de `.env` solo
   ajusta referencias absolutas al directorio renombrado; no cambia contratos
   de Fase 1–12.

4. Comprobar que el entorno operativo conserva los artefactos y ejecuta código
   RC4:

   ```bash
   cd "$new_root"
   test "$(head -n 1 VERSION.txt)" = '8.0.0-linux-rc4'
   test -f exports/phase6/course_plan.csv
   test -f exports/phase6/batch_manifest.json
   test -d exports/packages
   ```

5. Archivar únicamente la telemetría transitoria de la ejecución fallida:

   ```bash
   ./REINICIAR-FASE13.sh ARCHIVAR-FASE13
   ```

   El comando no mueve `apply-states` ni `apply-checkpoints`, porque contienen
   la información requerida para cleanup, reuse y resume sin duplicados.

6. Reanudar el asistente:

   ```bash
   ./INICIAR-CONSOLIDACION.sh
   ```

   Debe reconocer las fases anteriores, entrar en Fase 13, reutilizar los
   checkpoints válidos y ejecutar solo los cursos pendientes.

## Señales de aceptación durante la auditoría real

- Cero `WORKER_SOURCE_CORRECTED` en operación normal.
- Cero omisiones por `El curso no pertenece al lote restaurable` causadas por
  SourceId incorrecto.
- Los cursos random seguros emiten `LEGACY_RANDOM_QUESTION_NORMALIZED`.
- Nunca se emite `DELEGATED_TO_MOODLE_NATIVE_RESTORE` para `qtype=random`.
- Los casos ambiguos quedan `WAITING_MANUAL` con causa concreta.
- El SHA-256 de cada MBZ fuente coincide antes y después.
- Los cursos con checkpoint válido se reportan como `status=reused` y no se
  crean duplicados.

No ejecutar la consolidación masiva como parte de la construcción del paquete;
esa corrida pertenece a la auditoría operativa posterior.
