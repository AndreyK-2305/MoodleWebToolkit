import re
from pathlib import Path

# 8.1.0: every CONSOLIDATION_* variable the assistant's scripts read must reach
# the assistant-runtime container. It gets there only if compose.yaml declares
# it in that service's environment, moodle-consolidation.sh writes it into .env,
# or the launcher passes it with -e. A variable exported by whoever starts the
# assistant (the platform's agent exports CONSOLIDATION_FUZZY_REVIEW and
# CONSOLIDATION_CATEGORY_MAP) that is declared nowhere is silently dropped and
# its stage behaves as if it were never requested.

compose = Path("compose.yaml").read_text(encoding="utf-8")
launcher = Path("moodle-consolidation.sh").read_text(encoding="utf-8")

service = re.search(r"^  assistant-runtime:\n(.*?)(?=^  \S|^\S)", compose, re.S | re.M)
assert service, "ASSISTANT_RUNTIME_SERVICE_MISSING"
environment = re.search(r"^    environment:\n((?:^      .*\n)+)", service.group(1), re.M)
assert environment, "ASSISTANT_RUNTIME_ENVIRONMENT_MISSING"
declared = set(re.findall(r"^      ([A-Z][A-Z0-9_]*):", environment.group(1), re.M))
written_env = set(re.findall(r"printf '([A-Z][A-Z0-9_]*)=", launcher))
passed = set(re.findall(r'-e "?([A-Z][A-Z0-9_]*)=', launcher))
reachable = declared | written_env | passed

read = {}
for path in sorted(Path("scripts").glob("*.ps1")):
    for name in re.findall(r"\$env:(CONSOLIDATION_[A-Z0-9_]+)", path.read_text(encoding="utf-8")):
        read.setdefault(name, path.name)

missing = sorted(f"{name} ({source})" for name, source in read.items() if name not in reachable)
assert not missing, f"CONSOLIDATION_ENV_NOT_PROPAGATED {missing}"

# The two variables the platform exports must come through compose's environment.
for name in ("CONSOLIDATION_FUZZY_REVIEW", "CONSOLIDATION_CATEGORY_MAP"):
    assert name in declared, f"PLATFORM_ENV_NOT_DECLARED {name}"
    assert f"{name}: ${{{name}:-}}" in environment.group(1), f"PLATFORM_ENV_NOT_INTERPOLATED {name}"

print(f"V8_810_ENV_PROPAGATION_OK read={len(read)} reachable={len(reachable)}")
