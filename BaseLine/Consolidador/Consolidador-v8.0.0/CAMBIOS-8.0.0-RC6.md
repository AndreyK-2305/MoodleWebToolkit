# Consolidador 8.0.0-linux-rc6

**Candidata técnica para revalidación E2E desde paquete limpio; no es una
versión estable.** RC6 consolida las correcciones aplicadas y validadas durante
el benchmark E2E completo ejecutado sobre RC5 parcheada.

La ejecución de referencia completó el lote con 365/365 cursos, conservó el
piloto, verificó el lote sin diferencias y generó correctamente el cierre y la
copia integral del Moodle consolidado. RC6 debe reproducir ese resultado desde
su distribución limpia antes de considerarse aceptada.

## Resolución de plugins y componentes core

- La validación de componentes acepta dígitos en el prefijo del tipo de plugin,
  permitiendo componentes core válidos como `h5plib_v128`.
- `target-plugin-pin.php`, `target-plugins.php` y
  `docker/verify-plugin-pins.php` comparten el contrato corregido.
- `h5plib_v128` se resuelve como componente core en `h5p/h5plib/v128` y no
  requiere un plugin externo fabricado.

## Cuestionarios y XML de gran tamaño

- Los valores `$@NULL@$` y vacíos de `sumgrades` se interpretan como `null`;
  cualquier otro valor debe ser numérico.
- Los documentos XML relevantes se cargan con `LIBXML_PARSEHUGE` además de
  `LIBXML_NONET`, evitando falsos fallos por nodos de texto grandes.
- La normalización legacy de `qtype=random` permanece fail-closed y conserva
  invariantes de `question_set_references`.

## Degradación controlada de archivos

- Los payloads físicamente ausentes de `user/icon` se clasifican como warning
  no académico y pueden omitirse de forma auditada.
- Cualquier otro payload ausente continúa bloqueando salvo que exista una ruta
  de degradación explícitamente aprobada.
- La comparación de IDs aprobados se realiza en orden numérico para no tratar
  como faltante un archivo presente únicamente por diferencia de orden en
  `files.xml`.
- El detalle por archivo omitido permanece sellado en
  `exports/phase6/course-degradation-plans`.
- La consola deja de imprimir una línea `WARNING_FILE_PAYLOAD_OMITTED` por cada
  archivo y conserva el resumen `COURSE_RESTORE_WARNING`; las observaciones
  estructurales continúan visibles.

## Alias locales de archivos

- Los aliases de repositorio `local` cuyo destino lógico ya no existe pueden
  materializarse como archivo regular únicamente cuando el payload físico está
  presente y sus metadatos verifican.
- Los aliases resolubles de forma nativa permanecen sin cambios.
- Inconsistencias de tamaño, hash o referencia continúan bloqueando.

## Usuarios históricos y foros

- La firma comparable histórica normaliza únicamente whitespace final del
  `subject` en `forum_discussions` y `forum_posts`.
- No se modifican inventarios, MBZ, base de datos ni artefactos sellados.
- Diferencias reales de contenido continúan detectándose.

## Enrutamiento de fuentes

- `Common.ps1` deja de reutilizar variables top-level cuyo nombre colisionaba,
  por insensibilidad a mayúsculas de PowerShell, con parámetros del worker
  como `SourceId` y `TargetId`.
- El scheduler conserva el `source_id` sellado del curso sin depender de
  correcciones defensivas en operación normal.
- La defensa `WORKER_SOURCE_CORRECTED` se mantiene como protección ante una
  discrepancia real.

## Verificación y cierre

- Fase 14 toma `source_state_sha256` del `course_job` sellado, que es el
  contrato vigente del manifest ligero V8.
- Fase 15 deja de exigir `plan_sha256` en `verification.json` de Fase 4, porque
  ese productor nunca declara el campo; la cadena permanece protegida mediante
  `apply_summary.json`, mapas y CSV de verificación.
- El cierre reconoce el manifest ligero de Fase 6:
  `schema_version=1.1`, `phase=6-lightweight-batch-manifest`,
  `manifest_status=BATCH_READY`, precheck por worker y extracción única.
- El manifest representa el lote previo a workers; la convergencia final se
  valida mediante los resúmenes de aplicación y verificación.

## Copia integral del sitio

- `mariadb-dump` usa explícitamente `--max-allowed-packet=1G`.
- Esto evita cortes del cliente al exportar registros mayores que su límite
  predeterminado de 16 MiB, manteniendo `--single-transaction`, `--quick`,
  `--skip-lock-tables`, `--hex-blob` y `pipefail`.
- El modo de mantenimiento continúa restaurándose incluso ante un fallo de
  exportación.

## Limpieza de distribución

- `config/fuzzy_identity_resolutions.csv` vuelve a la plantilla sin decisiones
  de una ejecución anterior.
- `config/theme-policy.json` vuelve a la política inicial sin selección del
  operador ni aceptación legacy heredada.
- `docker/custom-plugins/approved-plugins.json` vuelve al manifiesto vacío y
  los checkouts generados durante readiness no forman parte del paquete.
- `.env`, `managed-config`, caches, exports, reportes de ejecución, backups
  `.bak*` y paquetes de origen no forman parte de la distribución limpia.
- Se conservan únicamente los archivos auxiliares necesarios para mantener la
  estructura esperada de `copias`, `cache`, `exports` y `reports`.

## Estado de validación

Las correcciones anteriores fueron validadas durante una ejecución E2E completa
sobre RC5 parcheada. La sintaxis de los PHP y PowerShell modificados fue
revalidada durante la preparación de RC6.

La aceptación de RC6 requiere una nueva ejecución desde el paquete limpio y sin
reutilizar estado generado por la ejecución de referencia.
