<?php

namespace Tests\Feature\Aws;

use App\Domain\Aws\AwsConnectionCheck;
use App\Domain\Aws\StsClientFactory;
use App\Domain\Remote\S3ClientFactory;
use App\Domain\Remote\SsmClientFactory;
use App\Enums\AwsCredentialMode;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\AwsAccount;
use App\Models\AwsStorageSettings;
use App\Models\User;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\Ssm\Exception\SsmException;
use Aws\Ssm\SsmClient;
use Aws\Sts\StsClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class AwsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_ID = 'AKIAIOSFODNN7EXAMPLE';

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private const EXTERNAL_ID = 'external-id-never-shown-42';

    public function test_only_admins_reach_the_aws_settings(): void
    {
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);

        $this->actingAs($operator)->get(route('admin.aws.edit'))->assertForbidden();
        $this->actingAs($operator)->post(route('admin.aws.accounts.store'), $this->keys())->assertForbidden();
        $this->actingAs($operator)->put(route('admin.aws.storage.update'), $this->storage())->assertForbidden();
        $this->actingAs($operator)->post(route('admin.aws.storage.verify'))->assertForbidden();
        $this->assertDatabaseCount('aws_accounts', 0);
        $this->actingAs($this->admin())->get(route('admin.aws.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/aws')
                ->where('accounts', [])->where('storage', null)->has('modes', 3));
    }

    public function test_changes_need_a_confirmed_password(): void
    {
        $this->be($this->admin());

        $this->post(route('admin.aws.accounts.store'), $this->keys())->assertRedirect();
        $this->put(route('admin.aws.storage.update'), $this->storage())->assertRedirect();
        $this->assertDatabaseCount('aws_accounts', 0);
        $this->assertDatabaseCount('aws_storage_settings', 0);
    }

    public function test_accounts_and_storage_are_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->keys(['access_key_id' => 'not-a-key', 'secret_access_key' => 'short', 'mode' => 'ACCESS_KEYS']))
            ->assertSessionHasErrors(['access_key_id', 'secret_access_key']);
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->role(['role_arn' => 'arn:aws:iam::123:user/alguien', 'session_name' => 'con espacios']))
            ->assertSessionHasErrors(['role_arn', 'session_name']);
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->role(['external_id_action' => 'REPLACE', 'external_id' => '']))
            ->assertSessionHasErrors('external_id');
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), ['name' => '', 'mode' => 'MAGIC', 'is_default' => true])
            ->assertSessionHasErrors(['name', 'mode']);
        $this->assertDatabaseCount('aws_accounts', 0);

        $this->actingAs($admin)->put(route('admin.aws.storage.update'), ['bucket' => 's3://Mi_Bucket/ruta', 'region' => 'Ohio', 'aws_account' => '00000000-0000-4000-8000-000000000000'])
            ->assertSessionHasErrors(['bucket', 'region', 'aws_account']);
        $this->assertDatabaseCount('aws_storage_settings', 0);

        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->role())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->keys(['name' => 'Institución']))->assertSessionHasErrors('name');
    }

    public function test_secrets_are_encrypted_never_returned_and_only_kept_or_replaced(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->keys())->assertSessionHasNoErrors()->assertRedirect(route('admin.aws.edit'));
        $account = AwsAccount::query()->sole();
        $raw = (array) DB::table('aws_accounts')->first();
        foreach ([self::KEY_ID, self::SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, (string) json_encode($raw));
            $this->assertStringNotContainsString($secret, $account->toJson());
        }
        $this->assertSame(self::SECRET, $account->secret_access_key);
        $this->assertTrue($account->is_default, 'The first account becomes the default.');

        $page = $this->actingAs($admin)->get(route('admin.aws.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.0.access_key_hint', '…MPLE')
                ->where('accounts.0.secret_configured', true)->missing('accounts.0.secret_access_key')->missing('accounts.0.access_key_id'));
        foreach ([self::KEY_ID, self::SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $page->getContent());
        }

        // Keeping the keys ignores values sent along.
        $this->actingAs($admin)->put(route('admin.aws.accounts.update', $account->uuid), $this->keys(['keys_action' => 'KEEP', 'secret_access_key' => 'ignored', 'name' => 'Renombrada']))
            ->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, $account->refresh()->secret_access_key);
        $this->assertSame('Renombrada', $account->name);

        // Switching to an assumed role drops the stored keys.
        $this->actingAs($admin)->put(route('admin.aws.accounts.update', $account->uuid), $this->role(['name' => 'Renombrada']))->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertNull($account->secret_access_key);
        $this->assertNull($account->access_key_id);
        $this->assertSame(self::EXTERNAL_ID, $account->external_id);
        $page = $this->actingAs($admin)->get(route('admin.aws.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('accounts.0.external_id_configured', true)->missing('accounts.0.external_id'));
        $this->assertStringNotContainsString(self::EXTERNAL_ID, (string) $page->getContent());

        $payloads = AuditLog::query()->whereIn('action', ['AWS_ACCOUNT_CREATED', 'AWS_ACCOUNT_UPDATED'])->orderBy('id')->pluck('payload')->all();
        $this->assertSame(['REPLACED', 'KEPT', 'CLEARED'], array_column($payloads, 'access_keys'));
        $this->assertSame(['CLEARED', 'CLEARED', 'REPLACED'], array_column($payloads, 'external_id'));
        $encoded = (string) json_encode($payloads);
        foreach ([self::KEY_ID, self::SECRET, self::EXTERNAL_ID, 'Renombrada', '123456789012'] as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_one_default_account_and_accounts_in_use_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->role())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->keys(['is_default' => true]))->assertSessionHasNoErrors();
        $this->assertSame(['Claves'], AwsAccount::query()->where('is_default', true)->pluck('name')->all());

        $institution = AwsAccount::query()->where('name', 'Institución')->sole();
        $this->actingAs($admin)->put(route('admin.aws.storage.update'), $this->storage(['aws_account' => $institution->uuid]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete(route('admin.aws.accounts.destroy', $institution->uuid))->assertSessionHasErrors('account');
        $this->assertModelExists($institution);

        $keys = AwsAccount::query()->where('name', 'Claves')->sole();
        $this->actingAs($admin)->delete(route('admin.aws.accounts.destroy', $keys->uuid))->assertSessionHasNoErrors();
        $this->assertModelMissing($keys);
        $this->assertEquals(['mode' => 'ACCESS_KEYS', 'was_default' => true], AuditLog::query()->where('action', 'AWS_ACCOUNT_DELETED')->sole()->payload);
    }

    public function test_storage_is_saved_audited_without_values_and_overrides_the_configuration(): void
    {
        config(['toolkit.remote.package_bucket' => 'bucket-del-entorno', 'toolkit.remote.package_region' => 'us-east-2']);
        $this->actingAs($this->admin())->get(route('admin.aws.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('environment.bucket', 'bucket-del-entorno'));

        $this->actingAs($this->admin())->put(route('admin.aws.storage.update'), $this->storage(['bucket' => ' Paquetes-Institucion ']))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.aws.edit'));

        $this->assertSame('paquetes-institucion', AwsStorageSettings::current()?->bucket);
        $this->assertSame('paquetes-institucion', config('toolkit.remote.package_bucket'));
        $this->assertSame('sa-east-1', config('toolkit.remote.package_region'));
        $audit = AuditLog::query()->where('action', 'AWS_STORAGE_UPDATED')->sole();
        $this->assertEqualsCanonicalizing(['bucket', 'region', 'aws_account_id'], $audit->payload['changed_fields']);
        $this->assertStringNotContainsString('paquetes-institucion', (string) json_encode($audit->payload));
    }

    public function test_account_verification_reports_identity_and_online_instances(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.aws.accounts.store'), $this->role())->assertSessionHasNoErrors();
        $account = AwsAccount::query()->sole();
        $ssm = new MockHandler([
            new Result(['InstanceInformationList' => [['InstanceId' => 'i-1'], ['InstanceId' => 'i-2']], 'NextToken' => 'next']),
            new Result(['InstanceInformationList' => [['InstanceId' => 'i-3']]]),
        ]);
        $this->fakeClients(sts: new MockHandler([new Result(['Account' => '123456789012', 'Arn' => 'arn:aws:sts::123456789012:assumed-role/MoodleWebToolkit/MoodleWebToolkit'])]), ssm: $ssm);

        $this->actingAs($admin)->post(route('admin.aws.accounts.verify', $account->uuid), ['region' => 'us-east-2'])
            ->assertRedirect(route('admin.aws.edit'))->assertInertiaFlash('toast.type', 'success');
        $check = session('awsCheck');
        $this->assertTrue($check['ok']);
        $this->assertSame(3, $check['online_instances']);
        $this->assertSame(['account' => '123456789012', 'arn' => 'arn:aws:sts::123456789012:assumed-role/MoodleWebToolkit/MoodleWebToolkit'], $check['identity']);
        $this->assertStringContainsString('Hay 3 instancias en línea en us-east-2', $check['checks'][1]['message']);
        $this->assertStringNotContainsString(self::EXTERNAL_ID, (string) json_encode($check));
        $this->assertEquals(['outcome' => 'OK', 'region' => 'us-east-2', 'online_instances' => 3], AuditLog::query()->where('action', 'AWS_ACCOUNT_VERIFIED')->sole()->payload);

        $this->actingAs($admin)->post(route('admin.aws.accounts.verify', $account->uuid), ['region' => 'Ohio'])->assertSessionHasErrors('region');
    }

    public function test_account_verification_explains_a_missing_permission(): void
    {
        $account = AwsAccount::query()->create(['name' => 'Entorno', 'mode' => AwsCredentialMode::ENVIRONMENT, 'is_default' => true]);
        $this->fakeClients(
            sts: new MockHandler([new Result(['Account' => '123456789012', 'Arn' => 'arn:aws:sts::123456789012:assumed-role/plataforma/i-0abc'])]),
            ssm: new MockHandler([fn (CommandInterface $command) => new SsmException('denied', $command, ['code' => 'AccessDeniedException', 'response' => new Response(400)])]),
        );

        $result = app(AwsConnectionCheck::class)->account($account, 'us-east-2');

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['checks'][0]['ok']);
        $this->assertFalse($result['checks'][1]['ok']);
        $this->assertSame('La identidad no tiene permiso para ssm:DescribeInstanceInformation. Revise la política IAM sugerida en esta página.', $result['checks'][1]['message']);
    }

    public function test_storage_verification_writes_reads_and_removes_a_test_object(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.aws.storage.update'), $this->storage())->assertSessionHasNoErrors();
        $commands = [];
        $this->fakeClients(s3: $this->recording(new MockHandler([new Result([]), new Result([]), new Result(['ContentLength' => 10]), new Result([])]), $commands));

        $this->actingAs($admin)->post(route('admin.aws.storage.verify'))->assertRedirect(route('admin.aws.edit'))->assertInertiaFlash('toast.type', 'success');

        $this->assertTrue(session('awsCheck')['ok']);
        $this->assertSame(['HeadBucket', 'PutObject', 'HeadObject', 'DeleteObject'], array_column($commands, 0));
        $key = (string) $commands[1][1];
        $this->assertStringStartsWith(AwsConnectionCheck::TEST_PREFIX, $key);
        $this->assertSame([$key, $key], [$commands[2][1], $commands[3][1]]);
        $this->assertEquals(['outcome' => 'OK', 'failed' => []], AuditLog::query()->where('action', 'AWS_STORAGE_VERIFIED')->sole()->payload);
    }

    public function test_storage_verification_reports_what_failed_and_still_removes_the_object(): void
    {
        $denied = fn (CommandInterface $command) => new S3Exception('Forbidden', $command, ['response' => new Response(403)]);
        $commands = [];
        $this->fakeClients(s3: $this->recording(new MockHandler([new Result([]), new Result([]), $denied, new Result([])]), $commands));

        $result = app(AwsConnectionCheck::class)->storage('paquetes', 'us-east-2', null);

        $this->assertFalse($result['ok']);
        $this->assertSame(['HeadBucket', 'PutObject', 'HeadObject', 'DeleteObject'], array_column($commands, 0));
        $this->assertSame([true, true, false, true], array_column($result['checks'], 'ok'));
        $this->assertSame('La identidad no tiene permiso para s3:GetObject. Revise la política IAM sugerida en esta página.', $result['checks'][2]['message']);

        $this->fakeClients(s3: new MockHandler([fn (CommandInterface $command) => new S3Exception('Not Found', $command, ['response' => new Response(404)])]));
        $missing = app(AwsConnectionCheck::class)->storage('no-existe', 'us-east-2', null);
        $this->assertCount(1, $missing['checks']);
        $this->assertSame('El bucket no existe o el nombre está mal escrito.', $missing['checks'][0]['message']);
    }

    /**
     * @param  list<array{0: string, 1: mixed}>  $commands
     */
    private function recording(MockHandler $handler, array &$commands): callable
    {
        return function (CommandInterface $command, RequestInterface $request) use ($handler, &$commands) {
            $commands[] = [$command->getName(), $command['Key'] ?? null];

            return $handler($command, $request);
        };
    }

    private function fakeClients(?callable $sts = null, ?callable $ssm = null, ?callable $s3 = null): void
    {
        $options = fn (?callable $handler): array => ['region' => 'us-east-2', 'credentials' => ['key' => 'test', 'secret' => 'test'], 'handler' => $handler ?? new MockHandler];
        $this->app->instance(StsClientFactory::class, new class(new StsClient(['version' => '2011-06-15', ...$options($sts)])) extends StsClientFactory
        {
            public function __construct(private readonly StsClient $client) {}

            public function forAccount(string $region, ?AwsAccount $account): StsClient
            {
                return $this->client;
            }
        });
        $this->app->instance(SsmClientFactory::class, new class(new SsmClient(['version' => '2014-11-06', ...$options($ssm)])) extends SsmClientFactory
        {
            public function __construct(private readonly SsmClient $client) {}

            public function forAccount(string $region, ?AwsAccount $account): SsmClient
            {
                return $this->client;
            }
        });
        $this->app->instance(S3ClientFactory::class, new class(new S3Client(['version' => '2006-03-01', ...$options($s3)])) extends S3ClientFactory
        {
            public function __construct(private readonly S3Client $client) {}

            public function forAccount(string $region, ?AwsAccount $account): S3Client
            {
                return $this->client;
            }
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function keys(array $overrides = []): array
    {
        return ['name' => 'Claves', 'mode' => 'ACCESS_KEYS', 'keys_action' => 'REPLACE', 'access_key_id' => self::KEY_ID,
            'secret_access_key' => self::SECRET, 'is_default' => false, ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function role(array $overrides = []): array
    {
        return ['name' => 'Institución', 'mode' => 'ASSUME_ROLE', 'role_arn' => 'arn:aws:iam::123456789012:role/MoodleWebToolkit',
            'external_id_action' => 'REPLACE', 'external_id' => self::EXTERNAL_ID, 'session_name' => '', 'is_default' => false, ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storage(array $overrides = []): array
    {
        return ['bucket' => 'paquetes-institucion', 'region' => 'sa-east-1', 'aws_account' => null, ...$overrides];
    }
}
