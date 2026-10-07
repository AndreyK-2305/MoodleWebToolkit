# Recolector 7.4.2

Revisión localizada de `7.4.1-linux` para producir la evidencia de themes que
consume el Consolidador v8.

## Cambios

- Conserva en `inventario-origen.json` el theme global, las políticas
  disponibles, los perfiles de configuración seguros y las asignaciones.
- Registra `course.theme` tanto en el resumen global como en el inventario
  individual de cada curso.
- Redacta passwords, secrets, tokens, API keys, credenciales y claves privadas.
- Declara `capabilities.theme_inventory=1.0` en el manifiesto sellado.
- Cruza themes, perfiles, plugins, inventarios individuales y checkpoints en
  `VALIDAR-PAQUETE.sh`.
- Reanuda trabajos 7.4.1 enriqueciendo únicamente metadata; los MBZ y el
  fingerprint académico conservan su identidad.
- Si una reanudación ya tiene schema de themes `1.0` pero el inventario quedó
  con `inventory_complete=false`, vuelve a ejecutar el enriquecimiento en el
  siguiente intento. No exige `--restart` ni regenera MBZ por una falla
  temporal de metadata visual.

## Sin cambios funcionales

No se modificaron `source-plugins.php`, el formato MBZ, la creación/reutilización
de backups, la cola, el límite de workers, identidades, SMTP ni el perfil
académico.
