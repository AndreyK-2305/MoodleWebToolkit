<?php

namespace Tests\Unit;

use App\Domain\Artifacts\LocalArtifactStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ArtifactReferenceStorageTest extends TestCase
{
    public function test_large_artifact_reference_uses_the_same_file_without_copying(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $sourcePath = 'workspaces/reference/source.mbz';
        $disk->put($sourcePath, str_repeat('course-data-', 1000));
        $source = $disk->path($sourcePath);

        $stored = (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/execution/course.mbz');

        $this->assertSame(strlen(str_repeat('course-data-', 1000)), $stored->size);
        $this->assertSame(hash_file('sha256', $source), $stored->checksum);
        $this->assertSame(fileinode($source), fileinode($disk->path($stored->path)));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0, fileperms($source) & 0222, 'chmod on the artifact hard link also makes the producer inode read-only.');
        }
    }

    public function test_artifact_reference_rejects_paths_outside_private_storage(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);
        (new LocalArtifactStorage)->referenceExisting(__FILE__, 'artifacts/outside.php');
    }

    public function test_artifact_reference_rejects_an_incorrect_expected_hash_without_creating_a_target(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('workspaces/reference/source.bin', 'expected data');
        $source = $disk->path('workspaces/reference/source.bin');

        try {
            (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/execution/wrong.bin', str_repeat('0', 64));
            $this->fail('A mismatched expected hash must block the reference.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('hash esperado', $exception->getMessage());
            $this->assertFalse($disk->exists('artifacts/execution/wrong.bin'));
        }
    }

    public function test_artifact_reference_never_overwrites_an_existing_target(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('workspaces/reference/source.bin', 'source');
        $disk->put('artifacts/execution/existing.bin', 'existing');

        try {
            (new LocalArtifactStorage)->referenceExisting(
                $disk->path('workspaces/reference/source.bin'),
                'artifacts/execution/existing.bin',
            );
            $this->fail('An existing target must block the hard link.');
        } catch (\RuntimeException) {
            $this->assertSame('existing', $disk->get('artifacts/execution/existing.bin'));
            $this->assertSame('source', $disk->get('workspaces/reference/source.bin'));
        }
    }

    public function test_source_and_parent_symlinks_are_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The Linux quality stack supplies symlink privileges.');
        }
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('workspaces/reference/source.bin', 'source');
        $source = $disk->path('workspaces/reference/source.bin');
        $this->assertTrue(symlink($source, $disk->path('workspaces/reference/alias.bin')));
        $this->assertTrue(symlink(dirname($source), $disk->path('workspaces/alias')));
        $this->assertTrue(symlink(dirname($source), $disk->path('artifacts-alias')));
        $storage = new LocalArtifactStorage;
        foreach ([
            [$disk->path('workspaces/reference/alias.bin'), 'artifacts/source-link.bin'],
            [$disk->path('workspaces/alias/source.bin'), 'artifacts/parent-link.bin'],
            [$source, 'artifacts-alias/target.bin'],
        ] as [$candidate, $target]) {
            try {
                $storage->referenceExisting($candidate, $target);
                $this->fail('A source or destination symlink must block registration.');
            } catch (InvalidArgumentException) {
                $this->assertFalse($disk->exists($target));
            }
        }
        $this->assertSame('source', $disk->get('workspaces/reference/source.bin'));
    }

    public function test_concurrent_source_modification_cannot_register_the_expected_original_bytes(): void
    {
        $this->requireForkSupport();
        Storage::fake('local');
        $disk = Storage::disk('local');
        $original = str_repeat('a', 1024 * 1024);
        $disk->put('workspaces/reference/source.bin', $original);
        $source = $disk->path('workspaces/reference/source.bin');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($pair[0]);
            $handle = fopen($source, 'r+b');
            fwrite($handle, 'b');
            fflush($handle);
            fwrite($pair[1], '1');
            stream_set_blocking($pair[1], false);
            while (fread($pair[1], 1) !== '1') {
                fseek($handle, 1);
                fwrite($handle, random_bytes(64));
                fflush($handle);
                usleep(1000);
            }
            fclose($handle);
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        fread($pair[0], 1);
        try {
            (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/changed.bin', hash('sha256', $original), strlen($original));
            $this->fail('A concurrent producer must not register the original content identity.');
        } catch (\RuntimeException) {
            $this->assertFalse($disk->exists('artifacts/changed.bin'));
        } finally {
            fwrite($pair[0], '1');
            fclose($pair[0]);
            pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_two_concurrent_registrations_have_one_winner_and_preserve_its_content(): void
    {
        $this->requireForkSupport();
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('workspaces/reference/source.bin', 'same source');
        $disk->makeDirectory('artifacts');
        $source = $disk->path('workspaces/reference/source.bin');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($pair[0]);
            fread($pair[1], 1);
            try {
                (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/winner.bin');
                fwrite($pair[1], '1');
            } catch (\RuntimeException) {
                fwrite($pair[1], '0');
            }
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        fwrite($pair[0], '1');
        try {
            (new LocalArtifactStorage)->referenceExisting($source, 'artifacts/winner.bin');
            $parentWon = 1;
        } catch (\RuntimeException) {
            $parentWon = 0;
        }
        $childWon = (int) fread($pair[0], 1);
        fclose($pair[0]);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(1, $parentWon + $childWon);
        $this->assertSame('same source', $disk->get('artifacts/winner.bin'));
        $this->assertSame(fileinode($source), fileinode($disk->path('artifacts/winner.bin')));
    }

    private function requireForkSupport(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concurrent filesystem regressions execute in Linux with pcntl.');
        }
    }
}
