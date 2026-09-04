<?php

namespace App\Domain\Academic;

use App\Domain\Academic\DTOs\AcademicProposalResult;
use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Tools\DTOs\NormalizedToolEvent;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\ProjectIsReadOnly;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposeAcademicChange
{
    public const OPERATIONS = ['RENAME_CATEGORY', 'MOVE_CATEGORY', 'MOVE_COURSE', 'CHANGE_VISIBLE_NAME'];

    public function __construct(
        private readonly AcademicPreview $preview,
        private readonly ExecutionEventRecorder $events,
    ) {}

    /** @param array{operation: string, node_id: string, value: string, expected_version: int, base_fingerprint: string} $input */
    public function propose(Execution $execution, User $actor, array $input, string $idempotencyKey): AcademicProposalResult
    {
        return DB::transaction(function () use ($execution, $actor, $input, $idempotencyKey): AcademicProposalResult {
            $seed = Execution::query()->select(['id', 'project_id'])->findOrFail((int) $execution->getKey());
            $project = Project::query()->lockForUpdate()->findOrFail($seed->project_id);
            $locked = Execution::query()->lockForUpdate()->findOrFail((int) $seed->getKey());
            $locked->setRelation('project', $project);
            $this->authorize($locked, $actor);
            $payload = [
                'operation' => $input['operation'],
                'node_id' => $input['node_id'],
                'value' => $input['value'],
                'expected_version' => $input['expected_version'],
                'base_fingerprint' => $input['base_fingerprint'],
            ];
            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = $locked->commands()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->command_type !== ExecutionCommandType::PROPOSE
                    || ! hash_equals((string) $existing->payload_hash, $payloadHash)
                ) {
                    throw new IdempotencyKeyConflict;
                }

                return new AcademicProposalResult(
                    $locked->academicProposals()->where('version', $existing->attempt)->firstOrFail(),
                    false,
                );
            }

            if ($project->status === ProjectStatus::COMPLETED || $locked->status === ExecutionStatus::COMPLETED) {
                throw new ProjectIsReadOnly;
            }

            if ($project->status !== ProjectStatus::REVIEW || $locked->status !== ExecutionStatus::REVIEW) {
                throw ValidationException::withMessages([
                    'execution' => $locked->status === ExecutionStatus::VERIFYING
                        ? 'No se pueden modificar propuestas mientras una validación está activa.'
                        : 'Las propuestas sólo se permiten durante REVIEW.',
                ]);
            }

            if ($locked->commands()->whereNull('processed_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['execution' => 'Existe una operación activa; espere antes de modificar propuestas.']);
            }

            if ($locked->proposal_version !== $input['expected_version']
                || $locked->review_fingerprint === null
                || ! hash_equals($locked->review_fingerprint, $input['base_fingerprint'])
            ) {
                throw ValidationException::withMessages(['expected_version' => 'La propuesta se basó en una versión obsoleta. Recargue la previsualización.']);
            }

            $nodes = $this->preview->state($locked);
            $operation = $input['operation'];

            if (! in_array($operation, self::OPERATIONS, true)) {
                throw ValidationException::withMessages(['operation' => 'La operación solicitada no está permitida.']);
            }

            $node = collect($nodes)->firstWhere('id', $input['node_id']);

            if (! is_array($node)) {
                throw ValidationException::withMessages(['node_id' => 'El nodo académico ya no existe.']);
            }

            [$oldValue, $newValue] = $this->validatedChange($nodes, $node, $operation, $input['value']);
            $nextVersion = $locked->proposal_version + 1;
            $changed = $this->preview->apply($nodes, $operation, (string) $node['id'], $newValue);
            $fingerprint = $this->preview->fingerprint($changed, $nextVersion);
            $proposal = $locked->academicProposals()->create([
                'version' => $nextVersion,
                'operation' => $operation,
                'node_id' => $node['id'],
                'node_type' => $node['type'],
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'base_fingerprint' => $locked->review_fingerprint,
                'resulting_fingerprint' => $fingerprint,
                'status' => 'ACTIVE',
                'proposed_by' => $actor->getKey(),
            ]);
            $command = $locked->commands()->create([
                'step_key' => 'academic-review',
                'attempt' => $nextVersion,
                'command_type' => ExecutionCommandType::PROPOSE,
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope' => "execution:{$locked->getKey()}:proposal:{$nextVersion}",
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'created_by' => $actor->getKey(),
                'processed_at' => now(),
            ]);

            $locked->proposal_version = $nextVersion;
            $locked->review_fingerprint = $fingerprint;
            $locked->validated_proposal_version = null;
            $locked->validated_fingerprint = null;
            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'project_id' => $project->getKey(),
                'execution_id' => $locked->getKey(),
                'action' => 'ACADEMIC_PROPOSAL_CREATED',
                'auditable_type' => $proposal->getMorphClass(),
                'auditable_id' => $proposal->getKey(),
                'old_values' => $oldValue,
                'new_values' => $newValue,
                'payload' => ['operation' => $operation, 'node_id' => $node['id'], 'version' => $nextVersion, 'base_fingerprint' => $input['base_fingerprint'], 'command_id' => $command->getKey()],
            ]);
            $this->events->recordNormalized($locked, new NormalizedToolEvent(
                'academic.proposal.created',
                stepKey: 'verification',
                progress: $locked->progress,
                message: 'La propuesta académica se almacenó sin modificar Moodle y requiere una nueva validación.',
                payload: ['proposal_id' => $proposal->getKey(), 'version' => $nextVersion, 'fingerprint' => $fingerprint],
            ));

            return new AcademicProposalResult($proposal, true);
        }, attempts: 3);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $node
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function validatedChange(array $nodes, array $node, string $operation, string $rawValue): array
    {
        $byId = collect($nodes)->keyBy('id');

        if (in_array($operation, ['RENAME_CATEGORY', 'CHANGE_VISIBLE_NAME'], true)) {
            $name = trim($rawValue);
            $expectedType = $operation === 'RENAME_CATEGORY' ? 'category' : 'course';

            if ($node['type'] !== $expectedType) {
                throw ValidationException::withMessages(['operation' => 'La operación no corresponde al tipo de nodo.']);
            }

            if ($name === '' || mb_strlen($name) > 160) {
                throw ValidationException::withMessages(['value' => 'El nombre debe contener entre 1 y 160 caracteres.']);
            }

            if ($name === $node['name']) {
                throw ValidationException::withMessages(['value' => 'La propuesta no produce ningún cambio.']);
            }

            $collision = collect($nodes)->contains(fn (array $candidate): bool => $candidate['id'] !== $node['id']
                && $candidate['type'] === $node['type']
                && $candidate['parent_id'] === $node['parent_id']
                && mb_strtolower((string) $candidate['name']) === mb_strtolower($name));

            if ($collision) {
                throw ValidationException::withMessages(['value' => 'Ya existe un nodo con ese nombre en la ubicación propuesta.']);
            }

            return [['name' => $node['name']], ['name' => $name]];
        }

        $destination = $byId->get($rawValue);

        if (! is_array($destination) || $destination['type'] !== 'category') {
            throw ValidationException::withMessages(['value' => 'La categoría de destino no existe o no es válida.']);
        }

        if ($operation === 'MOVE_CATEGORY' && $node['type'] !== 'category') {
            throw ValidationException::withMessages(['operation' => 'Sólo una categoría puede usar MOVE_CATEGORY.']);
        }

        if ($operation === 'MOVE_COURSE' && $node['type'] !== 'course') {
            throw ValidationException::withMessages(['operation' => 'Sólo un curso puede usar MOVE_COURSE.']);
        }

        if ($node['parent_id'] === $destination['id']) {
            throw ValidationException::withMessages(['value' => 'El nodo ya se encuentra en esa categoría.']);
        }

        if ($operation === 'MOVE_CATEGORY') {
            if ($node['id'] === $destination['id'] || $this->isDescendant((string) $destination['id'], (string) $node['id'], $byId->all())) {
                throw ValidationException::withMessages(['value' => 'Una categoría no puede moverse dentro de sí misma ni de sus descendientes.']);
            }
        }

        $collision = collect($nodes)->contains(function (array $candidate) use ($node, $destination): bool {
            if ($candidate['id'] === $node['id'] || $candidate['type'] !== $node['type'] || $candidate['parent_id'] !== $destination['id']) {
                return false;
            }

            if ($node['type'] === 'course' && $candidate['short_name'] === $node['short_name']) {
                return true;
            }

            return mb_strtolower((string) $candidate['name']) === mb_strtolower((string) $node['name']);
        });

        if ($collision) {
            throw ValidationException::withMessages(['value' => 'El movimiento produciría una colisión de nombre o ubicación.']);
        }

        return [['parent_id' => $node['parent_id']], ['parent_id' => $destination['id']]];
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function isDescendant(string $candidateId, string $ancestorId, array $nodes): bool
    {
        $cursor = $nodes[$candidateId] ?? null;
        $seen = [];

        while (is_array($cursor) && $cursor['parent_id'] !== null) {
            if (isset($seen[$cursor['id']])) {
                return true;
            }

            $seen[$cursor['id']] = true;

            if ($cursor['parent_id'] === $ancestorId) {
                return true;
            }

            $cursor = $nodes[$cursor['parent_id']] ?? null;
        }

        return false;
    }

    private function authorize(Execution $execution, User $actor): void
    {
        if (! $actor->can('control', $execution)) {
            throw new AuthorizationException;
        }

        if (! $actor->isAdmin() && ! ProjectAssignment::query()
            ->where('project_id', $execution->project_id)
            ->where('user_id', $actor->getKey())
            ->lockForUpdate()
            ->exists()) {
            throw new AuthorizationException;
        }
    }
}
