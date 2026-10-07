<?php
// Normalización defensiva de residuos qtype=random en la extracción de fase 6.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

function p6_legacy_random_block(string $reason, string $detail = ''): void {
    $message = 'LEGACY_RANDOM_BLOCKED reason=' . $reason;
    if ($detail !== '') {
        $message .= ' detail=' . $detail;
    }
    throw new RuntimeException($message);
}

function p6_legacy_random_positive_id($value, string $field): int {
    if (is_int($value)) {
        $id = $value;
    } else if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
        $id = (int)$value;
    } else {
        p6_legacy_random_block('invalid_identifier', $field);
    }
    if ($id < 1 || (string)$id !== (string)$value) {
        p6_legacy_random_block('invalid_identifier', $field);
    }
    return $id;
}

function p6_legacy_random_boolean($value, string $field): bool {
    if ($value === false || $value === 0 || $value === '0') {
        return false;
    }
    if ($value === true || $value === 1 || $value === '1') {
        return true;
    }
    p6_legacy_random_block('invalid_filtercondition', $field);
}

function p6_legacy_random_direct_text(DOMElement $parent, string $name): ?string {
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            return trim($child->textContent);
        }
    }
    return null;
}

/** Interpreta únicamente los dos contratos de filtro demostrados. */
function p6_legacy_random_filter_contract(string $filtertext): array {
    try {
        $filtercondition = json_decode(
            $filtertext,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        p6_legacy_random_block('invalid_filtercondition', 'invalid_json');
    }
    if (!is_array($filtercondition) || array_is_list($filtercondition)) {
        p6_legacy_random_block('invalid_filtercondition', 'not_an_object');
    }

    if (array_key_exists('filter', $filtercondition)) {
        $category = $filtercondition['filter']['category'] ?? null;
        $values = is_array($category) ? ($category['values'] ?? null) : null;
        $options = is_array($category)
            ? ($category['filteroptions'] ?? null)
            : null;
        $jointype = is_array($category) ? ($category['jointype'] ?? null) : null;
        if (!is_array($values) || count($values) !== 1 ||
                !is_array($options) ||
                !array_key_exists('includesubcategories', $options) ||
                !($jointype === 1 || $jointype === '1')) {
            p6_legacy_random_block('invalid_filtercondition', 'category_filter');
        }
        return [
            'category_id' => p6_legacy_random_positive_id(
                $values[0],
                'filter.category.values'
            ),
            'includesubcategories' => p6_legacy_random_boolean(
                $options['includesubcategories'],
                'filter.category.filteroptions.includesubcategories'
            ),
            'format' => 'modern_category_filter',
        ];
    }

    $keys = array_keys($filtercondition);
    sort($keys, SORT_STRING);
    if ($keys !== ['includingsubcategories', 'questioncategoryid']) {
        p6_legacy_random_block('invalid_filtercondition', 'unknown_legacy_filter');
    }
    return [
        'category_id' => p6_legacy_random_positive_id(
            $filtercondition['questioncategoryid'],
            'questioncategoryid'
        ),
        'includesubcategories' => p6_legacy_random_boolean(
            $filtercondition['includingsubcategories'],
            'includingsubcategories'
        ),
        'format' => 'legacy_questioncategoryid',
    ];
}

/** Fotografía determinista y semántica de question_set_reference. */
function p6_question_set_reference_snapshot(string $directory): array {
    $paths = glob($directory . '/activities/quiz_*/quiz.xml');
    if ($paths === false) {
        p6_legacy_random_block('question_set_reference_scan_failed');
    }
    sort($paths, SORT_STRING);
    $rows = [];
    foreach ($paths as $path) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->load($path, LIBXML_NONET)) {
            p6_legacy_random_block(
                'invalid_filtercondition',
                str_replace($directory . '/', '', $path)
            );
        }
        $xpath = new DOMXPath($dom);
        $references = $xpath->query('//question_set_reference');
        if ($references === false) {
            p6_legacy_random_block('question_set_reference_scan_failed');
        }
        $ordinal = 0;
        foreach ($references as $reference) {
            if (!$reference instanceof DOMElement) {
                continue;
            }
            $ordinal++;
            $filtertext = p6_legacy_random_direct_text($reference, 'filtercondition');
            if ($filtertext === null || $filtertext === '') {
                p6_legacy_random_block('invalid_filtercondition', 'missing_filter');
            }
            $contract = p6_legacy_random_filter_contract($filtertext);
            $rows[] = [
                'path' => str_replace($directory . '/', '', $path),
                'ordinal' => $ordinal,
                'reference_id' => $reference->getAttribute('id'),
                'category_id' => $contract['category_id'],
                'includesubcategories' => $contract['includesubcategories'],
                'filter_format' => $contract['format'],
            ];
        }
    }
    usort($rows, static fn(array $left, array $right): int => [
        $left['path'], $left['ordinal'], $left['reference_id'],
    ] <=> [
        $right['path'], $right['ordinal'], $right['reference_id'],
    ]);
    return $rows;
}

