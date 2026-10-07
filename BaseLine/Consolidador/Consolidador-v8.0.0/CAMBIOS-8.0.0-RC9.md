# Consolidador 8.0.0-linux-rc9

Release derivada directamente de la RC8 que completó la validación
E2E del lote de aceptación.

## Alcance

RC9 no modifica la lógica académica, de restauración, identidad,
OAuth2, plugins, temas, archivos, módulos, cuestionarios, notas,
entregas, checkpoints ni verificación del lote.

La única corrección funcional está en el contrato de ejecución en
segundo plano.

## Persistencia de systemd --user

RC8 podía iniciar correctamente una unidad mediante `systemd-run
--user` y publicar `SEGUNDO_PLANO_OK`, pero no verificaba que el
usuario tuviera `Linger=yes`.

En un host donde `Linger=no`, al cerrarse la última sesión SSH,
`systemd-logind` puede detener `user@UID.service`; las unidades
transitorias del usuario terminan junto con ese user manager.

RC9 valida explícitamente el estado de `Linger` antes de iniciar el
runner systemd.

### Contrato

- Si `systemd-run --user` no es el camino disponible, se conserva el
  comportamiento histórico alternativo.
- Si se utilizará `systemd-run --user`, `loginctl` debe estar
  disponible.
- `Linger` debe ser exactamente `yes`.
- Con otro valor, la ejecución se bloquea antes de crear la unidad.
- El mensaje indica de forma explícita:

  `sudo loginctl enable-linger <usuario>`

- Solo después de validar la persistencia se publica:

  `SEGUNDO_PLANO_OK runner=systemd ... linger=yes`

## Evidencia del defecto

Durante la E2E de RC8 una ejecución background comenzó correctamente,
pero el user manager fue detenido después del cierre de las sesiones
SSH. El host no tenía linger habilitado en ese momento.

Posteriormente se habilitó linger y las ejecuciones mediante
`systemd-run --user` permanecieron activas.

## Regresión

Se incorpora:

`tests/v8-rc9-background-linger.py`

La prueba exige:

- consulta explícita de `Linger`;
- bloqueo fail-closed si no es `yes`;
- instrucción accionable;
- validación antes de `systemd-run`;
- conservación del fallback histórico `nohup`;
- conservación de la configuración de la unidad systemd.
