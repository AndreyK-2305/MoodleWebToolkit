<?php

namespace App\Domain\Remote;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Enums\ConflictStatus;
use App\Enums\EventSeverity;
use App\Exceptions\RemoteAgentUnavailable;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Typed interventions of a remote consolidation. When the assistant waits for
 * the administrator, the Execution opens one Conflict that carries what the
 * Consolidator itself wrote for that stage, whitelisted and redacted:
 *
 * - OAUTH2_CONFIGURATION: the manual instructions and the validation issues;
 *   the administrator configures Google OAuth2 in Moodle and confirms.
 * - PLUGIN_INTERVENTION: the source needs, the inventory diff and technical
 *   issues; confirming revalidates, and once the assistant is ready for
 *   approval, accepting approves the sealed plugin inventory on the destination.
 *   When the Consolidator's lock asks for no plugin at all, continuing without
 *   plugins seals it. Consolidator rc12 and rc13 stall on that case; 8.0.0
 *   seals it by itself, and this stays as the fallback if the stage still stops.
 * - IDENTITY_REVIEW: the fuzzy identity candidates the Consolidator prepared
 *   (rc13 stops for them); the administrator decides each group in the platform
 *   and the review is applied with the Consolidator's own validator.
 * - THEME_SELECTION: the destination's global theme and the legacy theme
 *   decision, recorded in the Consolidator's theme policy.
 * - CATEGORY_MAP: the destination category tree the Consolidator 8.1.0 proposed
 *   from the sources' inventories, with their courses and merge suggestions; the
 *   administrator draws the map in the platform and it is written as
 *   config/category-map.json, which the Consolidator validates on resuming.
 * - CONSOLIDATOR_INTERVENTION: any other stop, with the assistant's message.
 *
 * Decisions go through the generic conflict resolution, so they are versioned,
 * idempotent and audited, and continue the Execution with a durable command.
 */
class RemoteConsolidationInterventions
{
    public const OAUTH2 = 'OAUTH2_CONFIGURATION';

    public const PLUGINS = 'PLUGIN_INTERVENTION';

    public const GENERIC = 'CONSOLIDATOR_INTERVENTION';

    public const IDENTITY = 'IDENTITY_REVIEW';

    public const THEMES = 'THEME_SELECTION';

    public const CATEGORY_MAP = 'CATEGORY_MAP';

    public const TYPES = [self::OAUTH2, self::PLUGINS, self::IDENTITY, self::THEMES, self::CATEGORY_MAP, self::GENERIC];

    /** Proposal pages read at most: each fits one agent answer, so a larger tree is decided on the server. */
    public const MAX_MAP_PAGES = 200;

    /** Candidates shown at most: a review covers every group, so a longer one is decided on the server. */
    public const MAX_CANDIDATES = 200;

    private const THEME_MESSAGES = ['V8_THEME_SELECTION_REQUIRED', 'V8_LEGACY_THEME_DECISION_REQUIRED', 'V8_THEME_GLOBAL_NOT_AVAILABLE'];

    private const OAUTH2_STAGES = ['02-oauth2', '03b-oauth2-final'];

