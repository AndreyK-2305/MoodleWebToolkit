# Consolidador 7.4.0-linux-rc19

RC19 parte exactamente de RC18 y corrige de forma mínima la lectura liviana
de `users.xml` desde backups MBZ con formato tar durante la preparación del
lote de Fase 6. Continúa siendo una candidata técnica; no se declara estable
ni apta para benchmark hasta completar la ejecución real.

## Corrección

- La rama tar de `p6_backup_users_xml()` invoca GNU tar mediante un array de
  argumentos inequívoco:

  `tar --extract --to-stdout --file <MBZ> -- <member>`

- `--file` recibe exactamente la ruta del backup; el separador `--` protege el
  nombre interno `users.xml` o `./users.xml`.
- Se elimina la forma ambigua `tar -xOf -- <MBZ> <member>`, donde `-f`
  interpretaba `--` como el archivo tar.
- Se mantiene `proc_open()` sin shell, incluso para rutas con espacios.

## Arquitectura preservada

- Se copia únicamente `users.xml` a un temporal y se procesa con XMLReader.
- No se extrae ni duplica el MBZ completo.
- No se recalcula el SHA sellado ni se escribe en Moodle destino.
- Se conservan los dos nombres de miembro, la rama ZipArchive, las
  clasificaciones, bloqueos, trabajos livianos y checkpoints existentes.
- Fases 1–5 permanecen sin cambios funcionales, incluida la semántica RC18 de
  `course_completions`.

## Regresión

`tests/rc19-phase6-tar-users.php` cubre:

- `users.xml` y `./users.xml`;
- tar sin users.xml y archivo corrupto;
- ruta del MBZ con espacios;
- múltiples usuarios, IDs y clasificación determinista;
- IDs inválidos o repetidos;
- contrato exacto de argumentos y ausencia de `-xOf` o shell.

La rama ZIP continúa cubierta por `tests/contracts.php` y se conserva toda la
suite RC1–RC18.

