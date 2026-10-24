# Etapa F: hacia la versión definitiva

Tras la Etapa E7, el recorrido Recolector → Consolidador V8 funciona de punta a punta en AWS y desde
la web. Esta etapa cierra lo que falta para la versión definitiva, en el orden acordado con el
usuario el 2026-10-24.

1. **Consolidador definitivo y pendientes de experiencia.**
    - Consolidador 8.0.0 final (F1, abajo).
    - La consolidación remota no pide instancias de origen.
    - Actualizar el agente desde la web con un proyecto en curso.
2. **Prueba representativa en dos pasos.**
    - Primero, un laboratorio sintético más rico, cargado automáticamente en cada Moodle de origen:
      unas 3 categorías con 2 cursos cada una, unos 10 usuarios y contenido genérico. Ejercita la
      consolidación, la revisión fuzzy de identidades y la gestión de categorías antes de consolidar.
    - Si pasa bien, el usuario restaura copias institucionales significativas en los EC2 de origen
      y se prueba la herramienta web con ellas. Ese proceso dura unas 3 a 5 horas, así que va al
      final.
    - La prueba de resiliencia en AWS va en el mismo ciclo: reiniciar la plataforma, cortar la
      conexión y detener un worker durante una consolidación larga, y comprobar que retoma sin
      duplicar.
3. **Runbook de operación.** Preparación de servidores, DNS y Caddy, OAuth, recolección,
   consolidación y publicación, junto con la respuesta a fallos, el rollback, los costos y la
   capacidad. Se escribe cuando el flujo ya no cambie.
4. **Integrador.** Sigue bloqueado como incompatible. El usuario decide si entra en la versión
   definitiva.

## F1: Consolidador 8.0.0-linux final

La distribución pasa de `8.0.0-linux-rc13` a `8.0.0-linux`:

- árbol `717b1fefca41fca97db22a1e2f964866f95e406ab259648d09c4fb5510817bc3`;
- manifiesto `47456297f907308f8b5602da488f0403918fa03b25a2c8746d89433263b566ca`;
- 266 archivos.

Corrige en el propio Consolidador el defecto de la consolidación sin plugins, que en E7 se rodeaba
desde la plataforma con «Continuar sin plugins».

- **Causa:** con un lock vacío, `v8-plugin-resolver.ps1 -Mode Prepare` reescribía
  `approved-plugins.json` y solo reconstruía `moodle-target` si había instalado algún plugin. La
  imagen conservaba el manifiesto anterior y la revalidación fallaba con «pins inconsistentes». El
  asistente archivaba entonces las necesidades y el lock nunca se sellaba.
- **Corrección:** `Prepare` reconstruye la imagen cuando el manifiesto de `moodle-target` difiere
  del archivo, también al reutilizar un lock. Con cero plugins, la etapa se sella y se aprueba sola.
  El detalle está en `CAMBIOS-8.0.0-FINAL.md`, con la prueba `tests/v8-800-zero-plugin-rebuild.ps1`.
- **Plataforma:** el catálogo, `CheckRemoteDestination::DISTRIBUTION_KEY` y
  `consolidator:enable-remote` apuntan a `moodle-consolidador-8.0.0-linux-tree`. «Continuar sin
  plugins» y la operación `consolidator.plugins-none` del agente se conservan como respaldo, por si
  la etapa vuelve a detenerse.
- **BaseLine:** pasa a 426 archivos, con huella
  `4613bd0a9a3338a0d6f3824955f9f30e712af3d1f6f15cbd43e2ae2b93c8cae6`.

**Al desplegarla en un destino existente**, el administrador vuelve a ejecutar el helper del perfil
con `--force --tree 717b1fef…`. El panel muestra el comando. Después se usa «Preparar destino» para
limpiarlo con la nueva versión. El cambio se confirma de punta a punta en la prueba representativa.
