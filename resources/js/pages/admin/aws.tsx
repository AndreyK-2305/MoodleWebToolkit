import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Cloud,
    Copy,
    Database,
    KeyRound,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { edit } from '@/routes/admin/aws';
import {
    destroy as destroyAccount,
    store as storeAccount,
    update as updateAccount,
    verify as verifyAccount,
} from '@/routes/admin/aws/accounts';
import {
    update as updateStorage,
    verify as verifyStorage,
} from '@/routes/admin/aws/storage';

type Mode = 'ENVIRONMENT' | 'ACCESS_KEYS' | 'ASSUME_ROLE';
type Account = {
    uuid: string;
    name: string;
    mode: Mode;
    access_key_hint: string | null;
    secret_configured: boolean;
    role_arn: string | null;
    external_id_configured: boolean;
    session_name: string | null;
    is_default: boolean;
    connections: number;
    used_by_storage: boolean;
    updated_at: string | null;
};
type Storage = {
    bucket: string;
    region: string;
    aws_account: string | null;
    updated_at: string | null;
};
type Check = { label: string; ok: boolean; message: string };
type LastCheck =
    | {
          target: 'account';
          account: string;
          ok: boolean;
          identity: { account: string; arn: string } | null;
          online_instances: number | null;
          region: string;
          checks: Check[];
      }
    | { target: 'storage'; ok: boolean; checks: Check[] };
type Props = {
    accounts: Account[];
    modes: { value: Mode; label: string }[];
    storage: Storage | null;
    environment: { bucket: string | null; region: string };
    lastCheck: LastCheck | null;
};

const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';

const modeHelp: Record<Mode, string> = {
    ENVIRONMENT:
        'Usa la identidad del servidor donde corre la plataforma: el rol IAM de la instancia EC2 o de la tarea ECS, o un perfil de AWS montado. No guarda ninguna clave.',
    ACCESS_KEYS:
        'Usa las claves de un usuario IAM. Se guardan cifradas y nunca vuelven al navegador. Prefiera un rol cuando la plataforma corra en AWS.',
    ASSUME_ROLE:
        'La plataforma asume un rol de la cuenta de la institución con STS, partiendo de la identidad del entorno. Es el modo recomendado: la institución controla los permisos y puede revocarlos.',
};

/**
 * The principal for the trust policy, from the last check of an environment
 * account: an instance role reports its STS session, the trust policy wants the role.
 */
function trustPrincipal(
    check: { account: string; identity: { arn: string } | null } | null,
    accounts: Account[],
): string | null {
    const account = accounts.find((a) => a.uuid === check?.account);
    const arn = check?.identity?.arn;
    if (account?.mode !== 'ENVIRONMENT' || !arn) {
        return null;
    }
    const session = arn.match(
        /^arn:(aws[\w-]*):sts::(\d{12}):assumed-role\/([^/]+)\//,
    );

    return session
        ? `arn:${session[1]}:iam::${session[2]}:role/${session[3]}`
        : arn;
}

