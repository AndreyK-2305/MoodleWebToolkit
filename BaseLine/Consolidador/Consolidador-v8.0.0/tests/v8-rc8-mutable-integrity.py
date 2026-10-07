from pathlib import Path

verify = Path("tests/verify-package.sh").read_text(encoding="utf-8")
launcher = Path("moodle-consolidation.sh").read_text(encoding="utf-8")

mutable = (
    "./config/phase5-pilot-package.json",
    "./config/phase6-batch.json",
)

assert "RC8_MUTABLE_DISTRIBUTION_FILES" in verify

for item in mutable:
    assert item in verify, f"MUTABLE_CONTRACT_MISSING {item}"

assert "immutableactual = allfiles - mutable" in verify
assert "mutable & listed" in verify
assert "DISTRIBUTION_MUTABLE_REQUIRED_MISSING" in verify

# El launcher debe seguir verificando el manifiesto inmutable.
assert '[[ -f FILES.sha256 ]]' in launcher
assert 'sha256sum --check FILES.sha256' in launcher

# Los archivos mutables siguen siendo archivos reales de configuración.
for item in mutable:
    path = Path(item.removeprefix("./"))
    assert path.is_file(), f"MUTABLE_FILE_MISSING {item}"

print(
    "V8_RC8_MUTABLE_INTEGRITY_OK "
    "mutable=2 immutable_manifest=1 runtime_verify=1"
)
