import { Head, useForm } from '@inertiajs/react';
import { Mail, Send } from 'lucide-react';
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
type Props = {
    settings: Settings | null;
    encryptions: { value: Encryption; label: string }[];
    usesApplicationMailer: boolean;
    lastTest: { ok: boolean; message: string | null } | null;
};

const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function NotificationSettingsPage({
    settings,
    encryptions,
    usesApplicationMailer,
    lastTest,
}: Props) {
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
    const testForm = useForm({});

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
                            <Send className="size-5" /> Correo de prueba
                        </CardTitle>
                        <CardDescription>
                            Envía un correo a su propia dirección con la
                            configuración guardada (no con cambios sin guardar).
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
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
                                    {lastTest.ok
                                        ? 'Revise su bandeja de entrada.'
                                        : lastTest.message}
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
