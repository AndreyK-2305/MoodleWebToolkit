# Consolidador 7.4.0-linux-rc3

**Candidata técnica. No es versión estable ni está declarada apta para el benchmark real.** Parte de RC2 sin sustituirla y conserva todas sus correcciones, contratos y fixtures.

## Intervención de plugins

1. El asistente valida primero la URL pública HTTPS, el reverse proxy y Google OAuth2; enseguida prepara el inventario de plugins, antes de Fase 3.
2. Identifica necesidades funcionales por módulo utilizado o plugin adicional de cada fuente. Registra `plugin_inventory_before.json` y `plugin_compatibility_needs.json` inmutables. Si hay necesidades, pasa a `WAITING_USER_ACTION`. Una cuenta de plugins instalada pero deshabilitada es una advertencia funcional, no una ausencia automática.
3. El administrador instala versiones o sustitutos compatibles con Moodle 5.2.1; `[R]` ejecuta el upgrade CLI, consulta el inventario posterior y presenta diferencias. No exige igualdad de nombre ni cantidad entre los plugins de origen y destino. `[Y]` solo está disponible tras superar los controles técnicos; `[N]` pausa. Un error técnico produce `PLUGIN_TECHNICAL_VALIDATION_FAILED` y nunca crea una aprobación.
4. La aprobación se guarda una sola vez en `plugin_intervention_resolution.json`, vinculada a configuración, fuentes, destino, pins, inventarios antes/después y huella del código. Un cambio invalida la aprobación y archiva el contrato previo para iniciar una nueva revisión. Una pausa anterior reutiliza el inventario original sin rehacer OAuth.
5. Puede documentar equivalencias en `config/plugin_equivalences.csv` con `source_requirement,target_components`. El registro es opcional y jamás se infiere de nombres similares. Es posible añadirlo durante la pausa sin regenerar BEFORE.
6. Los plugins empaquetados siguen sujetos a la verificación de versión, release, commit, árbol y submódulos recursivos en la construcción de la imagen y al control de integridad en el inventario. Los plugins instalados manualmente se registran por versión, release y SHA-256 completo del árbol; el volumen conserva su código entre reinicios. Una modificación del árbol exige nueva aprobación.
7. El cierre de fase 7 incorpora el inventario, las necesidades, las diferencias, la validación y la resolución selladas.
8. Tras aprobar plugins se consulta de nuevo el estado público de OAuth y del proxy antes de Fase 3. Fase 4 repite la comprobación en vivo inmediatamente antes de escribir usuarios; el issuer debe seguir siendo el aprobado. Una pausa en `WAITING_USER_ACTION` no repite estas comprobaciones.

## Regresiones

### Preparación para repetir el benchmark

- Una diferencia de `quiz_attempts` genera `quiz_attempt_differences.json` en el piloto o `restore-diagnostics/quiz_attempt_differences-<curso>.json` en el lote. Distingue `missing`, `extra` y `changed`; consigna curso, módulo, actividad, identidad, quiz, número, estado, fechas, nota, preview e IDs/candidatos. La comparación incluye los intentos aunque los conteos sean iguales. Un fallo tras la restauración conserva el curso como `verification_failed`, con el reporte y SHA-256 en el diagnóstico.
- Los usuarios `historical_deleted` se registran por fuente e ID en `historical-users/` y, por cada curso, en `historical-audits/`. El registro guarda el ID de destino que Moodle realmente asignó a `backup_ids/user`; el precheck reutiliza el mismo ID en cursos posteriores bajo un bloqueo común de workers. Antes de aprobar el curso se verifican estado eliminado, ausencia de OAuth y `pending_relink`, ausencia de fusión con Phase4, y referencias académicas por usuario. Las incidencias `historical_user_reactivated`, `historical_user_duplicated` y `historical_history_lost` bloquean la verificación y preservan el curso restaurado para examen.
- Los fixtures ahora cubren 487 intentos origen frente a 486 destino e identifican el intento concreto; tres MBZ con el mismo usuario eliminado; y la detección de reactivación, duplicación, fusión y pérdida de aportes académicos. La lógica que aplica el mapa interno de Moodle necesita además validación en el destino real del benchmark.

`tests/verify-package.sh` conserva los tests y los 206 casos `pending_relink` de RC2, comprueba la sintaxis de todos los PHP y PowerShell, Bash, JSON, YAML y hashes. RC3 añade casos de 3 necesidades a 2 o 5 reemplazos, nombres distintos, módulo deshabilitado, versión/plugin incompatible, dependencias inválidas, `version.php` inválido, upgrade fallido, reanudación antes/después de la aprobación, equivalencia declarada durante la pausa y alteración de artefactos sellados. El test de flujo usa un Docker simulado; no reemplaza la ejecución en un Moodle real.

La entrega requiere ejecutar el verificador sobre el **ZIP final extraído** y observar `DISTRIBUTION_OK` con código 0. La prueba de extremo a extremo con Moodle y los dos paquetes de origen reales permanece pendiente.
