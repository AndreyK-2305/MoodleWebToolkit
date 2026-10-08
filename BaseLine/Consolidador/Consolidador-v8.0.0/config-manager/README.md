# Configuración administrada

Esta carpeta contiene el motor distribuido del gestor. Los datos reales no se
guardan aquí: viven en la ruta absoluta `MOODLE_MANAGED_CONFIG_DIR` definida en
`.env`.

Utilice siempre el comando de la raíz:

```bash
./GESTIONAR-CONFIG.sh ayuda
```

No edite `config.php`, `.managed-config.php`, `active/current.php` ni los
directorios de `history/` directamente. El único archivo de trabajo editable es
`pending.json`, creado por `./GESTIONAR-CONFIG.sh editar`.

La preparación V8 usa la misma transacción para la política visual:

```bash
./GESTIONAR-CONFIG.sh aplicar-politica-theme \
  --theme academi \
  --allow-course-themes 1 \
  --motivo "Selección global aprobada antes de READY_TO_RUN"
```

El comando valida el candidato, conserva auditoría e historial, recrea el
servicio cuando corresponde y verifica los valores efectivos de Moodle. Si la
verificación falla, reactiva la versión anterior mediante el rollback normal.
