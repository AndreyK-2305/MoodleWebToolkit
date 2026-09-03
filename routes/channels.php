<?php

use App\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('projects.{projectUuid}', function ($user, string $projectUuid): bool {
    $project = Project::query()->where('uuid', $projectUuid)->first();

    return $project !== null && $user->can('view', $project);
});
