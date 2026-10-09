<?php

namespace Tests\Unit;

use App\Domain\Collector\CollectorSourceEvidence;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class CollectorSourceEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/collector-source-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_protected_tree_detects_content_addition_deletion_rename_directory_and_permission_mutations(): void
    {
        $service = new CollectorSourceEvidence;
        foreach (['content', 'addition', 'deletion', 'rename', 'directory', 'permissions'] as $mutation) {
            $code = $this->root.'/'.$mutation;
            mkdir($code, 0700);
            file_put_contents($code.'/version.php', 'protected-source');
            $before = $service->fingerprint($code);
            match ($mutation) {
                'content' => file_put_contents($code.'/version.php', 'mutated-source'),
                'addition' => file_put_contents($code.'/added.php', 'unapproved'),
                'deletion' => unlink($code.'/version.php'),
                'rename' => rename($code.'/version.php', $code.'/renamed.php'),
                'directory' => mkdir($code.'/added-directory', 0700),
                'permissions' => chmod($code.'/version.php', 0400),
            };
            $after = $service->fingerprint($code);
            $this->assertNotSame($before['sha256'], $after['sha256'], $mutation);
            try {
                $service->compare($before, $after);
                $this->fail('A protected mutation must fail closed: '.$mutation);
            } catch (RuntimeException $error) {
                $this->assertSame('Protected source was mutated.', $error->getMessage());
            } finally {
                if (is_file($code.'/version.php')) {
                    chmod($code.'/version.php', 0600);
                }
            }
        }
    }

    public function test_writable_source_is_rejected_even_when_before_and_after_hashes_match(): void
    {
        file_put_contents($this->root.'/version.php', 'unchanged');
        $service = new CollectorSourceEvidence;
        $measurement = $service->fingerprint($this->root);
        $this->assertFalse($measurement['write_access_denied']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Protected source is writable');
        $service->compare($measurement, $service->fingerprint($this->root));
    }

    public function test_missing_or_linked_source_fails_measurement_without_disclosing_paths(): void
    {
        file_put_contents($this->root.'/private-name.php', 'private-source');
        symlink($this->root.'/private-name.php', $this->root.'/linked.php');
        foreach ([$this->root, $this->root.'/missing'] as $code) {
            try {
                (new CollectorSourceEvidence)->fingerprint($code);
                $this->fail('Incomplete or linked source must fail closed.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString($this->root, $error->getMessage());
                $this->assertStringNotContainsString('private-name', $error->getMessage());
                $this->assertStringNotContainsString('private-source', $error->getMessage());
            }
        }
    }

    public function test_permission_denial_on_a_writable_mount_is_not_claimed_as_enforced(): void
    {
        file_put_contents($this->root.'/version.php', 'owner-can-chmod');
        chmod($this->root.'/version.php', 0400);
        $service = new CollectorSourceEvidence;
        $measurement = $service->fingerprint($this->root);
        $this->assertFalse($measurement['read_only_mount']);
        // Even an observed permission denial is insufficient on a writable mount.
        $measurement['write_access_denied'] = true;
        try {
            $service->assertProtected($measurement);
            $this->fail('chmod permissions must not be presented as enforced source immutability.');
        } catch (RuntimeException $error) {
            $this->assertSame('A read-only source mount could not be verified.', $error->getMessage());
        } finally {
            chmod($this->root.'/version.php', 0600);
        }
    }

    public function test_access_contract_distinguishes_measured_code_temporary_policy_and_unobserved_database_and_cleanup(): void
    {
        // Contract fixtures describe completed observations; the LAB integration
        // test verifies write denial against the real read-only mounted tree.
        $measurement = ['sha256' => str_repeat('a', 64), 'entries' => 3, 'write_access_denied' => true, 'read_only_mount' => true];
        $service = new CollectorSourceEvidence;
        $evidence = $service->compare($measurement, $measurement);
        $this->assertSame('PROHIBITED_AND_ENFORCED', $evidence['source_code_write']);
        $this->assertSame('VERIFIED_UNCHANGED', $evidence['code']['result']);
        $this->assertSame('TEMPORARY_ALLOWED', $evidence['source_data_write']);
        $this->assertSame('NOT_VERIFIED', $evidence['data']['result']);
        $this->assertSame('NOT_VERIFIED', $evidence['data']['cleanup']);
        $this->assertSame('NOT_VERIFIED', $evidence['source_database_mutation']);
        $this->assertSame('NOT_APPLICABLE', $evidence['destination_write']);
        $this->assertArrayNotHasKey('source_write', $evidence);
        $reordered = array_reverse($evidence, true);
        foreach (['code', 'data', 'database', 'destination'] as $field) {
            $reordered[$field] = array_reverse($reordered[$field], true);
        }
        $service->validate($reordered);
    }

    public function test_favorable_booleans_unmeasured_states_and_private_fields_are_rejected(): void
    {
        $service = new CollectorSourceEvidence;
        $measurement = ['sha256' => str_repeat('a', 64), 'entries' => 3, 'write_access_denied' => true, 'read_only_mount' => true];
        $valid = $service->compare($measurement, $measurement);
        $variants = [
            ['source_write' => false],
            [...$valid, 'source_write' => false],
            [...$valid, 'source_database_mutation' => 'VERIFIED_NONE'],
            [...$valid, 'source_data_write' => false],
            [...$valid, 'code' => [...$valid['code'], 'after_sha256' => str_repeat('b', 64)]],
            [...$valid, 'code' => [...$valid['code'], 'method' => 'UNMEASURED']],
            [...$valid, 'code' => [...$valid['code'], 'enforcement' => 'READ_ONLY_MOUNT_ASSUMED']],
            [...$valid, 'data' => [...$valid['data'], 'cleanup' => 'VERIFIED_CLEAN']],
            [...$valid, 'code' => [...$valid['code'], 'path' => '/private/institutional/source']],
            [...$valid, 'credential' => 'private-credential'],
        ];
        foreach ($variants as $variant) {
            try {
                $service->validate($variant);
                $this->fail('Unsupported source evidence must fail closed.');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString('/private', $error->getMessage());
                $this->assertStringNotContainsString('private-credential', $error->getMessage());
            }
        }
    }

    public function test_fingerprint_is_stable_and_publishes_only_aggregates(): void
    {
        mkdir($this->root.'/institutional-private-name', 0700);
        file_put_contents($this->root.'/institutional-private-name/secret.php', 'private-credential-and-content');
        $service = new CollectorSourceEvidence;
        $measurement = $service->fingerprint($this->root);
        $this->assertSame($measurement, $service->fingerprint($this->root));
        $this->assertSame(3, $measurement['entries']);
        $serialized = json_encode($measurement, JSON_THROW_ON_ERROR);
        foreach ([$this->root, 'institutional-private-name', 'secret.php', 'private-credential-and-content'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }
}
