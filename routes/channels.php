<?php

use App\Domain\Realtime\ProjectSessionChannels;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('projects.{projectUuid}.sessions.{sessionToken}', function (
    User $user,
    string $projectUuid,
    string $sessionToken,
): bool {
    $project = Project::query()->where('uuid', $projectUuid)->first();

    return $project !== null && app(ProjectSessionChannels::class)->canSubscribe(
        $user,
        $project,
        $sessionToken,
    );
});
