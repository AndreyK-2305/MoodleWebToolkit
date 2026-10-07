# Consolidador 7.4.0-linux-rc1

Esta candidata parte del código distribuido como 7.3.0-linux. No se ha ejecutado aún la prueba real con los dos ZIP del Recolector, ni se ha verificado el cierre de los 365 cursos. Por tanto, **no es una versión estable aprobada**.

## Contratos incorporados

- En preparación de Fase 6 se lee únicamente `users.xml` de cada MBZ. Se registra en el job sellado la distinción entre participantes académicos, auxiliares con mapa global, usuarios eliminados, `guest` y referencias activas sin mapa. Se comprueba el mismo contrato contra la extracción antes de modificarla. Los MBZ originales se leen sin cambiarlos ni volver a copiarse.
- El preflight recorre las clasificaciones selladas de todos los cursos y bloquea cuentas activas sin mapa o participantes ausentes antes de crear categorías. Informa el curso y los identificadores de origen afectados.
- Cada worker comprueba los hashes del contrato de Fase 4 y consulta su mapa global cuando el usuario no figura en el subconjunto del job. En particular, `pregrado:540 → 3899` ya no se interpreta como identidad inexistente.
- Los registros `deleted=1` sin mapa se conservan en `users.xml` con su estado original, sin inventar `google_sub` ni matricularlos. **Debe probarse en Moodle 5.2 real** que el mismo histórico en varios MBZ no genere cuentas activas ni duplicados.
- Si Moodle termina el restore y falla la verificación, se conserva el curso, queda `verification_failed` con diagnóstico y la reanudación vuelve a verificarlo. Los restos de un restore incompleto siguen sujetándose a limpieza controlada.
- La comparación de `course_completions` cuenta finalizaciones efectivas. Las filas cuyo `completed=false` son seguimiento y no equivalen a un curso completado.
- Una diferencia en `quiz_attempts` produce firmas por actividad, usuario destino, estado y nota; informa candidatos y ambigüedad por duplicados. La diferencia real `487/486` permanece **sin resolución** hasta investigarla con los paquetes reales. Los inventarios originales antiguos pueden carecer de número de intento y tiempos.
- El asistente bloquea la regeneración silenciosa de un piloto ya aplicado/verificado o de un plan masivo sellado si cambia su evidencia. El valor de workers solicitado se propaga explícitamente al contenedor; `auto` considera CPU y memoria, y un valor manual de `2` se mantiene en `2`.
- Los permisos de exportación mantienen el propietario del host, grupo compartido `www-data` y escritura para ambos. El proceso usa `umask 0007` para los artefactos nuevos.

## Pruebas y uso

La prueba de contratos está en `tests/contracts.php`. Ejecutar `bash tests/verify-package.sh` **sobre el ZIP extraído** con PHP CLI, extensiones DOM/XMLReader/Zip, PowerShell, Python con PyYAML y opcionalmente ShellCheck. El verificador revisa checksums, sintaxis, contratos y estructura. No ejecutar esta candidata sobre el destino ni los checkpoints de 7.3.0.

La aceptación requiere un Moodle 5.2 limpio y los ZIP originales del Recolector `posgrados-2025-05-02-directo` y `pregrado-2026-03-04-directo`, sin regenerarlos. La prueba debe llegar a Fase 6 y resolver la diferencia de cuestionario antes de llamar estable a una versión. Si hay nuevas correcciones, conservar el destino y sus checkpoints solo cuando el contrato de esa ejecución siga íntegro.

## Pendientes expresos

Este corte no implementa todavía el rediseño completo de plugins y OAuth, los casos manuales de identidad de Fase 3, ni las mejoras de S3/SSM, reintentos, correo o duración de 24 horas. El comportamiento de restauración de históricos eliminados y la diferencia `quiz_attempts=487/486` requieren validación real. No inferir su aprobación de los fixtures sintéticos.
