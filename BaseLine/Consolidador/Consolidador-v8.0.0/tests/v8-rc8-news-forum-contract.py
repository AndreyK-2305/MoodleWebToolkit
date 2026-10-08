from pathlib import Path

source = Path("scripts/phase5-lib.php").read_text(
    encoding="utf-8"
)

required = [
    "'section_number'",
    "'forum_type'",
    "$targetnewsforumcandidates",
    "$expectedmodulekeylookup",
    "'forum'",
    "'news'",
    "trim((string)($module['idnumber'] ?? '')) === ''",
    "(int)($module['section_number'] ?? -1) === 0",
    "!isset($expectedmodulekeylookup[$modulekey])",
    "count($targetnewsforumcandidates) === 1",
    "'target_news_forum_candidates'",
    "'ignored_target_news_forum_modules'",
    "'ignored_target_news_forum_keys'",
    "'news_forum_reason'",
    "'ignored_target_qbank_modules'",
]

for token in required:
    assert token in source, (
        f"RC8_NEWS_FORUM_CONTRACT_MISSING token={token}"
    )

# La excepción no puede depender del nombre localizado "Avisos".
comparison_start = source.index(
    "function p5_compare_course_inventories"
)
comparison = source[comparison_start:]

candidate_start = comparison.index(
    "$targetnewsforumcandidates"
)
candidate_end = comparison.index(
    "$ignorednewsforumrows"
)
candidate_logic = comparison[
    candidate_start:candidate_end
]

assert "Avisos" not in candidate_logic
assert "Anuncios" not in candidate_logic

# Debe seguir siendo fail-closed con múltiples candidatos:
# solo exactamente uno es normalizado.
assert "count($targetnewsforumcandidates) === 1" in comparison

# La normalización debe afectar el comparable, no el inventario bruto.
assert "$comparableactualcounts['activities']" in comparison
assert "$comparableactualmodulesbytype" in comparison
assert "$comparableactualmodules" in comparison

print(
    "V8_RC8_NEWS_FORUM_CONTRACT_OK "
    "type=news section=0 idnumber=empty "
    "target_only=1 multiple=blocked qbank=independent"
)
