<?php

namespace App\Domain\Remote;

use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Enums\ConflictStatus;
use App\Exceptions\RemoteAgentUnavailable;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Server;

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

    public const TYPES = [self::OAUTH2, self::PLUGINS, self::GENERIC];

    private const OAUTH2_STAGES = ['02-oauth2', '03b-oauth2-final'];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly SensitiveValueRedactor $redactor,
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
        $type = match (true) {
            in_array($stage, self::OAUTH2_STAGES, true) => self::OAUTH2,
            $stage === '03-plugins' => self::PLUGINS,
            default => self::GENERIC,
        };
        $documents = $type === self::GENERIC ? [] : $this->documents($execution, $settings, $type === self::OAUTH2 ? 'oauth2' : 'plugins');
        $details = ['kind' => $type, 'stage' => $stage, 'message' => is_string($report['message'] ?? null) ? $report['message'] : null,
            'review_paths' => is_array($report['review_paths'] ?? null) ? $report['review_paths'] : [], ...match ($type) {
                self::OAUTH2 => $this->oauth2($documents),
                self::PLUGINS => $this->plugins($documents),
                default => [],
            }];
        $details['allowed_decisions'] = $type === self::PLUGINS && ($details['checkpoint_status'] ?? null) === 'READY_FOR_APPROVAL'
            ? ['ACCEPT', 'CONFIRM_COMPLETED'] : ['CONFIRM_COMPLETED'];
        $step = ExecutionStep::query()->where('execution_id', $execution->id)->where('step_key', $stage ?? '01-importar-paquetes')->first();

        return Conflict::query()->firstOrCreate(['execution_id' => $execution->id, 'key' => 'consolidator.'.($stage ?? 'assistant').'.'.$run],
            ['execution_step_id' => $step?->id, 'type' => $type, 'status' => ConflictStatus::OPEN, 'version' => 1, 'details' => $details]);
    }

    /**
     * The intervention fields that leave the backend, whitelisted again on the way out.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function present(array $details): array
    {
        return array_intersect_key($details, array_flip(['kind', 'stage', 'message', 'review_paths', 'instructions', 'callback_uri', 'issues',
            'needs', 'new_plugins', 'updated_plugins', 'removed_plugins', 'technical_issues', 'checkpoint_status']));
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

        return ['needs' => $needs, 'new_plugins' => $names($diff['newly_detected_plugins'] ?? null), 'updated_plugins' => $names($diff['updated_plugins'] ?? null),
            'removed_plugins' => $names($diff['removed_plugins'] ?? null), 'technical_issues' => $technical,
            'checkpoint_status' => is_string($status) && preg_match('/^[A-Z_]{1,64}$/D', $status) === 1 ? $status : null];
    }

    /** @return array<string, mixed> */
    private function json(?string $document): array
    {
        $decoded = is_string($document) ? json_decode($document, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
