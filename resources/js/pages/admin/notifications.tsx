import { Head, useForm, usePage } from '@inertiajs/react';
import { Mail, Plus, Send, Trash2, Users } from 'lucide-react';
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
import { edit, test as sendTest, update } from '@/routes/admin/notifications';
import { update as updateRecipients } from '@/routes/admin/notifications/recipients';

type Encryption = 'TLS' | 'STARTTLS' | 'NONE';
type PasswordAction = 'KEEP' | 'REPLACE' | 'CLEAR';
type Settings = {
    enabled: boolean;
    smtp_host: string;
    smtp_port: number;
    smtp_encryption: Encryption;
    smtp_username: string | null;
    from_address: string;
    from_name: string;
    progress_interval_minutes: number | null;
    password_configured: boolean;
    updated_at: string | null;
};
type Recipient = {
    email: string;
    interventions: boolean;
    outcomes: boolean;
    backups: boolean;
    progress: boolean;
};
type Group = Exclude<keyof Recipient, 'email'>;
type TestTarget = 'SELF' | 'ADDRESS' | 'RECIPIENTS';
type Props = {
    settings: Settings | null;
    encryptions: { value: Encryption; label: string }[];
    recipients: Recipient[];
    maxRecipients: number;
    usesApplicationMailer: boolean;
    lastTest: { ok: boolean; message: string | null } | null;
};

const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';

