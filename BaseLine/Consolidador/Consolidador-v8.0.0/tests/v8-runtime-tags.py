#!/usr/bin/env python3
"""Bloquea tags runtime RC activos en la distribución V8."""
from pathlib import Path
import re

root = Path(__file__).resolve().parent.parent
target = 'moodle-consolidation-target:5.2.1-v8.0.0'
assistant = 'moodle-consolidation-assistant:8.0.0-linux'
old = re.compile(r'moodle-consolidation-(?:target|assistant):[^\s"\']*v7\.4\.0-rc(?:[1-9]|1[0-9]|2[0-4])(?!\d)')
files = [
    root / '.env.example', root / 'moodle-consolidation.sh',
    root / 'config-manager/config-manager.sh', root / 'compose.yaml',
    root / 'VERSION.txt', *sorted((root / 'scripts').glob('*.ps1')),
    *sorted((root / 'docker').glob('*.php')),
    *sorted((root / 'docker').glob('Dockerfile*')),
]
active_previous = re.compile(r'8\.0\.0-linux-rc(?:1|2|3|4|5|6|7|8|9|10|11)(?!\d)')
for path in files:
    content = path.read_text(encoding='utf-8-sig')
    if old.search(content):
        raise SystemExit(f'V8_TAGS_FAILED: referencia runtime RC en {path.relative_to(root)}')
    if path.name != 'VERSION.txt' and active_previous.search(content):
        raise SystemExit(
            f'V8_TAGS_FAILED: referencia activa anterior en {path.relative_to(root)}'
        )
for name in ('.env.example', 'moodle-consolidation.sh',
             'config-manager/config-manager.sh', 'compose.yaml'):
    if target not in (root / name).read_text(encoding='utf-8'):
        raise SystemExit(f'V8_TAGS_FAILED: falta imagen destino V8 en {name}')
if assistant not in (root / 'compose.yaml').read_text(encoding='utf-8'):
    raise SystemExit('V8_TAGS_FAILED: falta imagen assistant V8')
version_lines = (root / 'VERSION.txt').read_text().splitlines()
if version_lines[0] != '8.0.0-linux-rc12':
    raise SystemExit('V8_TAGS_FAILED: VERSION.txt discrepante')
if not any('Origen: 8.0.0-linux-rc11 +' in line for line in version_lines):
    raise SystemExit('V8_TAGS_FAILED: VERSION.txt no declara RC11 como base')
print(f'V8_RUNTIME_TAGS_OK files={len(files)} rc_active=0')
