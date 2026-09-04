<?php

namespace App\Domain\Realtime;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectSessionChannels
{
    public function current(Project $project, string $sessionId): string
    {
        return sprintf(
            'projects.%s.sessions.%s',
            $project->uuid,
            $this->token($project->uuid, $sessionId),
        );
    }

    public function canSubscribe(User $user, Project $project, string $token): bool
    {
        return $user->can('view', $project)
            && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }

    /** @return list<string> */
    public function authorized(Project $project): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        $authorizedUserIds = User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($project): void {
                $query->where('role', UserRole::ADMIN)
                    ->orWhereHas(
                        'projectAssignments',
                        fn (Builder $assignments): Builder => $assignments->where('project_id', $project->getKey()),
                    );
            })
            ->pluck('id');

        if ($authorizedUserIds->isEmpty()) {
            return [];
        }

        $userIds = array_values($authorizedUserIds
            ->map(fn (mixed $userId): int => (int) $userId)
            ->values()
            ->all());

        return array_values($this->activeSessionIds($userIds)
            ->map(fn (string $sessionId): string => $this->current($project, $sessionId))
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, string>
     */
    private function activeSessionIds(array $userIds): Collection
    {
        $lastActivity = (int) now()->getTimestamp() - ((int) config('session.lifetime') * 60);
        $connection = config('session.connection');

        return DB::connection(is_string($connection) ? $connection : null)
            ->table((string) config('session.table'))
            ->whereIn('user_id', $userIds)
            ->where('last_activity', '>=', $lastActivity)
            ->pluck('id')
            ->map(fn (mixed $sessionId): string => (string) $sessionId)
            ->values();
    }

    private function token(string $projectUuid, string $sessionId): string
    {
        return hash_hmac(
            'sha256',
            $projectUuid.'|'.$sessionId,
            (string) config('app.key'),
        );
    }
}