function p6_assert_question_set_reference_invariant(
    array $before,
    array $after
): void {
    if ($after !== $before) {
        p6_legacy_random_block('question_set_reference_changed');
    }
}

/**
 * Busca solo referencias XML semánticas a preguntas o entradas del banco.
 * No hace búsquedas textuales de IDs y evita coincidencias numéricas fortuitas.
 */
function p6_legacy_random_active_references(
    string $directory,
    array $questionids,
    array $entryids
): array {
    $questionlookup = array_fill_keys(array_map('strval', $questionids), true);
    $entrylookup = array_fill_keys(array_map('strval', $entryids), true);
    $questionspath = $directory . '/questions.xml';
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'xml' &&
                $item->getPathname() !== $questionspath) {
            $paths[] = $item->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    $references = [];
    $questionfields = ['questionid' => true, 'question_id' => true];
    $entryfields = [
        'questionbankentryid' => true,
        'question_bank_entry_id' => true,
    ];
    foreach ($paths as $path) {
        $reader = new XMLReader();
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            p6_legacy_random_block(
                'active_reference_scan_failed',
                str_replace($directory . '/', '', $path)
            );
        }
        $stack = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                unset($stack[$reader->depth]);
                continue;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            $name = strtolower($reader->localName ?: $reader->name);
            $parent = $reader->depth > 0
                ? ($stack[$reader->depth - 1] ?? '')
                : '';
            $stack[$reader->depth] = $name;
            foreach (array_keys($stack) as $depth) {
                if ($depth > $reader->depth) {
                    unset($stack[$depth]);
                }
            }
            $kind = null;
            if (isset($questionfields[$name]) ||
                    ($name === 'question' &&
                        in_array($parent, ['question_instance', 'slot'], true))) {
                $kind = 'question';
            } else if (isset($entryfields[$name])) {
                $kind = 'entry';
            }
            if ($kind !== null && !$reader->isEmptyElement) {
                $value = trim($reader->readString());
                $lookup = $kind === 'question' ? $questionlookup : $entrylookup;
                if (isset($lookup[$value])) {
                    $references[] = [
                        'path' => str_replace($directory . '/', '', $path),
                        'field' => $name,
                        'kind' => $kind,
                        'id' => (int)$value,
                    ];
                }
            }
            if ($reader->hasAttributes) {
                while ($reader->moveToNextAttribute()) {
                    $attribute = strtolower($reader->localName ?: $reader->name);
                    $value = trim($reader->value);
                    $kind = isset($questionfields[$attribute])
                        ? 'question'
                        : (isset($entryfields[$attribute]) ? 'entry' : null);
                    $lookup = $kind === 'question'
                        ? $questionlookup
                        : ($kind === 'entry' ? $entrylookup : []);
                    if ($kind !== null && isset($lookup[$value])) {
                        $references[] = [
                            'path' => str_replace($directory . '/', '', $path),
                            'field' => '@' . $attribute,
                            'kind' => $kind,
                            'id' => (int)$value,
                        ];
                    }
                }
                $reader->moveToElement();
            }
        }
        $errors = libxml_get_errors();
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if ($errors) {
            p6_legacy_random_block(
                'active_reference_scan_failed',
                str_replace($directory . '/', '', $path)
            );
        }
    }
    usort($references, static fn(array $left, array $right): int => [
        $left['path'], $left['field'], $left['kind'], $left['id'],
    ] <=> [
        $right['path'], $right['field'], $right['kind'], $right['id'],
    ]);
    return $references;
}

