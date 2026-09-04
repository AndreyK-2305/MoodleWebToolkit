<?php

namespace App\Http\Controllers;

use App\Domain\Artifacts\DownloadArtifact;
use App\Exceptions\ArtifactIntegrityException;
use App\Models\Artifact;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class ArtifactController extends Controller
{
    public function download(
        Request $request,
        Project $project,
        Execution $execution,
        Artifact $artifact,
        DownloadArtifact $download,
    ): Response {
        abort_unless($execution->project_id === $project->getKey() && $artifact->execution_id === $execution->getKey(), 404);
        Gate::authorize('view', $execution);
        $validated = $request->validate([
            'key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        try {
            /** @var User $actor */
            $actor = $request->user();
            $contents = $download->contents($artifact, $actor, (string) $validated['key']);
        } catch (ArtifactIntegrityException $exception) {
            abort(str_contains($exception->getMessage(), 'ya no existe') ? 410 : 409, $exception->getMessage());
        }

        return response($contents, 200, [
            'Content-Type' => $artifact->mime_type ?? 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $artifact->filename, 'artifact.bin'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