    /** How the timeline names each intervention. */
    private const TITLES = [self::OAUTH2 => 'configurar Google OAuth2', self::PLUGINS => 'plugins requeridos por los orígenes',
        self::IDENTITY => 'revisar posibles identidades duplicadas', self::THEMES => 'elegir la apariencia del Moodle consolidado',
        self::CATEGORY_MAP => 'revisar el mapa de categorías del destino', self::GENERIC => 'el Consolidador necesita una decisión'];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly SensitiveValueRedactor $redactor,
        private readonly ExecutionEventRecorder $events,
    ) {}

    /**
     * Opens the intervention for the stage the assistant stopped at; one per stop.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $report
     */
    public function open(Execution $execution, array $settings, array $report, int $run): Conflict
    {
        $stage = $report['state']['stage'] ?? null;
        $stage = is_string($stage) && isset(RemoteConsolidationPreparation::STAGES[$stage]) ? $stage : null;
        $message = is_string($report['message'] ?? null) ? $report['message'] : '';
        $type = match (true) {
            in_array($stage, self::OAUTH2_STAGES, true) => self::OAUTH2,
            $stage === '03-plugins' => self::PLUGINS,
            $stage === '05b-readiness' && str_starts_with($message, 'V8_IDENTITY_REVIEW_REQUIRED') => self::IDENTITY,
            $stage === '05b-readiness' && collect(self::THEME_MESSAGES)->contains(fn (string $prefix): bool => str_starts_with($message, $prefix)) => self::THEMES,
            $stage === '05a-mapa-categorias' && str_starts_with($message, 'V8_CATEGORY_MAP_') => self::CATEGORY_MAP,
            default => self::GENERIC,
        };
        $documents = in_array($type, [self::OAUTH2, self::PLUGINS], true) ? $this->documents($execution, $settings, $type === self::OAUTH2 ? 'oauth2' : 'plugins') : [];
        $details = ['kind' => $type, 'stage' => $stage, 'message' => $message !== '' ? $message : null,
            'review_paths' => is_array($report['review_paths'] ?? null) ? $report['review_paths'] : [], ...match ($type) {
                self::OAUTH2 => $this->oauth2($documents),
                self::PLUGINS => $this->plugins($documents),
                self::IDENTITY => $this->identities($execution, $settings),
                self::THEMES => $this->themes($execution, $settings),
                self::CATEGORY_MAP => $this->categoryMap($execution, $settings),
                default => [],
            }];
        $details['allowed_decisions'] = match (true) {
            $type === self::PLUGINS && ($details['checkpoint_status'] ?? null) === 'READY_FOR_APPROVAL' => ['ACCEPT', 'CONFIRM_COMPLETED'],
            $type === self::PLUGINS && ($details['plugins_none'] ?? false) === true => ['CONTINUE_WITHOUT_PLUGINS', 'CONFIRM_COMPLETED'],
            // Components outside the catalog are inspected and confirmed in the platform.
            $type === self::PLUGINS && ($details['unknown'] ?? []) !== [] => ['APPLY_ANSWERS', 'CONFIRM_COMPLETED'],
            // Answered in the platform; a review that could not be read is decided on the server and confirmed.
            $type === self::IDENTITY && ($details['candidates_complete'] ?? false) === true => ['APPLY_ANSWERS', 'CONFIRM_COMPLETED'],
            $type === self::THEMES && ($details['themes'] ?? []) !== [] => ['APPLY_ANSWERS', 'CONFIRM_COMPLETED'],
            $type === self::CATEGORY_MAP && ($details['map_complete'] ?? false) === true => ['APPLY_ANSWERS', 'CONFIRM_COMPLETED'],
            default => ['CONFIRM_COMPLETED'],
        };

        return $this->create($execution, 'consolidator.'.($stage ?? 'assistant').'.'.$run, $type, $stage, $details);
    }

    /**
     * Opens the same intervention again when continuing failed, with the reason, so the
     * administrator can correct and decide once more.
     */
    public function reopen(Conflict $conflict, string $reason): Conflict
    {
        $details = $conflict->details ?? [];
        unset($details['answers']);
        $details['message'] = mb_substr($this->redactor->redactString('No se pudo continuar: '.$reason), 0, 500);
        $attempt = Conflict::query()->where('execution_id', $conflict->execution_id)->where('key', 'like', $conflict->key.'%')->count();

        return $this->create($conflict->execution, $conflict->key.'.r'.$attempt, $conflict->type, $details['stage'] ?? null, $details);
    }

    /** @param array<string, mixed> $details */
    private function create(Execution $execution, string $key, string $type, ?string $stage, array $details): Conflict
    {
        $step = ExecutionStep::query()->where('execution_id', $execution->id)->where('step_key', $stage ?? '01-importar-paquetes')->first();

        // The Execution already waits when its intervention is read from the destination: the
        // conflict is announced with its own event, in the same commit, so the tracking screen
        // (realtime or polling) brings the new form without a reload.
        return DB::transaction(function () use ($execution, $key, $type, $stage, $step, $details): Conflict {
            $conflict = Conflict::query()->firstOrCreate(['execution_id' => $execution->id, 'key' => $key],
                ['execution_step_id' => $step?->id, 'type' => $type, 'status' => ConflictStatus::OPEN, 'version' => 1, 'details' => $details]);
            if ($conflict->wasRecentlyCreated) {
                $this->events->record($execution, 'consolidator.intervention_opened', $stage, EventSeverity::WARNING,
                    message: 'Nueva intervención: '.(self::TITLES[$type] ?? 'revise la decisión pendiente').'.',
                    payload: ['conflict_key' => $key, 'conflict_type' => $type]);
            }

            return $conflict;
        }, attempts: 3);
    }

    /**
     * The intervention fields that leave the backend, whitelisted again on the way out.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function present(array $details): array
    {
        $presented = array_intersect_key($details, array_flip(['kind', 'stage', 'message', 'review_paths', 'instructions', 'callback_uri', 'issues',
            'needs', 'new_plugins', 'updated_plugins', 'removed_plugins', 'technical_issues', 'checkpoint_status', 'plugins_none',
            'candidates', 'candidate_total', 'candidates_complete', 'themes', 'theme_selected', 'theme_default', 'legacy_sources', 'legacy_accepted',
            'unknown', 'inspections', 'proposals', 'proposal_sha256', 'map_sources', 'existing', 'categories', 'suggestions', 'pilot', 'map_issues',
            'map_complete', 'previous_map']));
        // The web user answers in the platform; the console wording goes to the technical details.
        foreach (['message', 'instructions'] as $field) {
            if (array_key_exists($field, $presented)) {
                $original = is_string($presented[$field]) ? $presented[$field] : null;
                $presented[$field] = OperatorText::forWebUser($original);
                $presented['server_'.$field] = OperatorText::technical($original);
            }
        }
        // What was answered, without personal data: the page keeps confirming it while the destination applies it.
        if (is_array($details['answers'] ?? null)) {
            $answers = $details['answers'];
            $resolutions = array_column(is_array($answers['decisions'] ?? null) ? array_filter($answers['decisions'], 'is_array') : [], 'resolution');
            $presented['answer_summary'] = ['answered_at' => is_string($answers['answered_at'] ?? null) ? $answers['answered_at'] : null,
                'merge' => count(array_keys($resolutions, 'MERGE', true)), 'keep_separate' => count(array_keys($resolutions, 'KEEP_SEPARATE', true)),
                'ignore' => count(array_keys($resolutions, 'IGNORE', true)), 'theme' => is_string($answers['theme'] ?? null) ? $answers['theme'] : null,
                'promoted' => is_array($answers['promote'] ?? null) ? count($answers['promote']) : 0,
                'categories' => is_array($answers['nodes'] ?? null) ? count($answers['nodes']) : 0,
                'merged' => is_array($answers['nodes'] ?? null) && is_array($answers['assignments'] ?? null)
                    ? count(array_filter(array_count_values(array_map('strval', array_column($answers['assignments'], 'node'))), fn (int $members): bool => $members > 1)) : 0];
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string|null>
     */
    private function documents(Execution $execution, array $settings, string $kind): array
    {
        $server = Server::query()->where('project_id', $execution->project_id)->where('uuid', (string) ($settings['server_uuid'] ?? ''))->first();
        if ($server === null) {
            return [];
        }
        try {
            $response = $this->agent->call($this->connections->target($server), 'consolidator-intervention', [(string) $settings['destination_profile'], $kind], 30);
        } catch (RemoteAgentUnavailable) {
            return [];
        }
        $documents = [];
        foreach ($response->ok && is_array($response->data['documents'] ?? null) ? $response->data['documents'] : [] as $name => $encoded) {
            $decoded = is_string($encoded) && $encoded !== 'too_large' ? base64_decode($encoded, true) : false;
            // PowerShell writes UTF-8, sometimes with a byte order mark.
            $documents[(string) $name] = is_string($decoded) ? mb_scrub((string) preg_replace('/^\xEF\xBB\xBF/', '', $decoded), 'UTF-8') : null;
        }

        return $documents;
    }

    /**
     * @param  array<string, string|null>  $documents
     * @return array<string, mixed>
     */
    private function oauth2(array $documents): array
    {
        $issues = [];
        $callback = null;
        foreach (['oauth2_validation', 'oauth2_live'] as $name) {
            $json = $this->json($documents[$name] ?? null);
            foreach (is_array($json['issues'] ?? null) ? $json['issues'] : [] as $issue) {
                $text = is_string($issue) ? $issue : (is_array($issue) && is_string($issue['message'] ?? null) ? $issue['message'] : null);
                if ($text !== null) {
                    $issues[] = mb_substr($this->redactor->redactString($text), 0, 300);
                }
            }
            foreach (['callback_uri', 'callback_url', 'redirect_uri'] as $field) {
                if ($callback === null && is_string($json[$field] ?? null) && preg_match('#^https://[A-Za-z0-9._:-]+/admin/oauth2callback\.php$#D', $json[$field]) === 1) {
                    $callback = $json[$field];
                }
            }
        }
        $instructions = $documents['oauth2_manual'] ?? null;

        return ['instructions' => is_string($instructions) ? mb_substr($this->redactor->redactString($instructions), 0, 6000) : null,
            'callback_uri' => $callback, 'issues' => array_values(array_unique(array_slice($issues, 0, 20)))];
    }

    /**
     * @param  array<string, string|null>  $documents
     * @return array<string, mixed>
     */
    private function plugins(array $documents): array
    {
        $needs = [];
        foreach ($this->json($documents['plugin_needs'] ?? null)['original_compatibility_needs'] ?? [] as $need) {
            if (is_array($need) && is_string($need['component'] ?? null) && count($needs) < 50) {
                $needs[] = ['component' => mb_substr($need['component'], 0, 100),
                    'display_name' => is_string($need['display_name'] ?? null) ? mb_substr($need['display_name'], 0, 120) : null,
                    'observed_state' => is_string($need['observed_state'] ?? null) ? mb_substr($need['observed_state'], 0, 60) : null];
            }
        }
        $diff = $this->json($documents['plugin_diff'] ?? null);
        $names = fn (mixed $items): array => array_values(array_slice(array_filter(array_map(
            fn ($item): ?string => is_array($item) && is_string($item['component'] ?? null) ? mb_substr($item['component'], 0, 100) : null,
            is_array($items) ? $items : [])), 0, 50));
        $technical = [];
        foreach ($this->json($documents['plugin_technical'] ?? null)['issues'] ?? [] as $issue) {
            $text = is_string($issue) ? $issue : (is_array($issue) && is_string($issue['message'] ?? null) ? $issue['message'] : null);
            if ($text !== null && count($technical) < 20) {
                $technical[] = mb_substr($this->redactor->redactString($text), 0, 300);
            }
        }
        $status = $this->json($documents['plugin_checkpoint'] ?? null)['status'] ?? null;
        // The lock was resolved from the sources' needs and asks for nothing: neither catalog plugins
        // nor unknown components. After a failed validation the assistant archives the needs
        // document; when it is still there it must be empty too.
        $lock = $this->json($documents['plugin_lock'] ?? null);
        $needsDocument = $this->json($documents['plugin_needs'] ?? null);
        $none = is_string($lock['compatibility_needs_sha256'] ?? null) && ($lock['plugins'] ?? null) === [] && ($lock['unknown_plugins'] ?? null) === []
            && (($documents['plugin_needs'] ?? null) === null || ($needsDocument['original_compatibility_needs'] ?? null) === []);
        $unknown = [];
        foreach (is_array($lock['unknown_plugins'] ?? null) ? array_slice($lock['unknown_plugins'], 0, 20) : [] as $plugin) {
            $component = is_array($plugin) ? ($plugin['component'] ?? null) : null;
            if (is_string($component) && preg_match('/^[a-z][a-z0-9]*_[a-z][a-z0-9_]{0,60}$/D', $component) === 1) {
                $unknown[] = $component;
            }
        }

        return ['needs' => $needs, 'new_plugins' => $names($diff['newly_detected_plugins'] ?? null), 'updated_plugins' => $names($diff['updated_plugins'] ?? null),
            'removed_plugins' => $names($diff['removed_plugins'] ?? null), 'technical_issues' => $technical,
            'checkpoint_status' => is_string($status) && preg_match('/^[A-Z_]{1,64}$/D', $status) === 1 ? $status : null,
            'plugins_none' => $none, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * Every fuzzy candidate group, read a page at a time with closed fields. Names and
     * e-mails are shown only to the administrator deciding the review.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function identities(Execution $execution, array $settings): array
    {
        $target = $this->target($execution, $settings);
        $candidates = [];
        $total = null;
        while ($target !== null && count($candidates) < self::MAX_CANDIDATES) {
            try {
                $response = $this->agent->call($target, 'identity-candidates', [(string) $settings['destination_profile'], (string) count($candidates)], 30);
            } catch (RemoteAgentUnavailable) {
                break;
            }
            $page = $response->ok && is_array($response->data['candidates'] ?? null) ? $response->data['candidates'] : [];
            $total = is_int($response->data['total'] ?? null) ? $response->data['total'] : $total;
            foreach ($page as $candidate) {
                if (is_array($candidate) && is_string($candidate['group'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $candidate['group']) === 1) {
                    $candidates[] = $this->candidate($candidate);
                }
            }
            if ($page === [] || $total === null || count($candidates) >= $total) {
                break;
            }
        }

        return ['candidates' => $candidates, 'candidate_total' => $total,
            'candidates_complete' => $total !== null && $total > 0 && count($candidates) === $total];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function candidate(array $candidate): array
    {
        $text = fn (mixed $value, int $size = 120): ?string => is_string($value) && $value !== '' ? mb_substr($value, 0, $size) : null;
        $side = fn (mixed $side): array => is_array($side) ? ['source' => $text($side['source'] ?? null, 64), 'user_id' => $text($side['user_id'] ?? null, 32),
            'canonical_id' => $text($side['canonical_id'] ?? null, 64), 'name' => $text($side['name'] ?? null), 'email' => $text($side['email'] ?? null),
            'google' => $text($side['google'] ?? null, 40), 'members' => array_values(array_filter(array_map(fn ($member): ?string => $text($member, 80),
                is_array($side['members'] ?? null) ? array_slice($side['members'], 0, 20) : []))),
            // Agent 1.14+: each member's existing username and the last digits of its document.
            'accounts' => array_values(array_filter(array_map(fn ($account): ?array => is_array($account) && is_string($account['key'] ?? null)
                && preg_match('/^[A-Za-z0-9_.-]{1,64}:[0-9]{1,15}$/D', $account['key']) === 1 ? ['key' => $account['key'], 'name' => $text($account['name'] ?? null),
                    'email' => $text($account['email'] ?? null), 'username' => $text($account['username'] ?? null, 100),
                    'document' => $text($account['document'] ?? null, 8)] : null,
                is_array($side['accounts'] ?? null) ? array_slice($side['accounts'], 0, 20) : [])))] : [];

        return ['group' => $candidate['group'], 'type' => $text($candidate['type'] ?? null, 60), 'confidence' => $text($candidate['confidence'] ?? null, 30),
            'name_similarity' => $text($candidate['name_similarity'] ?? null, 12), 'email_similarity' => $text($candidate['email_similarity'] ?? null, 12),
            'identifier_match' => $text($candidate['identifier_match'] ?? null, 12), 'evidence' => $text($candidate['evidence'] ?? null, 200),
            'a' => $side($candidate['a'] ?? null), 'b' => $side($candidate['b'] ?? null)];
    }

    /**
     * The proposed tree read a page at a time with closed fields: the destination's existing
     * categories, every source category with its courses, the merge suggestions and, when a
     * map was refused, the Consolidator's reasons. The previous answer of this Execution lets
     * the administrator correct the map instead of starting again.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function categoryMap(Execution $execution, array $settings): array
    {
        $target = $this->target($execution, $settings);
        $text = fn (mixed $value, int $size = 255): string => is_string($value) ? mb_substr($value, 0, $size) : '';
        $int = fn (mixed $value): int => is_int($value) ? $value : 0;
        $header = null;
        $existing = $categories = $suggestions = [];
        $offset = 0;
        $total = null;
        for ($page = 0; $target !== null && $page < self::MAX_MAP_PAGES; $page++) {
            try {
                $response = $this->agent->call($target, 'category-map-proposal', [(string) $settings['destination_profile'], (string) $offset], 30);
            } catch (RemoteAgentUnavailable) {
                break;
            }
            if (! $response->ok) {
                break;
            }
            $header ??= is_array($response->data['header'] ?? null) ? $response->data['header'] : null;
            $total = is_int($response->data['total'] ?? null) ? $response->data['total'] : null;
            foreach (is_array($response->data['items'] ?? null) ? $response->data['items'] : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $kind = $item['k'] ?? null;
                if ($kind === 'e') {
                    $existing[] = ['id' => $int($item['id'] ?? null), 'parent_id' => $int($item['parent_id'] ?? null), 'name' => $text($item['name'] ?? null),
                        'depth' => $int($item['depth'] ?? null), 'course_count' => $int($item['course_count'] ?? null)];
                } elseif ($kind === 'c' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $text($item['source'] ?? null, 64)) === 1) {
                    $courses = [];
                    foreach (is_array($item['courses'] ?? null) ? array_slice($item['courses'], 0, 20) : [] as $course) {
                        if (is_array($course)) {
                            $courses[] = ['id' => $int($course['id'] ?? null), 'shortname' => $text($course['shortname'] ?? null, 100),
                                'fullname' => $text($course['fullname'] ?? null, 120)];
                        }
                    }
                    $categories[] = ['source' => $text($item['source'], 64), 'id' => $int($item['id'] ?? null), 'parent_id' => $int($item['parent_id'] ?? null),
                        'name' => $text($item['name'] ?? null), 'depth' => $int($item['depth'] ?? null), 'course_count' => $int($item['course_count'] ?? null),
                        'courses' => $courses];
                } elseif ($kind === 's') {
                    $side = fn (mixed $value): array => ['source' => $text(is_array($value) ? ($value['source'] ?? null) : null, 64),
                        'id' => $int(is_array($value) ? ($value['id'] ?? null) : null)];
                    $suggestions[] = ['kind' => ($item['kind'] ?? null) === 'same' ? 'same' : 'similar',
                        'score' => is_numeric($item['score'] ?? null) ? round((float) $item['score'], 3) : 0.0,
                        'a' => $side($item['a'] ?? null), 'b' => $side($item['b'] ?? null)];
                }
            }
            $next = is_int($response->data['next'] ?? null) ? $response->data['next'] : $offset;
            $advanced = $next > $offset;
            $offset = $next;
            if ($total === null || ! $advanced || $offset >= $total) {
                break;
            }
        }
        $sha = is_array($header) && is_string($header['proposal_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $header['proposal_sha256']) === 1
            ? $header['proposal_sha256'] : null;
        $sources = [];
        foreach (is_array($header['sources'] ?? null) ? array_slice($header['sources'], 0, 20) : [] as $source) {
            if (is_array($source) && is_string($source['source'] ?? null)) {
                $sources[] = ['source' => $text($source['source'], 64), 'alias' => $text($source['alias'] ?? null, 3), 'name' => $text($source['name'] ?? null, 120)];
            }
        }
        $pilotHeader = is_array($header['pilot'] ?? null) ? $header['pilot'] : null;
        $pilot = $pilotHeader === null ? null : ['source' => $text($pilotHeader['source'] ?? null, 64),
            'source_category_id' => $int($pilotHeader['source_category_id'] ?? null), 'shortname' => $text($pilotHeader['shortname'] ?? null, 100)];
        $issues = [];
        foreach (is_array($header['issues'] ?? null) ? array_slice($header['issues'], 0, 50) : [] as $issue) {
            if (is_array($issue)) {
                $issues[] = ['code' => $text($issue['code'] ?? null, 40), 'node' => is_string($issue['node'] ?? null) ? $text($issue['node'], 64) : null,
                    'message' => mb_substr($this->redactor->redactString($text($issue['message'] ?? null, 300)), 0, 300)];
            }
        }
        $previous = null;
        foreach (Conflict::query()->where('execution_id', $execution->id)->where('type', self::CATEGORY_MAP)->orderByDesc('id')->get() as $conflict) {
            $answers = $conflict->details['answers'] ?? null;
            if (is_array($answers)) {
                $previous = ['nodes' => $answers['nodes'] ?? [], 'assignments' => $answers['assignments'] ?? []];

                break;
            }
        }

        return ['proposal_sha256' => $sha, 'map_sources' => $sources, 'existing' => $existing, 'categories' => $categories,
            'suggestions' => $suggestions, 'pilot' => $pilot, 'map_issues' => $issues,
            'map_complete' => $sha !== null && $total !== null && $offset >= $total && $categories !== [],
            'previous_map' => $previous];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function themes(Execution $execution, array $settings): array
    {
        $target = $this->target($execution, $settings);
        try {
            $response = $target === null ? null : $this->agent->call($target, 'theme-options', [(string) $settings['destination_profile']], 30);
        } catch (RemoteAgentUnavailable) {
            $response = null;
        }
        $data = $response?->ok ? $response->data : [];
        $name = fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,60}$/D', $value) === 1 ? $value : null;
        $legacy = [];
        foreach (is_array($data['legacy_sources'] ?? null) ? array_slice($data['legacy_sources'], 0, 20) : [] as $source) {
            if (is_array($source) && is_string($source['source'] ?? null)) {
                $legacy[] = ['source' => mb_substr($source['source'], 0, 64),
                    'collector_version' => is_string($source['collector_version'] ?? null) ? mb_substr($source['collector_version'], 0, 32) : null];
            }
        }

        return ['themes' => array_values(array_filter(array_map($name, is_array($data['themes'] ?? null) ? $data['themes'] : []))),
            'theme_selected' => $name($data['selected'] ?? null), 'theme_default' => $name($data['default'] ?? null),
            'legacy_sources' => $legacy, 'legacy_accepted' => ($data['legacy_accepted'] ?? false) === true];
    }

    /** @param array<string, mixed> $settings */
    private function target(Execution $execution, array $settings): ?RemoteTarget
    {
        $server = Server::query()->where('project_id', $execution->project_id)->where('uuid', (string) ($settings['server_uuid'] ?? ''))->first();

        return $server === null ? null : $this->connections->target($server);
    }

    /** @return array<string, mixed> */
    private function json(?string $document): array
    {
        $decoded = is_string($document) ? json_decode($document, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
