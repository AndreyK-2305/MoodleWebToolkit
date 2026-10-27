<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Aws\AwsConnectionCheck;
use App\Domain\Aws\RemoteStorageConfiguration;
use App\Enums\AwsCredentialMode;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AwsAccount;
use App\Models\AwsStorageSettings;
use App\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The AWS identities the platform acts as and its single package bucket, for
 * administrators. Access keys and External IDs are never sent back to the
 * browser: the page only knows whether they are set (and the key id's last
 * characters). Changes are audited by field name, never by value.
 */
class AwsSettingsController extends Controller
{
    private const REGION = '/^[a-z]{2}(-gov)?-[a-z]+-[1-9]$/D';

    private const BUCKET = '/^(?!.*\.\.)(?!xn--)[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D';

    private const ROLE_ARN = '/^arn:aws(-cn|-us-gov)?:iam::\d{12}:role\/[\w+=,.@\/-]{1,512}$/D';

    public function edit(Request $request): Response
    {
        $storage = AwsStorageSettings::current();
        $region = config('toolkit.remote.default_region');
        $usage = $this->usage();

        return Inertia::render('admin/aws', [
            'accounts' => AwsAccount::query()->orderByDesc('is_default')->orderBy('name')->get()->map(fn (AwsAccount $account): array => [
                'uuid' => $account->uuid,
                'name' => $account->name,
                'mode' => $account->mode->value,
                // The key id identifies the key in IAM; its last four characters are enough to recognize it.
                'access_key_hint' => ($account->access_key_id ?? '') !== '' ? '…'.substr((string) $account->access_key_id, -4) : null,
                'secret_configured' => ($account->secret_access_key ?? '') !== '',
                'role_arn' => $account->role_arn,
                'external_id_configured' => ($account->external_id ?? '') !== '',
                'session_name' => $account->session_name,
                'is_default' => $account->is_default,
                'connections' => $usage[$account->uuid] ?? 0,
                'used_by_storage' => $storage?->aws_account_id === $account->id,
                'updated_at' => $account->updated_at?->toIso8601String(),
            ]),
            'modes' => collect(AwsCredentialMode::cases())->map(fn (AwsCredentialMode $mode) => ['value' => $mode->value, 'label' => $mode->label()]),
            'storage' => $storage === null ? null : [
                'bucket' => $storage->bucket,
                'region' => $storage->region,
                'aws_account' => $storage->account?->uuid,
                'updated_at' => $storage->updated_at?->toIso8601String(),
            ],
            // What the installation's environment provides while nothing is saved here.
            'environment' => [
                'bucket' => $storage === null && is_string(config('toolkit.remote.package_bucket')) && config('toolkit.remote.package_bucket') !== ''
                    ? config('toolkit.remote.package_bucket') : null,
                'region' => is_string($region) && $region !== '' ? $region : 'us-east-2',
            ],
            'lastCheck' => $request->session()->get('awsCheck'),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        return $this->saveAccount($request, new AwsAccount);
    }

    public function updateAccount(Request $request, AwsAccount $account): RedirectResponse
    {
        return $this->saveAccount($request, $account);
    }

    /** An account still selected by a connection or by the storage cannot be removed. */
    public function destroyAccount(Request $request, AwsAccount $account): RedirectResponse
    {
        $connections = $this->usage()[$account->uuid] ?? 0;
        if ($connections > 0 || AwsStorageSettings::query()->where('aws_account_id', $account->getKey())->exists()) {
            throw ValidationException::withMessages(['account' => $connections > 0
                ? 'La cuenta está elegida en '.$connections.' '.($connections === 1 ? 'conexión remota' : 'conexiones remotas').'. Cámbielas antes de eliminarla.'
                : 'La cuenta se usa para el almacenamiento. Elija otra antes de eliminarla.']);
        }
        DB::transaction(function () use ($request, $account): void {
            $account->delete();
            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(), 'action' => 'AWS_ACCOUNT_DELETED',
                'auditable_type' => $account->getMorphClass(), 'auditable_id' => $account->getKey(),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'payload' => ['mode' => $account->mode->value, 'was_default' => $account->is_default],
            ]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta AWS eliminada.']);

        return to_route('admin.aws.edit');
    }

    public function verifyAccount(Request $request, AwsAccount $account, AwsConnectionCheck $check): RedirectResponse
    {
        $validated = $request->validate(['region' => ['required', 'string', 'regex:'.self::REGION]],
            ['region.regex' => 'Indique una región AWS válida, por ejemplo us-east-2.']);
        $result = $check->account($account, $validated['region']);
        AuditLog::query()->create([
            'actor_id' => $request->user()->getKey(), 'action' => 'AWS_ACCOUNT_VERIFIED',
            'auditable_type' => $account->getMorphClass(), 'auditable_id' => $account->getKey(), 'ip_address' => $request->ip(),
            'payload' => ['outcome' => $result['ok'] ? 'OK' : 'FAILED', 'region' => $validated['region'], 'online_instances' => $result['online_instances']],
        ]);
        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => 'La cuenta «'.$account->name.'» respondió correctamente.']
            : ['type' => 'error', 'message' => 'La verificación de «'.$account->name.'» encontró problemas.']);

        return to_route('admin.aws.edit')->with('awsCheck', ['target' => 'account', 'account' => $account->uuid, ...$result]);
    }

    public function updateStorage(Request $request, RemoteStorageConfiguration $configuration): RedirectResponse
    {
        $request->merge(['bucket' => is_string($request->input('bucket')) ? mb_strtolower(trim($request->input('bucket'))) : $request->input('bucket')]);
        $validated = $request->validate([
            'bucket' => ['required', 'string', 'max:63', 'regex:'.self::BUCKET],
            'region' => ['required', 'string', 'max:32', 'regex:'.self::REGION],
            'aws_account' => ['nullable', 'uuid', Rule::exists('aws_accounts', 'uuid')],
        ], [
            'bucket.regex' => 'Escriba sólo el nombre del bucket (3 a 63 caracteres: minúsculas, números, puntos y guiones), sin s3:// ni rutas.',
            'region.regex' => 'Indique una región AWS válida, por ejemplo us-east-2.',
            'aws_account.exists' => 'La cuenta AWS elegida ya no existe.',
        ]);
        $accountId = isset($validated['aws_account']) ? AwsAccount::query()->where('uuid', $validated['aws_account'])->value('id') : null;

        DB::transaction(function () use ($request, $validated, $accountId): void {
            $storage = AwsStorageSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new AwsStorageSettings;
            $storage->fill(['bucket' => $validated['bucket'], 'region' => $validated['region'], 'aws_account_id' => $accountId,
                'updated_by' => $request->user()->getKey()]);
            $created = ! $storage->exists;
            $changed = array_values(array_diff(array_keys($storage->getDirty()), ['updated_by']));
            $storage->save();
            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(), 'action' => 'AWS_STORAGE_UPDATED',
                'auditable_type' => $storage->getMorphClass(), 'auditable_id' => $storage->getKey(),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                // Field names only: the bucket name never reaches the audit trail.
                'payload' => ['created' => $created, 'changed_fields' => $changed],
            ]);
        }, attempts: 3);
        $configuration->apply();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Almacenamiento guardado. Verifíquelo antes de recolectar.']);

