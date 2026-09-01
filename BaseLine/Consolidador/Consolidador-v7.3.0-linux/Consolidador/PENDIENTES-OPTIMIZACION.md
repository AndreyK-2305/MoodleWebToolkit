# Seguimiento de optimización

Esta RC incorpora el rediseño de mayor impacto: extracción única, eliminación
de copias de `.mbz`, pool dinámico, trabajos por curso, menos recorridos SHA,
verificación incremental, scripts montados una vez y compresión multihilo.

Los siguientes puntos quedan deliberadamente fuera de `7.3.0-linux`:

1. **Caché persistente entre lotes.** Reutilizar restauraciones, inventarios o
   transformaciones cuando se agregue otro ZIP en una ejecución futura. Requiere
   definir invalidación por versión de Moodle, configuración, plan de identidad,
   plugins y SHA de cada fuente. Es opcional y debe implementarse al final.
2. **Auditoría exhaustiva bajo demanda.** La verificación normal reutiliza el
   inventario exhaustivo sellado por cada curso. Puede añadirse un modo
   `--full-audit` que recalcule todos los inventarios al cierre para controles
   extraordinarios, aceptando el costo adicional.
3. **Control adaptativo de workers.** Reducir o aumentar concurrencia según RAM,
   espera de I/O, conexiones y latencia de MariaDB. Esta RC usa `auto` por CPU o
   un valor explícito estable de 1 a 4.
4. **Planificador con historial real.** El orden actual estima peso con bytes y
   conteos académicos. Una ejecución posterior podría aprender de duraciones
   reales por tipo de curso y ajustar el orden.
5. **Paralelismo fuera de una sola instancia.** Distribuir cursos entre varios
   hosts exige coordinación de base de datos, `moodledata`, locks y checkpoints;
   no se recomienda hasta validar primero el pool local.
6. **Optimización administrativa de MariaDB y almacenamiento.** Buffer pool,
   IOPS, latencia, límites de conexiones y parámetros de restore dependen del
   servidor institucional y deben medirse con el administrador.
7. **Benchmark institucional reproducible.** Registrar por curso extracción,
   normalización, precheck, restore, inventario y verificación para comparar
   `workers=1..4` con los mismos paquetes y la misma instancia.

## Criterio para promover la RC

- Ejecutar un laboratorio limpio y una reanudación después de matar un worker.
- Confirmar que no aparecen `backups/raw` ni `backups/normalized` en fase 6.
- Confirmar un solo evento de extracción por curso y cero cursos duplicados.
- Comparar tiempo, espacio máximo, CPU, RAM, I/O y conexiones para 1, 2 y 4
  workers.
- Restaurar la copia integral de fase 8 en un entorno aislado.
- Aprobar OAuth2, plugins, SMTP y cierre de evidencias con datos representativos.
