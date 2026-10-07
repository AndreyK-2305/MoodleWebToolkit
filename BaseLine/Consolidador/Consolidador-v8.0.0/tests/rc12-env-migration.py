#!/usr/bin/env python3
"""Ejecuta la migración real de .env aislada, sin invocar Docker."""
from pathlib import Path
import os
import subprocess
import tempfile

launcher = (Path(__file__).resolve().parent.parent / 'moodle-consolidation.sh').read_text()
start = "    python3 - <<'PY'\n"
if launcher.count(start) != 1:
    raise SystemExit('RC12_ENV_FAILED: no se encontró la migración única')
program = launcher.split(start, 1)[1].split('\nPY\n', 1)[0]
new = 'moodle-consolidation-target:5.2.1-v8.0.0'
for previous in range(1, 25):
    old = 'moodle-consolidation-target:5.2.1-v7.4.0-rc' + str(previous)
    with tempfile.TemporaryDirectory(prefix='rc12-env-') as directory:
        path = Path(directory) / '.env'
        original = f'MOODLE_TARGET_IMAGE={old}\nOTHER_SETTING=preserve-me\n'.encode()
        path.write_bytes(original)
        path.chmod(0o600)
        # Un archivo de una tentativa anterior no debe impedir un reintento.
        (Path(directory) / '.env.rc12.partial').write_text('incompleto')
        subprocess.run(['python3', '-c', program], cwd=directory, check=True)
        changed = path.read_bytes()
        if changed != original.replace(old.encode(), new.encode()) or \
                path.stat().st_mode & 0o777 != 0o600:
            raise SystemExit('RC12_ENV_FAILED: alteró un ajuste ajeno o permisos')
        if not (Path(directory) / '.env.rc12.partial').exists():
            raise SystemExit('RC12_ENV_FAILED: alteró la tentativa ajena')
        if new not in changed.decode() or old in changed.decode():
            raise SystemExit('RC12_ENV_FAILED: etiqueta no quedó actualizada')
        if 'range(1, 25)' not in program or 'v8.0.0' not in program:
            raise SystemExit('RC12_ENV_FAILED: no existe protección de reintentos')
        before = path.read_bytes()
        if new not in path.read_text():
            subprocess.run(['python3', '-c', program], cwd=directory, check=True)
        if path.read_bytes() != before:
            raise SystemExit('RC12_ENV_FAILED: preparación repetida cambió .env')
print('V8_ENV_OK rc1_rc24_migrated=1 other_settings=preserved retry=idempotent')
