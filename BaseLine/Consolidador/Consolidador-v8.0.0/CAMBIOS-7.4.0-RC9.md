# Consolidador 7.4.0-linux-rc9

Candidata basada en RC8. Corrige el bloqueo de Fase 2 al comparar `moodle_version` de orígenes Moodle 4.5 con versiones como `2024100702.01`.

- `ConvertTo-MoodleVersion` valida por separado versiones de Moodle core y las compara como `System.Decimal` con cultura invariable, sin comparación en coma flotante. Acepta valores enteros y con revisión decimal, numéricos o texto JSON; los inválidos producen `MOODLE_VERSION_INVALID` con componente, campo y valor.
- `ConvertTo-PluginVersion` conserva su contrato estricto `Int64` para versiones de plugins, dependencias y pins.
- La regresión integra dos orígenes reales `posgrados-2025-05-02-directo` y `pregrado-2026-03-04-directo` con versiones `2024100702.01` (número y texto) contra destino `2026042001`: R genera AFTER, diff y validación, alcanza `READY_FOR_APPROVAL`, registra un destino inválido sin perder los artefactos y permite reanudar con BEFORE inmutable.
- Imágenes, identificación del asistente y referencias runtime pasan a RC9; el test de etiquetas rechaza RC1 a RC8.

Se mantienen las pruebas de RC7 y RC8. La integración de Fase 2 se prueba con Docker simulado; para repetir el benchmark hay que comprobarla en el entorno Moodle de destino.
