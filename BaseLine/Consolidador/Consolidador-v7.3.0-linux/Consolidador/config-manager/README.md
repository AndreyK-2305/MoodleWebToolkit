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