        return to_route('admin.aws.edit');
    }

    public function verifyStorage(Request $request, AwsConnectionCheck $check): RedirectResponse
    {
        $storage = AwsStorageSettings::current();
        if ($storage === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Guarde primero el bucket para poder verificarlo.']);

            return to_route('admin.aws.edit');
        }
        $result = $check->storage($storage->bucket, $storage->region, $storage->account ?? AwsAccount::default());
        AuditLog::query()->create([
            'actor_id' => $request->user()->getKey(), 'action' => 'AWS_STORAGE_VERIFIED',
            'auditable_type' => $storage->getMorphClass(), 'auditable_id' => $storage->getKey(), 'ip_address' => $request->ip(),
            'payload' => ['outcome' => $result['ok'] ? 'OK' : 'FAILED', 'failed' => collect($result['checks'])->reject(fn (array $c) => $c['ok'])->pluck('label')->values()->all()],
        ]);
        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => 'El almacenamiento respondió correctamente.']
            : ['type' => 'error', 'message' => 'La verificación del almacenamiento encontró problemas.']);

        return to_route('admin.aws.edit')->with('awsCheck', ['target' => 'storage', ...$result]);
    }

    private function saveAccount(Request $request, AwsAccount $account): RedirectResponse
    {
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $storedKeys = ($account->secret_access_key ?? '') !== '' && $account->mode === AwsCredentialMode::ACCESS_KEYS;
        $storedExternalId = ($account->external_id ?? '') !== '';
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('aws_accounts', 'name')->ignore($account->getKey())],
            'mode' => ['required', Rule::enum(AwsCredentialMode::class)],
            'keys_action' => ['exclude_unless:mode,ACCESS_KEYS', 'required', Rule::in($storedKeys ? ['KEEP', 'REPLACE'] : ['REPLACE'])],
            'access_key_id' => ['exclude_unless:keys_action,REPLACE', 'exclude_unless:mode,ACCESS_KEYS', 'required', 'string', 'regex:/^AKIA[A-Z0-9]{16}$/D'],
            'secret_access_key' => ['exclude_unless:keys_action,REPLACE', 'exclude_unless:mode,ACCESS_KEYS', 'required', 'string', 'regex:/^[A-Za-z0-9\/+]{40}$/D'],
            'role_arn' => ['exclude_unless:mode,ASSUME_ROLE', 'required', 'string', 'max:2048', 'regex:'.self::ROLE_ARN],
            'external_id_action' => ['exclude_unless:mode,ASSUME_ROLE', 'required', Rule::in($storedExternalId ? ['KEEP', 'REPLACE', 'CLEAR'] : ['REPLACE', 'CLEAR'])],
            'external_id' => ['exclude_unless:external_id_action,REPLACE', 'exclude_unless:mode,ASSUME_ROLE', 'required', 'string', 'min:2', 'max:1224', 'regex:/^[\w+=,.@:\/-]+$/D'],
            'session_name' => ['exclude_unless:mode,ASSUME_ROLE', 'nullable', 'string', 'min:2', 'max:64', 'regex:/^[\w+=,.@-]+$/D'],
            'is_default' => ['required', 'boolean'],
        ], [
            'name.unique' => 'Ya existe una cuenta con ese nombre.',
            'keys_action.in' => 'Escriba las claves de acceso de esta cuenta.',
            'access_key_id.regex' => 'El ID de la clave de acceso empieza por AKIA y tiene 20 caracteres.',
            'access_key_id.required' => 'Escriba el ID de la clave de acceso.',
            'secret_access_key.regex' => 'La clave secreta tiene 40 caracteres.',
            'secret_access_key.required' => 'Escriba la clave secreta.',
            'role_arn.regex' => 'Escriba el ARN completo del rol, por ejemplo arn:aws:iam::123456789012:role/MoodleWebToolkit.',
            'external_id.required' => 'Escriba el External ID o elija no usarlo.',
            'external_id.regex' => 'El External ID admite letras, números y los signos + = , . @ : / -.',
            'session_name.regex' => 'El nombre de sesión admite letras, números y los signos + = , . @ -.',
        ]);
        $mode = AwsCredentialMode::from($validated['mode']);

        $keysAction = $mode === AwsCredentialMode::ACCESS_KEYS ? $validated['keys_action'] : 'CLEAR';
        $externalAction = $mode === AwsCredentialMode::ASSUME_ROLE ? $validated['external_id_action'] : 'CLEAR';

        DB::transaction(function () use ($request, $account, $validated, $mode, $keysAction, $externalAction): void {
            $first = ! AwsAccount::query()->lockForUpdate()->exists();
            $default = (bool) $validated['is_default'] || $first;
            if ($default) {
                AwsAccount::query()->where('is_default', true)->whereKeyNot($account->getKey() ?? 0)->update(['is_default' => false]);
            }
            $account->fill([
                'name' => $validated['name'],
                'mode' => $mode,
                'role_arn' => $mode === AwsCredentialMode::ASSUME_ROLE ? $validated['role_arn'] : null,
                'session_name' => $mode === AwsCredentialMode::ASSUME_ROLE && ($validated['session_name'] ?? '') !== '' ? $validated['session_name'] : null,
                'is_default' => $default,
                'updated_by' => $request->user()->getKey(),
            ]);
            if ($keysAction === 'REPLACE') {
                $account->access_key_id = (string) $request->input('access_key_id');
                $account->secret_access_key = (string) $request->input('secret_access_key');
            } elseif ($keysAction === 'CLEAR') {
                $account->access_key_id = null;
                $account->secret_access_key = null;
            }
            if ($externalAction === 'REPLACE') {
                $account->external_id = (string) $request->input('external_id');
            } elseif ($externalAction === 'CLEAR') {
                $account->external_id = null;
            }
            $created = ! $account->exists;
            $changed = array_values(array_diff(array_keys($account->getDirty()), ['updated_by', 'access_key_id', 'secret_access_key', 'external_id']));
            $account->save();
            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(), 'action' => $created ? 'AWS_ACCOUNT_CREATED' : 'AWS_ACCOUNT_UPDATED',
                'auditable_type' => $account->getMorphClass(), 'auditable_id' => $account->getKey(),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                // Field names and what happened to each secret; never a value.
                'payload' => ['created' => $created, 'mode' => $mode->value, 'changed_fields' => $changed,
                    'access_keys' => $this->secretOutcome($keysAction), 'external_id' => $this->secretOutcome($externalAction), 'is_default' => $account->is_default],
            ]);
        }, attempts: 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cuenta AWS guardada. Verifíquela para confirmar los permisos.']);

        return to_route('admin.aws.edit');
    }

    private function secretOutcome(string $action): string
    {
        return match ($action) {
            'REPLACE' => 'REPLACED', 'CLEAR' => 'CLEARED', default => 'KEPT',
        };
    }

    /**
     * How many SSM connections select each account.
     *
     * @return array<string, int>
     */
    private function usage(): array
    {
        $usage = [];
        foreach (Connection::query()->whereNotNull('configuration->aws_account')->get(['configuration']) as $connection) {
            $uuid = $connection->configuration['aws_account'] ?? null;
            if (is_string($uuid)) {
                $usage[$uuid] = ($usage[$uuid] ?? 0) + 1;
            }
        }

        return $usage;
    }
}
