# Consolidador 7.4.0-linux-rc8

Candidata basada en RC7. Corrige el bloqueo previo a Fase 1 introducido por la comprobación del montaje de `assistant-runtime`.

- El servicio está en `profiles: ["tools"]`. La validación consulta `docker compose --profile tools config --format json` y requiere su presencia en ese modelo.
- Comprueba que una misma entrada de bind tenga `source` y `target` iguales a la raíz del paquete invocado, y verifica `working_dir` y `environment.ASSISTANT_PROJECT_ROOT`. Un servicio ausente tras activar `tools` produce `ASSISTANT_RUNTIME_MISSING`.
- El test de arranque simula Compose sin profiles, los cinco casos de montaje incorrecto o servicio ausente, una variable heredada de otra instalación y el ingreso efectivo a Fase 1 con Compose simulado.
- Las imágenes target y assistant y los identificadores runtime pasan a RC8. La distribución bloquea etiquetas activas RC1 a RC7.

Se preservan los contratos de RC7 y las pruebas de las versiones anteriores. El test del launcher utiliza un Docker simulado; la verificación del Docker Engine real y el benchmark deben hacerse en la instalación de destino.
