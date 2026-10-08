# Prueba de aceptación — 8.0.0-linux-rc1

Ejecutar esta candidata **desde un destino limpio**. No copiar `exports/`, `reports/`, `.env`, volúmenes ni checkpoints de la prueba anterior.

## Casos de regresión obligatorios

1. **Preparar destino** debe completar sin interacción de `tzdata`.
2. **Plugins** debe:
   - instalar los 19 candidatos pinneados;
   - resolver subplugins por su padre cuando corresponda;
   - habilitar automáticamente módulos standard/core usados y encontrados deshabilitados (casos observados: `mod_bigbluebuttonbn` y `mod_chat`);
   - bloquear `READY_TO_RUN` si cualquier módulo usado sigue deshabilitado.
3. **Conciliación fuzzy**: seleccionar revisión, importar al menos un `MERGE` y varios `IGNORE`, reanudar y comprobar que Fase 3/Fase 4 se regeneran sin tener que forzar manualmente un rewind.
4. **Primera escritura**: después de aplicar usuarios, el nuevo `reports/destination-write.lock.json` debe contener `readiness_sha256` y `workflow_floor=phase4-users`.
5. **Reanudación**: después de Fase 6 y después de Fase 8 el wizard no debe regresar a plugins, OAuth ni `05b-readiness`.
6. **OAuth pre-Fase 4**: debe existir `exports/oauth2-live-pre-phase4/validation.json`; el SHA-256 de `exports/oauth2-live/validation.json` debe seguir coincidiendo con `exports/readiness.json`.
7. Continuar hasta piloto, lote completo, verificación y cierre para aceptar la RC.

## Criterio de corte

Si una etapa post-write detecta drift en una entrada sellada pre-write, debe bloquear con `POST_WRITE_ANCHOR_INVALID`; nunca debe regenerar automáticamente plugins/OAuth/readiness.
