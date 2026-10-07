# Consolidador 8.0.0-linux-rc1

Candidata corregida a partir del primer benchmark real de `8.0.0-linux`.

## Correcciones bloqueantes

1. **Preparar destino**: el runtime del asistente instala dependencias con `DEBIAN_FRONTEND=noninteractive` y `TZ=Etc/UTC`, evitando bloqueos de `tzdata`.
2. **Plugins**:
   - la copia desde caché conserva modos de archivos/directorios;
   - componentes standard/core del Moodle destino pueden resolverse sin inventar pins Git;
   - subplugins físicamente cubiertos por un padre pinneado se resuelven mediante ese padre;
   - el sellado persiste `staging_validation=passed` sobre el arreglo real del lock;
   - módulos `mod_*` usados por cursos y encontrados `installed_disabled` se habilitan mediante la API de Moodle antes del inventario AFTER;
   - un módulo utilizado y deshabilitado ya no puede quedar `satisfied_by_target` ni alcanzar `READY_TO_RUN`.
3. **Conciliación fuzzy**:
   - CSV con BOM UTF-8 se interpreta correctamente;
   - una revisión `MERGE|KEEP_SEPARATE|IGNORE` obliga a salir de la etapa de readiness y reanudar desde el bucle principal;
   - al aplicar decisiones se invalida un readiness pre-write obsoleto;
   - se documenta que `canonical_target` es una cuenta origen `source:user_id`, no un `CAN-*`.
4. **OAuth pre-Fase 4**: la revalidación inmediatamente anterior a aplicar usuarios escribe en `exports/oauth2-live-pre-phase4/` y no sobrescribe el `oauth2-live/validation.json` sellado por readiness.
5. **Reanudación post-write**:
   - `destination-write.lock.json` sella también el SHA-256 de `exports/readiness.json`;
   - después de la primera escritura el workflow tiene un piso monotónico en Fase 4;
   - el wizard no vuelve automáticamente a importación, OAuth, plugins, conciliación, plan de usuarios ni readiness;
   - cualquier alteración de config/OAuth/readiness post-write bloquea con `POST_WRITE_ANCHOR_INVALID` en vez de regenerar etapas pre-write.

## Regresiones añadidas

- `tests/v8-rc1-regressions.php`
- `tests/v8-rc1-static.py`

## Benchmark requerido

Ejecutar desde destino limpio con los mismos paquetes reales utilizados en la candidata anterior. No reutilizar `exports/`, `reports/`, volúmenes Docker ni checkpoints del benchmark previo.