/** Observa qtype=random sin transformar el backup; Moodle 5.2 es la autoridad. */
function p6_observe_legacy_random_questions(string $directory): array {
    $questionspath = rtrim($directory, '/\\') . '/questions.xml';
    $sha = is_readable($questionspath) ? hash_file('sha256', $questionspath) : null;
    $ids = [];
    $categories = [];
    if (is_readable($questionspath)) {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        if (!$dom->load($questionspath, LIBXML_NONET)) {
            throw new RuntimeException('questions.xml no es XML válido.');
        }
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//question[qtype[normalize-space(.)="random"]]') ?: [] as $node) {
            if (!$node instanceof DOMElement) { continue; }
            $id = (int)$node->getAttribute('id');
            if ($id > 0) { $ids[$id] = true; }
            $category = $xpath->query('ancestor::question_category[1]', $node)?->item(0);
            if ($category instanceof DOMElement) {
                $categoryid = (int)$category->getAttribute('id');
                if ($categoryid > 0) { $categories[$categoryid] = true; }
            }
        }
    }
    ksort($ids, SORT_NUMERIC);
    ksort($categories, SORT_NUMERIC);
    return [
        'event' => count($ids) > 0 ? 'LEGACY_RANDOM_QUESTION_OBSERVED' : '',
        'action' => 'delegate_to_moodle_native_restore',
        'questions_xml_sha256_before' => $sha,
        'questions_xml_sha256_after' => $sha,
        'legacy_random_detected' => count($ids),
        'legacy_random_removed' => 0,
        'legacy_random_entries_removed' => 0,
        'legacy_random_question_ids' => array_map('intval', array_keys($ids)),
        'legacy_random_entry_ids' => [],
        'legacy_random_category_ids' => array_map('intval', array_keys($categories)),
        'legacy_random_physical_rows' => count($ids),
        'legacy_random_logical_stamps' => 0,
        'legacy_random_stamps' => [],
        'legacy_random_set_references_verified' => 0,
        'legacy_random_set_reference_cardinality_by_quiz' => [],
        'legacy_random_orphan_physical_rows' => 0,
        'legacy_random_orphan_entries' => 0,
        'legacy_random_orphan_semantics' => [],
        'legacy_random_filter_formats' => [],
        'legacy_random_normalization_status' => count($ids) > 0
            ? 'delegated_to_moodle_native_restore' : 'not_required',
    ];
}