const groups: { key: Group; title: string }[] = [
    { key: 'interventions', title: 'Intervenciones' },
    { key: 'outcomes', title: 'Finalizaciones y fallos' },
    { key: 'backups', title: 'Respaldos' },
    { key: 'progress', title: 'Resumen de avance' },
];

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function NotificationSettingsPage({
    settings,
    encryptions,
    recipients,
    maxRecipients,
    usesApplicationMailer,
    lastTest,
}: Props) {
    const { auth } = usePage().props;
    const form = useForm({
        enabled: settings?.enabled ?? false,
        smtp_host: settings?.smtp_host ?? '',
        smtp_port: settings?.smtp_port ?? 587,
        smtp_encryption:
            settings?.smtp_encryption ?? ('STARTTLS' as Encryption),
        smtp_username: settings?.smtp_username ?? '',
        password_action: (settings?.password_configured
            ? 'KEEP'
            : 'REPLACE') as PasswordAction,
        smtp_password: '',
        from_address: settings?.from_address ?? '',
        from_name: settings?.from_name ?? 'MoodleWebToolkit',
        progress_enabled:
            (settings?.progress_interval_minutes ?? null) !== null,
        progress_interval_minutes: settings?.progress_interval_minutes ?? 60,
    });
    const testForm = useForm({ target: 'SELF' as TestTarget, address: '' });
    const recipientsForm = useForm({ recipients });
    const [newAddress, setNewAddress] = useState('');
    const [addError, setAddError] = useState<string | null>(null);

    const addRecipient = () => {
        const email = newAddress.trim().toLowerCase();
        const problem = !emailPattern.test(email)
            ? 'Escriba una dirección de correo válida.'
            : recipientsForm.data.recipients.some((r) => r.email === email)
              ? 'Esta dirección ya está en la lista.'
              : recipientsForm.data.recipients.length >= maxRecipients
                ? `Puede agregar hasta ${maxRecipients} destinatarios adicionales.`
                : null;
        setAddError(problem);
        if (problem !== null) {
            return;
        }
        recipientsForm.setData('recipients', [
            ...recipientsForm.data.recipients,
            {
                email,
                interventions: true,
                outcomes: true,
                backups: false,
                progress: false,
            },
        ]);
        setNewAddress('');
    };

    const setGroup = (index: number, group: Group, value: boolean) =>
        recipientsForm.setData(
            'recipients',
            recipientsForm.data.recipients.map((recipient, i) =>
                i === index ? { ...recipient, [group]: value } : recipient,
            ),
        );

    const recipientErrors = recipientsForm.errors as Record<string, string>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            smtp_password:
                data.password_action === 'REPLACE' ? data.smtp_password : '',
            progress_interval_minutes: data.progress_enabled
                ? data.progress_interval_minutes
                : null,
        }));
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess: () => form.setData('smtp_password', ''),
        });
    };

    const passwordConfigured = settings?.password_configured ?? false;

    return (
        <>
            <Head title="Notificaciones" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Notificaciones</h1>
                    <p className="text-muted-foreground text-sm">
                        La plataforma envía los avisos de las ejecuciones por
                        correo con este servidor SMTP. Las credenciales se
                        guardan cifradas aquí y nunca viajan a los servidores de
                        los proyectos.
                    </p>
                </div>

                {usesApplicationMailer && (
                    <Alert>
                        <Mail className="size-4" />
                        <AlertTitle>Sin configuración propia</AlertTitle>
                        <AlertDescription>
                            Mientras no guarde una configuración, los correos
                            usan el servidor de correo definido en la
                            instalación de la plataforma.
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Mail className="size-5" /> Servidor SMTP
                            {settings !== null && (
                                <Badge
                                    variant={
                                        settings.enabled
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {settings.enabled
                                        ? 'Envío activo'
                                        : 'Envío desactivado'}
                                </Badge>
                            )}
                        </CardTitle>
                        <CardDescription>
                            Guardar cambios pide confirmar su contraseña y queda
                            registrado en la auditoría, sin los valores.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-2"
                        >
                            <label className="flex items-center gap-3 md:col-span-2">
                                <Checkbox
                                    id="enabled"
                                    checked={form.data.enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                <span className="text-sm font-medium">
                                    Enviar notificaciones por correo
                                </span>
                            </label>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_host">Servidor</Label>
                                <Input
                                    id="smtp_host"
                                    value={form.data.smtp_host}
                                    onChange={(event) =>
                                        form.setData(
                                            'smtp_host',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="smtp.ejemplo.edu"
                                    required
                                />
                                <InputError message={form.errors.smtp_host} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_port">Puerto</Label>
                                <Input
                                    id="smtp_port"
                                    type="number"
                                    min={1}
                                    max={65535}
                                    value={form.data.smtp_port}
                                    onChange={(event) =>
                                        form.setData(
                                            'smtp_port',
                                            Number(event.target.value),
                                        )
                                    }
                                    required
                                />
                                <InputError message={form.errors.smtp_port} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_encryption">Cifrado</Label>
                                <select
                                    id="smtp_encryption"
                                    className={selectClass}
                                    value={form.data.smtp_encryption}
                                    onChange={(event) =>
                                        form.setData(
                                            'smtp_encryption',
                                            event.target.value as Encryption,
                                        )
                                    }
                                >
                                    {encryptions.map((encryption) => (
                                        <option
                                            key={encryption.value}
                                            value={encryption.value}
                                        >
                                            {encryption.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={form.errors.smtp_encryption}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="smtp_username">Usuario</Label>
                                <Input
                                    id="smtp_username"
                                    value={form.data.smtp_username}
                                    onChange={(event) =>
                                        form.setData(
                                            'smtp_username',
                                            event.target.value,
                                        )
                                    }
                                    autoComplete="off"
                                    placeholder="Opcional"
                                />
                                <InputError
                                    message={form.errors.smtp_username}
                                />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="password_action">
                                    Contraseña{' '}
                                    {passwordConfigured ? (
                                        <Badge variant="outline">
                                            configurada
                                        </Badge>
                                    ) : (
                                        <Badge variant="secondary">
                                            sin configurar
                                        </Badge>
                                    )}
                                </Label>
                                <select
                                    id="password_action"
                                    className={selectClass}
                                    value={form.data.password_action}
                                    onChange={(event) =>
                                        form.setData(
                                            'password_action',
                                            event.target
                                                .value as PasswordAction,
                                        )
                                    }
                                >
                                    {passwordConfigured && (
                                        <option value="KEEP">
                                            Conservar la contraseña actual
                                        </option>
                                    )}
                                    <option value="REPLACE">
                                        {passwordConfigured
                                            ? 'Reemplazarla'
                                            : 'Escribir una contraseña'}
                                    </option>
                                    <option value="CLEAR">
                                        {passwordConfigured
                                            ? 'Borrarla'
                                            : 'Sin contraseña'}
                                    </option>
                                </select>
                                {form.data.password_action === 'REPLACE' && (
                                    <PasswordInput
                                        id="smtp_password"
                                        aria-label="Nueva contraseña SMTP"
                                        value={form.data.smtp_password}
                                        onChange={(event) =>
                                            form.setData(
                                                'smtp_password',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="new-password"
                                    />
                                )}
                                <InputError
                                    message={
                                        form.errors.smtp_password ??
                                        form.errors.password_action
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="from_address">
                                    Dirección del remitente
                                </Label>
                                <Input
                                    id="from_address"
                                    type="email"
                                    value={form.data.from_address}
                                    onChange={(event) =>
                                        form.setData(
                                            'from_address',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="notificaciones@ejemplo.edu"
                                    required
                                />
                                <InputError
                                    message={form.errors.from_address}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="from_name">
                                    Nombre del remitente
                                </Label>
                                <Input
                                    id="from_name"
                                    value={form.data.from_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'from_name',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={form.errors.from_name} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <label className="flex items-center gap-3">
                                    <Checkbox
                                        id="progress_enabled"
                                        checked={form.data.progress_enabled}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'progress_enabled',
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span className="text-sm font-medium">
                                        Enviar un resumen de avance mientras una
                                        ejecución está en curso
                                    </span>
                                </label>
                                {form.data.progress_enabled && (
                                    <div className="flex items-center gap-2 text-sm">
                                        <span>Cada</span>
                                        <Input
                                            id="progress_interval_minutes"
                                            aria-label="Minutos entre resúmenes"
                                            type="number"
                                            min={5}
                                            max={1440}
                                            className="w-24"
                                            value={
                                                form.data
                                                    .progress_interval_minutes
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'progress_interval_minutes',
                                                    Number(event.target.value),
                                                )
                                            }
                                        />
                                        <span>minutos</span>
                                    </div>
                                )}
                                <InputError
                                    message={
                                        form.errors.progress_interval_minutes
                                    }
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Button disabled={form.processing}>
                                    {form.processing && <Spinner />}
                                    Guardar configuración
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="size-5" /> Destinatarios
                            adicionales
                        </CardTitle>
                        <CardDescription>
                            Direcciones que reciben los avisos de todos los
                            proyectos, además de los usuarios que los eligieron;
                            por ejemplo, la persona designada para intervenir,
                            aunque no tenga cuenta en la plataforma. Una
                            dirección nunca recibe el mismo aviso dos veces.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                recipientsForm.put(updateRecipients.url(), {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id="new_recipient"
                                    type="email"
                                    aria-label="Nueva dirección de correo"
                                    placeholder="persona@ejemplo.edu"
                                    value={newAddress}
                                    onChange={(event) =>
                                        setNewAddress(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            addRecipient();
                                        }
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addRecipient}
                                    disabled={
                                        recipientsForm.data.recipients.length >=
                                        maxRecipients
                                    }
                                >
                                    <Plus /> Agregar
                                </Button>
                            </div>
                            <InputError
                                message={addError ?? recipientErrors.recipients}
                            />

                            {recipientsForm.data.recipients.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No hay destinatarios adicionales. Los avisos
                                    llegan sólo a los usuarios de cada proyecto.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-md border">
                                    {recipientsForm.data.recipients.map(
                                        (recipient, index) => (
                                            <li
                                                key={recipient.email}
                                                className="space-y-2 p-3"
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-sm font-medium break-all">
                                                        {recipient.email}
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Quitar ${recipient.email}`}
                                                        onClick={() =>
                                                            recipientsForm.setData(
                                                                'recipients',
                                                                recipientsForm.data.recipients.filter(
                                                                    (_, i) =>
                                                                        i !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                                <div className="flex flex-wrap gap-x-4 gap-y-2">
                                                    {groups.map((group) => (
                                                        <label
                                                            key={group.key}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            <Checkbox
                                                                checked={
                                                                    recipient[
                                                                        group
                                                                            .key
                                                                    ]
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    setGroup(
                                                                        index,
                                                                        group.key,
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                            {group.title}
                                                        </label>
                                                    ))}
                                                </div>
                                                <InputError
                                                    message={
                                                        recipientErrors[
                                                            `recipients.${index}.email`
                                                        ]
                                                    }
                                                />
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                            <p className="text-muted-foreground text-xs">
                                {recipientsForm.data.recipients.length} de{' '}
                                {maxRecipients} direcciones. Guardar pide
                                confirmar su contraseña; la auditoría registra
                                sólo cuántas hay, no las direcciones.
                            </p>
                            <Button disabled={recipientsForm.processing}>
                                {recipientsForm.processing && <Spinner />}
                                Guardar destinatarios
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Send className="size-5" /> Correo de prueba
                        </CardTitle>
                        <CardDescription>
                            Envía un correo con la configuración guardada (no
                            con cambios sin guardar).
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-2 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="test_target">Enviar a</Label>
                                <select
                                    id="test_target"
                                    className={selectClass}
                                    value={testForm.data.target}
                                    onChange={(event) =>
                                        testForm.setData(
                                            'target',
                                            event.target.value as TestTarget,
                                        )
                                    }
                                >
                                    <option value="SELF">
                                        Mi dirección ({auth.user?.email})
                                    </option>
                                    <option value="ADDRESS">
                                        Otra dirección
                                    </option>
                                    <option
                                        value="RECIPIENTS"
                                        disabled={recipients.length === 0}
                                    >
                                        Todos los destinatarios adicionales (
                                        {recipients.length})
                                    </option>
                                </select>
                            </div>
                            {testForm.data.target === 'ADDRESS' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="test_address">
                                        Dirección
                                    </Label>
                                    <Input
                                        id="test_address"
                                        type="email"
                                        placeholder="persona@ejemplo.edu"
                                        value={testForm.data.address}
                                        onChange={(event) =>
                                            testForm.setData(
                                                'address',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            )}
                        </div>
                        <InputError
                            message={
                                testForm.errors.address ??
                                testForm.errors.target
                            }
                        />
                        {lastTest !== null && (
                            <Alert
                                variant={
                                    lastTest.ok ? 'default' : 'destructive'
                                }
                            >
                                <AlertTitle>
                                    {lastTest.ok
                                        ? 'Correo de prueba enviado'
                                        : 'El correo de prueba no se envió'}
                                </AlertTitle>
                                <AlertDescription>
                                    {lastTest.message ??
                                        'Revise su bandeja de entrada.'}
                                </AlertDescription>
                            </Alert>
                        )}
                        <Button
                            variant="outline"
                            disabled={settings === null || testForm.processing}
                            onClick={() =>
                                testForm.post(sendTest.url(), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            {testForm.processing && <Spinner />}
                            Enviar correo de prueba
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

NotificationSettingsPage.layout = {
    breadcrumbs: [{ title: 'Notificaciones', href: edit() }],
};
