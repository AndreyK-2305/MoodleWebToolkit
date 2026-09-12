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
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtifactController extends Controller
{
    public function download(
        Request $request,
        Project $project,
        Execution $execution,
        Artifact $artifact,
        DownloadArtifact $download,
    ): StreamedResponse {
        abort_unless($execution->project_id === $project->getKey() && $artifact->execution_id === $execution->getKey(), 404);
        Gate::authorize('view', $execution);
        $validated = $request->validate([
            'key' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        try {
            /** @var User $actor */
            $actor = $request->user();
            $stream = $download->prepare($artifact, $actor, (string) $validated['key']);
        } catch (ArtifactIntegrityException $exception) {
            abort(str_contains($exception->getMessage(), 'ya no existe') ? 410 : 409, $exception->getMessage());
        }

        return response()->streamDownload(function () use ($stream): void {
            try {
                while (! $stream->eof()) {
                    echo $stream->read();
                }
            } finally {
                $stream->close();
            }
        }, $artifact->filename, [
            'Content-Type' => $artifact->mime_type ?? 'application/octet-stream',
            'Content-Length' => (string) $artifact->size,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