/** Normaliza de forma fail-closed únicamente la extracción temporal del curso. */
function p6_normalize_legacy_random_questions(string $directory): array {
    $questionspath = rtrim($directory, '/\\') . '/questions.xml';
    $audit = [
        'questions_xml_sha256_before' => null,
        'questions_xml_sha256_after' => null,
        'legacy_random_detected' => 0,
        'legacy_random_removed' => 0,
        'legacy_random_entries_removed' => 0,
        'legacy_random_question_ids' => [],
        'legacy_random_entry_ids' => [],
        'legacy_random_category_ids' => [],
        'legacy_random_physical_rows' => 0,
        'legacy_random_logical_stamps' => 0,
        'legacy_random_stamps' => [],
        'legacy_random_set_references_verified' => 0,
        'legacy_random_set_reference_cardinality_by_quiz' => [],
        'legacy_random_orphan_physical_rows' => 0,
        'legacy_random_orphan_entries' => 0,
        'legacy_random_orphan_semantics' => [],
        'legacy_random_filter_formats' => [],
        'legacy_random_normalization_status' => 'not_required',
    ];
    if (!is_readable($questionspath)) {
        p5_validate_backup_question_hierarchy($questionspath);
        return $audit;
    }
    $before = hash_file('sha256', $questionspath);
    if ($before === false) {
        throw new RuntimeException('No fue posible sellar questions.xml antes de normalizar.');
    }
    $audit['questions_xml_sha256_before'] = $before;
    $audit['questions_xml_sha256_after'] = $before;

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    if (!$dom->load($questionspath, LIBXML_NONET)) {
        throw new RuntimeException('questions.xml no es XML válido.');
    }
    $xpath = new DOMXPath($dom);
    $categories = $xpath->query('/question_categories/question_category');
    if ($categories === false) {
        p6_legacy_random_block('questions_scan_failed');
    }
    $candidates = [];
    $allrandomids = [];
    $entryidsseen = [];
    $questionidsseen = [];
    $stampsemantics = [];
    foreach ($categories as $category) {
        if (!$category instanceof DOMElement) {
            continue;
        }
        $categoryid = p6_legacy_random_positive_id(
            $category->getAttribute('id'),
            'question_category.id'
        );
        $randomquestions = $xpath->query(
            './/question[qtype[normalize-space(.)="random"]]',
            $category
        );
        if ($randomquestions === false) {
            p6_legacy_random_block('questions_scan_failed');
        }
        foreach ($randomquestions as $question) {
            if ($question instanceof DOMElement) {
                $allrandomids[] = p6_legacy_random_positive_id(
                    $question->getAttribute('id'),
                    'question.id'
                );
            }
        }
        $entries = $xpath->query(
            './question_bank_entries/question_bank_entry',
            $category
        );
        if ($entries === false) {
            p6_legacy_random_block('questions_scan_failed');
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof DOMElement) {
                continue;
            }
            $questions = $xpath->query('.//question[qtype]', $entry);
            if ($questions === false || $questions->length < 1) {
                continue;
            }
            $random = [];
            $randomstamps = [];
            $nonrandom = 0;
            $includesubcategories = null;
            foreach ($questions as $question) {
                if (!$question instanceof DOMElement) {
                    continue;
                }
                $qtype = p6_legacy_random_direct_text($question, 'qtype');
                if ($qtype !== 'random') {
                    $nonrandom++;
                    continue;
                }
                $questionid = p6_legacy_random_positive_id(
                    $question->getAttribute('id'),
                    'question.id'
                );
                if (isset($questionidsseen[$questionid])) {
                    p6_legacy_random_block('duplicate_question_id', (string)$questionid);
                }
                $questionidsseen[$questionid] = true;
                $stamp = p6_legacy_random_direct_text($question, 'stamp');
                if ($stamp === null || trim($stamp) === '') {
                    p6_legacy_random_block(
                        'missing_random_stamp',
                        'question_id=' . $questionid
                    );
                }
                $include = p6_legacy_random_direct_text($question, 'questiontext');
                if (!in_array($include, ['0', '1'], true)) {
                    p6_legacy_random_block(
                        'legacy_random_semantics_invalid',
                        'question_id=' . $questionid
                    );
                }
                $include = $include === '1';
                if ($includesubcategories !== null && $includesubcategories !== $include) {
                    p6_legacy_random_block('mixed_question_bank_entry');
                }
                $includesubcategories = $include;
                $random[] = $questionid;
                $randomstamps[] = $stamp;
                $semantics = $categoryid . '|' . ($include ? '1' : '0');
                if (isset($stampsemantics[$stamp]) &&
                        $stampsemantics[$stamp] !== $semantics) {
                    p6_legacy_random_block(
                        'random_stamp_semantic_conflict',
                        'stamp=' . $stamp
                    );
                }
                $stampsemantics[$stamp] = $semantics;
            }
            if (!$random) {
                continue;
            }
            if ($nonrandom > 0 || count($random) !== $questions->length) {
                p6_legacy_random_block('mixed_question_bank_entry');
            }
            $entryid = p6_legacy_random_positive_id(
                $entry->getAttribute('id'),
                'question_bank_entry.id'
            );
            if (isset($entryidsseen[$entryid])) {
                p6_legacy_random_block('duplicate_entry_id', (string)$entryid);
            }
            $entryidsseen[$entryid] = true;
            $candidates[] = [
                'node' => $entry,
                'entry_id' => $entryid,
                'question_ids' => $random,
                'stamps' => $randomstamps,
                'category_id' => $categoryid,
                'includesubcategories' => $includesubcategories,
            ];
        }
    }
    sort($allrandomids, SORT_NUMERIC);
    $candidatequestionids = [];
    foreach ($candidates as $candidate) {
        $candidatequestionids = array_merge(
            $candidatequestionids,
            $candidate['question_ids']
        );
    }
    sort($candidatequestionids, SORT_NUMERIC);
    if ($allrandomids !== $candidatequestionids) {
        p6_legacy_random_block('missing_question_bank_entry');
    }
    if (!$candidatequestionids) {
        // Sin residuos random no existe ninguna transformación admisible: la
        // jerarquía original se valida inmediatamente y permanece intacta.
        p5_validate_backup_question_hierarchy($questionspath);
        return $audit;
    }

    $candidateentryids = array_map(
        static fn(array $candidate): int => $candidate['entry_id'],
        $candidates
    );
    sort($candidateentryids, SORT_NUMERIC);
    $references = p6_legacy_random_active_references(
        rtrim($directory, '/\\'),
        $candidatequestionids,
        $candidateentryids
    );
    if ($references) {
        p6_legacy_random_block(
            'active_question_reference',
            $references[0]['path'] . ':' . $references[0]['field']
        );
    }

    $setreferencesbefore = p6_question_set_reference_snapshot(
        rtrim($directory, '/\\')
    );
    $expectedcounts = [];
    foreach ($stampsemantics as $stamp => $key) {
        $expectedcounts[$key] = ($expectedcounts[$key] ?? 0) + 1;
    }
    $actualcountsbyquiz = [];
    foreach ($setreferencesbefore as $reference) {
        $key = $reference['category_id'] . '|' .
            ($reference['includesubcategories'] ? '1' : '0');
        $path = (string)$reference['path'];
        $actualcountsbyquiz[$path][$key] =
            ($actualcountsbyquiz[$path][$key] ?? 0) + 1;
    }
    ksort($expectedcounts, SORT_STRING);
    ksort($actualcountsbyquiz, SORT_STRING);
    $verifiedreferences = 0;
    $cardinalityaudit = [];
    $orphansemantics = [];
    foreach ($expectedcounts as $key => $expectedcount) {
        $matchedpaths = [];
        foreach ($actualcountsbyquiz as $path => $counts) {
            $actualcount = (int)($counts[$key] ?? 0);
            if ($actualcount < 1) {
                continue;
            }
            $matchedpaths[] = $path;
            $cardinalityaudit[] = [
                'quiz_xml' => $path,
                'semantic_key' => $key,
                'expected_logical_stamps' => $expectedcount,
                'actual_set_references' => $actualcount,
            ];
            if ($actualcount !== $expectedcount) {
                p6_legacy_random_block(
                    'set_reference_cardinality_mismatch',
                    $path . ':' . $key . ':expected=' . $expectedcount .
                    ':actual=' . $actualcount
                );
            }
            $verifiedreferences += $actualcount;
        }
        if (!$matchedpaths) {
            [$categoryidtext] = explode('|', $key, 2);
            foreach ($actualcountsbyquiz as $path => $counts) {
                foreach ($counts as $actualkey => $actualcount) {
                    [$actualcategory] = explode('|', (string)$actualkey, 2);
                    if ($actualcategory === $categoryidtext && (string)$actualkey !== $key &&
                            (int)$actualcount > 0) {
                        p6_legacy_random_block(
                            'set_reference_semantic_conflict',
                            $path . ':expected=' . $key . ':actual=' . $actualkey
                        );
                    }
                }
            }
            // Los identificadores de preguntas/entradas ya fueron escaneados de forma
            // fail-closed arriba. Sin referencia activa directa ni set-reference con
            // esta semántica (ni una referencia contradictoria a la misma categoría),
            // la fila qtype=random es un residuo huérfano del banco antiguo.
            $orphansemantics[$key] = $expectedcount;
        }
    }

    $orphanphysicalrows = 0;
    $orphanentries = 0;
    foreach ($candidates as $candidate) {
        $key = $candidate['category_id'] . '|' .
            ($candidate['includesubcategories'] ? '1' : '0');
        if (isset($orphansemantics[$key])) {
            $orphanentries++;
            $orphanphysicalrows += count($candidate['question_ids']);
        }
    }

    foreach ($candidates as $candidate) {
        $node = $candidate['node'];
        if (!$node instanceof DOMElement || $node->parentNode === null) {
            p6_legacy_random_block('entry_removal_failed');
        }
        $node->parentNode->removeChild($node);
    }
    $mode = fileperms($questionspath);
    $temporary = $questionspath . '.partial-' . bin2hex(random_bytes(6));
    if ($dom->save($temporary) === false ||
            ($mode !== false && !chmod($temporary, $mode & 0777)) ||
            !rename($temporary, $questionspath)) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw new RuntimeException('No fue posible guardar questions.xml normalizado.');
    }

    $verification = new DOMDocument();
    $verification->preserveWhiteSpace = false;
    if (!$verification->load($questionspath, LIBXML_NONET)) {
        p6_legacy_random_block('post_normalization_xml_invalid');
    }
    p5_validate_backup_question_hierarchy($questionspath);
    $verificationxpath = new DOMXPath($verification);
    $remainingquestions = $verificationxpath->query('//question[@id]');
    $remainingentries = $verificationxpath->query('//question_bank_entry[@id]');
    if ($remainingquestions === false || $remainingentries === false) {
        p6_legacy_random_block('post_normalization_scan_failed');
    }
    $removedquestionlookup = array_fill_keys(
        array_map('strval', $candidatequestionids),
        true
    );
    foreach ($remainingquestions as $question) {
        if ($question instanceof DOMElement &&
                isset($removedquestionlookup[$question->getAttribute('id')])) {
            p6_legacy_random_block('post_normalization_question_present');
        }
    }
    $removedentrylookup = array_fill_keys(array_map('strval', $candidateentryids), true);
    foreach ($remainingentries as $entry) {
        if ($entry instanceof DOMElement &&
                isset($removedentrylookup[$entry->getAttribute('id')])) {
            p6_legacy_random_block('post_normalization_entry_present');
        }
    }
    $setreferencesafter = p6_question_set_reference_snapshot(
        rtrim($directory, '/\\')
    );
    p6_assert_question_set_reference_invariant(
        $setreferencesbefore,
        $setreferencesafter
    );
    $after = hash_file('sha256', $questionspath);
    if ($after === false) {
        throw new RuntimeException('No fue posible sellar questions.xml normalizado.');
    }
    $categoryids = array_values(array_unique(array_map(
        static fn(array $candidate): int => $candidate['category_id'],
        $candidates
    )));
    sort($categoryids, SORT_NUMERIC);
    $stamps = array_keys($stampsemantics);
    sort($stamps, SORT_STRING);
    $filterformats = array_values(array_unique(array_column(
        $setreferencesbefore,
        'filter_format'
    )));
    sort($filterformats, SORT_STRING);
    return [
        'questions_xml_sha256_before' => $before,
        'questions_xml_sha256_after' => $after,
        'legacy_random_detected' => count($candidatequestionids),
        'legacy_random_removed' => count($candidatequestionids),
        'legacy_random_entries_removed' => count($candidateentryids),
        'legacy_random_question_ids' => $candidatequestionids,
        'legacy_random_entry_ids' => $candidateentryids,
        'legacy_random_category_ids' => $categoryids,
        'legacy_random_physical_rows' => count($candidatequestionids),
        'legacy_random_logical_stamps' => count($stamps),
        'legacy_random_stamps' => $stamps,
        'legacy_random_set_references_verified' => $verifiedreferences,
        'legacy_random_set_reference_cardinality_by_quiz' => $cardinalityaudit,
        'legacy_random_orphan_physical_rows' => $orphanphysicalrows,
        'legacy_random_orphan_entries' => $orphanentries,
        'legacy_random_orphan_semantics' => $orphansemantics,
        'legacy_random_filter_formats' => $filterformats,
        'legacy_random_normalization_status' => 'normalized',
    ];
}
