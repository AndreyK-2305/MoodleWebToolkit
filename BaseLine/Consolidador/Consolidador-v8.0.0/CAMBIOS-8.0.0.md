# Consolidador 8.0.0-linux

V8 se construye sobre `7.4.0-linux-rc24` y conserva sin relajar sus
contratos de importación, OAuth2, identidades, Fases 4–7, restore,
reconciliación académica, checkpoints, seals y recuperación.

## Preparación reproducible

- Se incorpora `config/plugin-compatibility-catalog.json`, semilla
  `catalog-seed-ufps-2026`, con 19 candidatos Moodle 5.2.1 fijados por
  repositorio, commit, versión, release, SHA-256 de árbol, dependencias,
  submódulos y procedencia.
- Cada ejecución produce un `exports/plugin-lock.json` independiente. Una
  entrada conocida evita repetir la búsqueda, pero debe superar verificación
  del artefacto, reconstrucción, upgrade de staging e inventario posterior.
- Los componentes desconocidos continúan requiriendo resolución explícita y
  un pin exacto. El catálogo nunca selecciona `latest`.
- La caché física `cache/plugins/` se puede reutilizar únicamente cuando
  commit, origin, `version.php`, árbol y submódulos coinciden.

## Themes

- Los themes participan en el catálogo como plugins y pueden coexistir.
- La política sellada inicial es `preserve_course_assignments`, theme global
  `boost` y fallback seguro `boost`. Puede ajustarse declarativamente antes de
  preparar la ejecución.
- Se conservan perfiles independientes por fuente y se inventarían, sin
  aplicar automáticamente, las asignaciones de usuario y categoría.
- Las asignaciones explícitas por curso se verifican después del restore: se
  registran como `PRESERVED_BY_RESTORE`, `REAPPLIED` o
  `FALLBACK_TO_GLOBAL`. Una incompatibilidad visual genera
  `WARNING_COURSE_THEME_NOT_APPLIED`, no oculta datos académicos ni bloquea el
  curso.

## Identidades y readiness

- `exports/identity_candidates.csv` compara solo canonicales distintos mediante
  bloques deterministas; una coincidencia ya resuelta no reaparece.
- La revisión opcional permite `MERGE`, `KEEP_SEPARATE` e `IGNORE`. `S` conserva
  el estado `skipped` sin bloquear; `R` produce una decisión auditada.
- Un MERGE vuelve a pasar por el conciliador oficial de Fase 3 y, mediante los
  hashes existentes, invalida y regenera el plan de Fase 4. Nunca se parchean
  `source_user_map.csv` ni otros artefactos derivados.
- `google_sub` diferentes requieren aprobación explícita y solo la identidad
  OAuth seleccionada puede materializarse; las demás quedan como evidencia.
- `exports/readiness.json` sella catálogo, lock, OAuth/proxy, conciliación
  determinística, plan de Fase 4, perfiles/plan de themes y candidatos.
  Fase 4 no escribe hasta obtener `READY_TO_RUN`.

## Cierre de themes y trazabilidad

- El conjunto disponible se obtiene del inventario real del Moodle destino más
  los themes externos sellados en `plugin-lock`; no se limita a `boost`.
- El theme global exige selección explícita de operador o configuración. La
  selección queda ligada al inventario, lock, catálogo, política y destino.
- `theme_inventory_complete` diferencia inventario certificado vacío de
  inventario ausente. El segundo no permite sellar readiness.
- Las asignaciones no transportables separan `NOT_TRANSPORTABLE` de
  `FALLBACK_TO_GLOBAL`; la verificación devuelve `passed` cuando no hay warnings,
  `passed_with_warnings` cuando existen fallbacks y `failed` ante fallos
  funcionales.
- `VERSION.txt` identifica correctamente `7.4.0-linux-rc24` como base real.
- La selección global ya no es solo metadata: el wizard crea un cambio
  administrado atómico para `theme` y `allowcoursethemes`, reinicia, comprueba
  los valores efectivos y reutiliza el rollback del gestor antes de sellar
  `READY_TO_RUN`. Repetir la misma selección es idempotente.
- El normalizador consume por capability el contrato `theme_inventory=1.0`
  del Recolector 7.4.2, cruza el resumen y el inventario individual de cada
  curso, valida la huella semántica y distingue `complete`, `empty`,
  `not_supported`, `not_available`, `legacy_not_available` e `invalid`.
- Los paquetes 7.4.1 conservan compatibilidad mediante una decisión R/C/A
  previa al readiness. Continuar registra aceptación explícita y warning, sin
  afirmar que perfiles o asignaciones ausentes fueron preservados.
- Configuraciones distintas del mismo theme entre fuentes generan
  `WARNING_THEME_PROFILE_COLLISION`; ambos perfiles quedan sellados y la
  consolidación académica no se bloquea.

## Validación

`tests/verify-package.sh` conserva toda la regresión RC1–RC24 y añade pruebas
de catálogo, dependencias, desconocidos, pins, matching determinista, política
de themes, aplicación/rollback efectivo, contratos 7.4.2 y 7.4.1, cruces por
curso, colisiones de perfiles, fallbacks, verificación y tags runtime V8.

Esta distribución no se declara validada por benchmark real hasta ejecutar el
flujo completo con los paquetes institucionales y revisar sus artefactos.
