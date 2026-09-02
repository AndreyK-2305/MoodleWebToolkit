<?php

namespace Tests\Feature\Domain;

use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Models\Checkpoint;
use App\Models\ExecutionStep;
use Illuminate\Database\QueryException;

class CheckpointIndependenceTest extends DomainTestCase
{
    public function test_successful_step_is_not_resumable_without_a_validated_checkpoint(): void
    {
        $execution = $this->execution($this->project(), ExecutionStatus::FAILED);
        $step = ExecutionStep::query()->create([
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'name' => 'Recolectar',
            'position' => 1,
            'status' => ExecutionStepStatus::SUCCESS,
        ]);

        $this->assertFalse($step->hasValidatedCheckpoint());

        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque',
            'validated' => false,
        ]);

        $this->assertFalse($checkpoint->isResumable());
        $this->assertFalse($step->hasValidatedCheckpoint());

        $checkpoint->validated = true;
        $checkpoint->save();

        $this->assertTrue($checkpoint->isResumable());
        $this->assertTrue($step->hasValidatedCheckpoint());
    }

    public function test_compatible_validated_checkpoint_references_are_persisted(): void
    {
        $project = $this->project();
        $previous = $this->execution($project, ExecutionStatus::FAILED, 1);
        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $previous->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque',
            'validated' => true,
        ]);

        $resumed = $this->execution(
            project: $project,
            status: ExecutionStatus::QUEUED,
            attempt: 2,
        );
        $resumed->update([
            'resumed_from_execution_id' => $previous->getKey(),
            'resume_checkpoint_id' => $checkpoint->getKey(),
        ]);

        $this->assertTrue($resumed->resumedFromExecution->is($previous));
        $this->assertTrue($resumed->resumeCheckpoint->is($checkpoint));
        $this->assertTrue($checkpoint->resumedExecutions->first()->is($resumed));
    }

    public function test_resume_reference_requires_both_execution_and_checkpoint(): void
    {
        $project = $this->project();
        $previous = $this->execution($project, ExecutionStatus::FAILED, 1);

        $this->expectException(QueryException::class);

        $this->execution($project, ExecutionStatus::QUEUED, 2)->update([
            'resumed_from_execution_id' => $previous->getKey(),
        ]);
    }

    public function test_unvalidated_checkpoint_cannot_be_attached_to_a_resumed_execution(): void
    {
        $project = $this->project();
        $previous = $this->execution($project, ExecutionStatus::FAILED, 1);
        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $previous->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque',
            'validated' => false,
        ]);

        $this->expectException(QueryException::class);

        $this->execution($project, ExecutionStatus::QUEUED, 2)->update([
            'resumed_from_execution_id' => $previous->getKey(),
            'resume_checkpoint_id' => $checkpoint->getKey(),
        ]);
    }

    public function test_checkpoint_must_belong_to_the_referenced_previous_execution(): void
    {
        $project = $this->project();
        $previous = $this->execution($project, ExecutionStatus::FAILED, 1);
        $other = $this->execution($project, ExecutionStatus::FAILED, 2);
        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $other->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque',
            'validated' => true,
        ]);

        $this->expectException(QueryException::class);

        $this->execution($project, ExecutionStatus::QUEUED, 3)->update([
            'resumed_from_execution_id' => $previous->getKey(),
            'resume_checkpoint_id' => $checkpoint->getKey(),
        ]);
    }

    public function test_previous_execution_must_belong_to_the_same_project(): void
    {
        $firstProject = $this->project();
        $secondProject = $this->project();
        $previous = $this->execution($firstProject, ExecutionStatus::FAILED, 1);
        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $previous->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque',
            'validated' => true,
        ]);

        $this->expectException(QueryException::class);

        $this->execution($secondProject, ExecutionStatus::QUEUED, 2)->update([
            'resumed_from_execution_id' => $previous->getKey(),
            'resume_checkpoint_id' => $checkpoint->getKey(),
        ]);
    }
}
