#!/usr/bin/env python3
"""Bloquea referencias de runtime a imágenes candidatas anteriores."""

from pathlib import Path
import re

root = Path(__file__).resolve().parent.parent
target = 'moodle-consolidation-target:5.2.1-v7.4.0-rc12'
assistant = 'moodle-consolidation-assistant:7.4.0-linux-rc12'
image_pattern = re.compile(
    r'moodle-consolidation-(?:target|assistant):[^\s"\']*-rc(?:[1-9]|1[01])(?!\d)'
)
version_pattern = re.compile(r'7\.4\.0-linux-rc(?:[1-9]|1[01])(?!\d)')
files = [
    root / '.env.example',
    root / 'moodle-consolidation.sh',
    root / 'config-manager/config-manager.sh',
    root / 'compose.yaml',
    root / 'VERSION.txt',
    *sorted((root / 'scripts').glob('*.ps1')),
    *sorted((root / 'docker').glob('*.php')),
    *sorted((root / 'docker').glob('Dockerfile*')),
]
for old in range(1, 12):
    if not image_pattern.search(
        f'moodle-consolidation-target:5.2.1-v7.4.0-rc{old}'
    ) or not image_pattern.search(
        f'moodle-consolidation-assistant:7.4.0-linux-rc{old}'
    ) or not version_pattern.search(f'7.4.0-linux-rc{old}'):
        raise SystemExit(f'RC12_TAGS_FAILED: filtro no detecta rc{old}')
for path in files:
    content = path.read_text(encoding='utf-8-sig')
    if image_pattern.search(content) or version_pattern.search(content):
        raise SystemExit(f'RC12_TAGS_FAILED: referencia antigua en {path.relative_to(root)}')
for name in ('.env.example', 'moodle-consolidation.sh',
             'config-manager/config-manager.sh', 'compose.yaml'):
    if target not in (root / name).read_text(encoding='utf-8'):
        raise SystemExit(f'RC12_TAGS_FAILED: falta imagen destino rc12 en {name}')
if assistant not in (root / 'compose.yaml').read_text(encoding='utf-8'):
    raise SystemExit('RC12_TAGS_FAILED: falta imagen assistant rc12')
if (root / 'VERSION.txt').read_text().splitlines()[0] != '7.4.0-linux-rc12':
    raise SystemExit('RC12_TAGS_FAILED: VERSION.txt discrepante')
print(f'RC12_RUNTIME_TAGS_OK files={len(files)} image=rc12 old=0')