export default function AwsSettingsPage({
    accounts,
    modes,
    storage,
    environment,
    lastCheck,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [editing, setEditing] = useState<Account | 'new' | null>(
        accounts.length === 0 ? 'new' : null,
    );
    const [region, setRegion] = useState(environment.region);
    const [verifying, setVerifying] = useState<string | null>(null);
    const storageForm = useForm({
        bucket: storage?.bucket ?? environment.bucket ?? '',
        region: storage?.region ?? environment.region,
        aws_account: storage?.aws_account ?? '',
    });
    const storageCheck = useForm({});
    const accountCheck = lastCheck?.target === 'account' ? lastCheck : null;
    const bucket = storage?.bucket ?? environment.bucket ?? 'NOMBRE-DEL-BUCKET';

    const verify = (account: Account) => {
        setVerifying(account.uuid);
        router.post(
            verifyAccount.url({ account: account.uuid }),
            { region },
            {
                preserveScroll: true,
                onFinish: () => setVerifying(null),
            },
        );
    };

    const remove = (account: Account) => {
        if (
            !window.confirm(
                `¿Eliminar la cuenta «${account.name}»? Esta acción no se puede deshacer.`,
            )
        ) {
            return;
        }
        router.delete(destroyAccount.url({ account: account.uuid }), {
            preserveScroll: true,
        });
    };

    const saveStorage = (event: React.FormEvent) => {
        event.preventDefault();
        storageForm.transform((data) => ({
            ...data,
            aws_account: data.aws_account === '' ? null : data.aws_account,
        }));
        storageForm.put(updateStorage.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="AWS" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">AWS</h1>
                    <p className="text-muted-foreground text-sm">
                        La identidad de AWS con la que la plataforma envía
                        órdenes a los servidores por Systems Manager y verifica
                        los paquetes, y el bucket de S3 por el que viajan. Las
                        claves se guardan cifradas aquí y nunca viajan a los
                        servidores de los proyectos.
                    </p>
                </div>

                {accounts.length === 0 && (
                    <Alert>
                        <Cloud className="size-4" />
                        <AlertTitle>Sin cuentas propias</AlertTitle>
                        <AlertDescription>
                            Mientras no agregue una cuenta, la plataforma usa la
                            identidad del entorno donde está instalada (el rol
                            de la instancia o un perfil de AWS montado).
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <KeyRound className="size-5" /> Credenciales
                        </CardTitle>
                        <CardDescription>
                            Puede registrar varias cuentas, por ejemplo una por
                            institución. Cada conexión SSM de «Conexiones
                            remotas» puede elegir la suya; si no elige ninguna
                            se usa la predeterminada. Guardar o eliminar pide
                            confirmar su contraseña y queda en la auditoría, sin
                            los valores.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {errors.account && (
                            <Alert variant="destructive">
                                <XCircle className="size-4" />
                                <AlertTitle>No se eliminó la cuenta</AlertTitle>
                                <AlertDescription>
                                    {errors.account}
                                </AlertDescription>
                            </Alert>
                        )}
                        {accounts.length > 0 && (
                            <div className="flex flex-wrap items-end gap-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="verify_region">
                                        Región para verificar
                                    </Label>
                                    <Input
                                        id="verify_region"
                                        className="w-40"
                                        value={region}
                                        onChange={(event) =>
                                            setRegion(event.target.value)
                                        }
                                    />
                                </div>
                                <p className="text-muted-foreground pb-2 text-xs">
                                    «Verificar» cuenta las instancias SSM en
                                    línea en esta región.
                                </p>
                                <InputError message={errors.region} />
                            </div>
                        )}
                        {accounts.map((account) => (
                            <div
                                key={account.uuid}
                                className="space-y-3 rounded-md border p-3"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2 font-medium">
                                            {account.name}
                                            <Badge variant="outline">
                                                {modes.find(
                                                    (mode) =>
                                                        mode.value ===
                                                        account.mode,
                                                )?.label ?? account.mode}
                                            </Badge>
                                            {account.is_default && (
                                                <Badge>Predeterminada</Badge>
                                            )}
                                            {account.used_by_storage && (
                                                <Badge variant="secondary">
                                                    Almacenamiento
                                                </Badge>
                                            )}
                                            {account.connections > 0 && (
                                                <Badge variant="secondary">
                                                    {account.connections}{' '}
                                                    {account.connections === 1
                                                        ? 'conexión'
                                                        : 'conexiones'}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground text-xs break-all">
                                            {account.mode === 'ACCESS_KEYS' &&
                                                `Clave de acceso ${account.access_key_hint ?? 'sin configurar'}`}
                                            {account.mode === 'ASSUME_ROLE' &&
                                                `${account.role_arn ?? ''}${account.external_id_configured ? ' · con External ID' : ''}`}
                                            {account.mode === 'ENVIRONMENT' &&
                                                'Identidad del entorno de la plataforma'}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={verifying !== null}
                                            onClick={() => verify(account)}
                                        >
                                            {verifying === account.uuid ? (
                                                <Spinner />
                                            ) : (
                                                <ShieldCheck />
                                            )}
                                            Verificar
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(account)}
                                        >
                                            <Pencil /> Editar
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            aria-label={`Eliminar ${account.name}`}
                                            onClick={() => remove(account)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                                {accountCheck?.account === account.uuid && (
                                    <CheckResult
                                        ok={accountCheck.ok}
                                        title={
                                            accountCheck.ok
                                                ? accountCheck.online_instances ===
                                                  0
                                                    ? 'La identidad responde, pero no ve instancias en línea'
                                                    : 'La cuenta funciona'
                                                : 'La verificación encontró problemas'
                                        }
                                        checks={accountCheck.checks}
                                    />
                                )}
                            </div>
                        ))}
                        {editing === null ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing('new')}
                            >
                                <Plus /> Agregar cuenta
                            </Button>
                        ) : (
                            <AccountForm
                                key={editing === 'new' ? 'new' : editing.uuid}
                                account={editing === 'new' ? null : editing}
                                modes={modes}
                                first={accounts.length === 0}
                                onDone={() => setEditing(null)}
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Database className="size-5" /> Almacenamiento
                        </CardTitle>
                        <CardDescription>
                            Un único bucket para toda la plataforma. Los
                            orígenes suben allí los paquetes sellados con el rol
                            de su instancia, los destinos los descargan con el
                            suyo y la plataforma los verifica con la cuenta
                            elegida aquí.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {storage === null && (
                            <p className="text-muted-foreground text-sm">
                                {environment.bucket === null
                                    ? 'Todavía no hay un bucket configurado: las recolecciones remotas no pueden publicar paquetes hasta que lo guarde.'
                                    : `Mientras no guarde un bucket aquí, la plataforma usa el de la instalación (${environment.bucket}).`}
                            </p>
                        )}
                        <form
                            onSubmit={saveStorage}
                            className="grid gap-4 md:grid-cols-3"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="bucket">Bucket</Label>
                                <Input
                                    id="bucket"
                                    value={storageForm.data.bucket}
                                    onChange={(event) =>
                                        storageForm.setData(
                                            'bucket',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="mwt-paquetes-institucion"
                                    required
                                />
                                <InputError
                                    message={storageForm.errors.bucket}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="storage_region">Región</Label>
                                <Input
                                    id="storage_region"
                                    value={storageForm.data.region}
                                    onChange={(event) =>
                                        storageForm.setData(
                                            'region',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    message={storageForm.errors.region}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="storage_account">
                                    Cuenta AWS
                                </Label>
                                <select
                                    id="storage_account"
                                    className={selectClass}
                                    value={storageForm.data.aws_account}
                                    onChange={(event) =>
                                        storageForm.setData(
                                            'aws_account',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">
                                        La cuenta predeterminada
                                    </option>
                                    {accounts.map((account) => (
                                        <option
                                            key={account.uuid}
                                            value={account.uuid}
                                        >
                                            {account.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={storageForm.errors.aws_account}
                                />
                            </div>
                            <div className="flex flex-wrap gap-2 md:col-span-3">
                                <Button disabled={storageForm.processing}>
                                    {storageForm.processing && <Spinner />}
                                    Guardar almacenamiento
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        storage === null ||
                                        storageCheck.processing
                                    }
                                    onClick={() =>
                                        storageCheck.post(verifyStorage.url(), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {storageCheck.processing ? (
                                        <Spinner />
                                    ) : (
                                        <ShieldCheck />
                                    )}
                                    Verificar
                                </Button>
                            </div>
                        </form>
                        {lastCheck?.target === 'storage' && (
                            <CheckResult
                                ok={lastCheck.ok}
                                title={
                                    lastCheck.ok
                                        ? 'La plataforma puede usar el bucket'
                                        : 'La verificación del bucket encontró problemas'
                                }
                                checks={lastCheck.checks}
                            />
                        )}
                        <p className="text-muted-foreground text-xs">
                            «Verificar» comprueba el bucket y escribe, lee y
                            borra un objeto pequeño bajo{' '}
                            <code>mwt/_pruebas/</code> con la identidad de la
                            plataforma.
                        </p>
                    </CardContent>
                </Card>

                <PolicyHelp
                    bucket={bucket}
                    identity={trustPrincipal(accountCheck, accounts)}
                />
            </div>
        </>
    );
}

function AccountForm({
    account,
    modes,
    first,
    onDone,
}: {
    account: Account | null;
    modes: { value: Mode; label: string }[];
    first: boolean;
    onDone: () => void;
}) {
    const hasKeys =
        account?.mode === 'ACCESS_KEYS' && account.secret_configured;
    const form = useForm({
        name: account?.name ?? '',
        mode: account?.mode ?? ('ASSUME_ROLE' as Mode),
        keys_action: hasKeys ? 'KEEP' : 'REPLACE',
        access_key_id: '',
        secret_access_key: '',
        role_arn: account?.role_arn ?? '',
        external_id_action: account?.external_id_configured
            ? 'KEEP'
            : 'REPLACE',
        external_id: '',
        session_name: account?.session_name ?? '',
        is_default: account?.is_default ?? first,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.setData((data) => ({
                    ...data,
                    access_key_id: '',
                    secret_access_key: '',
                    external_id: '',
                }));
                onDone();
            },
        };
        if (account === null) {
            form.post(storeAccount.url(), options);
        } else {
            form.put(updateAccount.url({ account: account.uuid }), options);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-4 rounded-md border border-dashed p-4 md:grid-cols-2"
        >
            <p className="font-medium md:col-span-2">
                {account === null ? 'Nueva cuenta' : `Editar «${account.name}»`}
            </p>
            <div className="grid gap-2">
                <Label htmlFor="account_name">Nombre</Label>
                <Input
                    id="account_name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    placeholder="Universidad, cuenta de producción"
                    required
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="account_mode">Modo</Label>
                <select
                    id="account_mode"
                    className={selectClass}
                    value={form.data.mode}
                    onChange={(event) =>
                        form.setData('mode', event.target.value as Mode)
                    }
                >
                    {modes.map((mode) => (
                        <option key={mode.value} value={mode.value}>
                            {mode.label}
                            {mode.value === 'ASSUME_ROLE'
                                ? ' (recomendado)'
                                : ''}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.mode} />
            </div>
            <p className="text-muted-foreground text-sm md:col-span-2">
                {modeHelp[form.data.mode]}
            </p>

            {form.data.mode === 'ACCESS_KEYS' && (
                <>
                    {hasKeys && (
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="keys_action">
                                Claves de acceso{' '}
                                <Badge variant="outline">
                                    {account?.access_key_hint}
                                </Badge>
                            </Label>
                            <select
                                id="keys_action"
                                className={selectClass}
                                value={form.data.keys_action}
                                onChange={(event) =>
                                    form.setData(
                                        'keys_action',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="KEEP">
                                    Conservar las claves actuales
                                </option>
                                <option value="REPLACE">Reemplazarlas</option>
                            </select>
                        </div>
                    )}
                    {form.data.keys_action === 'REPLACE' && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="access_key_id">
                                    ID de la clave de acceso
                                </Label>
                                <Input
                                    id="access_key_id"
                                    value={form.data.access_key_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'access_key_id',
                                            event.target.value.trim(),
                                        )
                                    }
                                    autoComplete="off"
                                    placeholder="AKIA…"
                                />
                                <InputError
                                    message={
                                        form.errors.access_key_id ??
                                        form.errors.keys_action
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="secret_access_key">
                                    Clave secreta
                                </Label>
                                <PasswordInput
                                    id="secret_access_key"
                                    value={form.data.secret_access_key}
                                    onChange={(event) =>
                                        form.setData(
                                            'secret_access_key',
                                            event.target.value.trim(),
                                        )
                                    }
                                    autoComplete="new-password"
                                />
                                <InputError
                                    message={form.errors.secret_access_key}
                                />
                            </div>
                        </>
                    )}
                </>
            )}

            {form.data.mode === 'ASSUME_ROLE' && (
                <>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="role_arn">ARN del rol</Label>
                        <Input
                            id="role_arn"
                            value={form.data.role_arn}
                            onChange={(event) =>
                                form.setData('role_arn', event.target.value)
                            }
                            placeholder="arn:aws:iam::123456789012:role/MoodleWebToolkit"
                        />
                        <InputError message={form.errors.role_arn} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="external_id_action">
                            External ID{' '}
                            {account?.external_id_configured ? (
                                <Badge variant="outline">configurado</Badge>
                            ) : (
                                <Badge variant="secondary">opcional</Badge>
                            )}
                        </Label>
                        <select
                            id="external_id_action"
                            className={selectClass}
                            value={form.data.external_id_action}
                            onChange={(event) =>
                                form.setData(
                                    'external_id_action',
                                    event.target.value,
                                )
                            }
                        >
                            {account?.external_id_configured && (
                                <option value="KEEP">
                                    Conservar el External ID actual
                                </option>
                            )}
                            <option value="REPLACE">
                                {account?.external_id_configured
                                    ? 'Reemplazarlo'
                                    : 'Escribir un External ID'}
                            </option>
                            <option value="CLEAR">
                                {account?.external_id_configured
                                    ? 'Quitarlo'
                                    : 'Sin External ID'}
                            </option>
                        </select>
                        {form.data.external_id_action === 'REPLACE' && (
                            <PasswordInput
                                id="external_id"
                                aria-label="External ID"
                                value={form.data.external_id}
                                onChange={(event) =>
                                    form.setData(
                                        'external_id',
                                        event.target.value,
                                    )
                                }
                                autoComplete="off"
                            />
                        )}
                        <InputError
                            message={
                                form.errors.external_id ??
                                form.errors.external_id_action
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="session_name">
                            Nombre de la sesión
                        </Label>
                        <Input
                            id="session_name"
                            value={form.data.session_name}
                            onChange={(event) =>
                                form.setData('session_name', event.target.value)
                            }
                            placeholder="MoodleWebToolkit"
                        />
                        <InputError message={form.errors.session_name} />
                    </div>
                </>
            )}

            <label className="flex items-center gap-3 md:col-span-2">
                <Checkbox
                    id="is_default"
                    checked={form.data.is_default}
                    onCheckedChange={(checked) =>
                        form.setData('is_default', checked === true)
                    }
                />
                <span className="text-sm">Usar como cuenta predeterminada</span>
            </label>
            <div className="flex flex-wrap gap-2 md:col-span-2">
                <Button disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Guardar cuenta
                </Button>
                {!first && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancelar
                    </Button>
                )}
            </div>
        </form>
    );
}

function CheckResult({
    ok,
    title,
    checks,
}: {
    ok: boolean;
    title: string;
    checks: Check[];
}) {
    return (
        <Alert variant={ok ? 'default' : 'destructive'}>
            {ok ? (
                <CheckCircle2 className="size-4" />
            ) : (
                <XCircle className="size-4" />
            )}
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>
                <ul className="mt-1 space-y-1">
                    {checks.map((check) => (
                        <li key={check.label}>
                            <span className="font-medium">
                                {check.ok ? '✓' : '✗'} {check.label}:
                            </span>{' '}
                            {check.message}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

function PolicyHelp({
    bucket,
    identity,
}: {
    bucket: string;
    identity: string | null;
}) {
    const objects = `arn:aws:s3:::${bucket}/mwt`;
    const policies: { title: string; description: string; body: object }[] = [
        {
            title: 'Identidad de la plataforma',
            description:
                'Para el rol, el usuario o el rol asumido con el que actúa la plataforma. Puede limitar las instancias con una condición por etiqueta (ssm:resourceTag).',
            body: {
                Version: '2012-10-17',
                Statement: [
                    {
                        Sid: 'RunAgentCommands',
                        Effect: 'Allow',
                        Action: 'ssm:SendCommand',
                        Resource: [
                            'arn:aws:ec2:*:*:instance/*',
                            'arn:aws:ssm:*::document/AWS-RunShellScript',
                        ],
                    },
                    {
                        Sid: 'FollowCommands',
                        Effect: 'Allow',
                        Action: [
                            'ssm:GetCommandInvocation',
                            'ssm:CancelCommand',
                            'ssm:DescribeInstanceInformation',
                        ],
                        Resource: '*',
                    },
                    {
                        Sid: 'CheckBucket',
                        Effect: 'Allow',
                        Action: 's3:ListBucket',
                        Resource: `arn:aws:s3:::${bucket}`,
                    },
                    {
                        Sid: 'VerifyPackages',
                        Effect: 'Allow',
                        Action: 's3:GetObject',
                        Resource: `${objects}/*`,
                    },
                    {
                        Sid: 'VerificationObject',
                        Effect: 'Allow',
                        Action: ['s3:PutObject', 's3:DeleteObject'],
                        Resource: `${objects}/_pruebas/*`,
                    },
                ],
            },
        },
        {
            title: 'Rol de las instancias de origen',
            description:
                'Además de AmazonSSMManagedInstanceCore. El origen solo escribe sus paquetes; nunca lee ni borra.',
            body: {
                Version: '2012-10-17',
                Statement: [
                    {
                        Sid: 'UploadSourcePackages',
                        Effect: 'Allow',
                        Action: 's3:PutObject',
                        Resource: `${objects}/*`,
                    },
                ],
            },
        },
        {
            title: 'Rol de la instancia de destino',
            description:
                'Además de AmazonSSMManagedInstanceCore. Descarga los paquetes y sube la copia integral final del sitio consolidado.',
            body: {
                Version: '2012-10-17',
                Statement: [
                    {
                        Sid: 'DownloadPackages',
                        Effect: 'Allow',
                        Action: 's3:GetObject',
                        Resource: `${objects}/*`,
                    },
                    {
                        Sid: 'UploadConsolidatedBackup',
                        Effect: 'Allow',
                        Action: 's3:PutObject',
                        Resource: `${objects}/*/consolidated/*`,
                    },
                ],
            },
        },
        {
            title: 'Relación de confianza para «Asumir un rol»',
            description:
                'En el rol de la institución. El principal es la identidad del entorno de la plataforma (la que muestra «Verificar» en una cuenta «Rol del entorno»), y el External ID debe coincidir con el guardado aquí.',
            body: {
                Version: '2012-10-17',
                Statement: [
                    {
                        Effect: 'Allow',
                        Principal: {
                            AWS:
                                identity ??
                                'arn:aws:iam::CUENTA-DE-LA-PLATAFORMA:role/ROL-DE-LA-PLATAFORMA',
                        },
                        Action: 'sts:AssumeRole',
                        Condition: {
                            StringEquals: {
                                'sts:ExternalId': 'EL-EXTERNAL-ID-GUARDADO',
                            },
                        },
                    },
                ],
            },
        },
    ];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ShieldCheck className="size-5" /> Permisos IAM sugeridos
                </CardTitle>
                <CardDescription>
                    Los permisos mínimos los crea la institución en su cuenta.
                    Las políticas usan el bucket{' '}
                    <code className="break-all">{bucket}</code>; las rutas
                    siguen la forma <code>mwt/&lt;proyecto&gt;/…</code>.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {policies.map((policy) => (
                    <PolicyBlock key={policy.title} {...policy} />
                ))}
            </CardContent>
        </Card>
    );
}

function PolicyBlock({
    title,
    description,
    body,
}: {
    title: string;
    description: string;
    body: object;
}) {
    const [copied, copy] = useClipboard();
    const text = JSON.stringify(body, null, 2);

    return (
        <details className="rounded-md border p-3">
            <summary className="cursor-pointer font-medium">{title}</summary>
            <p className="text-muted-foreground mt-2 text-sm">{description}</p>
            <div className="relative mt-2">
                <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs">
                    {text}
                </pre>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="absolute top-2 right-2"
                    onClick={() => void copy(text)}
                >
                    <Copy /> {copied === text ? 'Copiada' : 'Copiar'}
                </Button>
            </div>
        </details>
    );
}

AwsSettingsPage.layout = {
    breadcrumbs: [{ title: 'AWS', href: edit() }],
};
