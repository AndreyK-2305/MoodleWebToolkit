import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Auth } from '@/types';

type Role = { value: 'ADMIN' | 'OPERATOR' | 'AUDITOR'; label: string };
type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: Role['value'];
    is_active: boolean;
    created_at: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
type Props = {
    users: { data: ManagedUser[]; links: PageLink[] };
    roles: Role[];
};

export default function UsersIndex({ users, roles }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const form = useForm({
        name: '',
        email: '',
        role: 'OPERATOR' as Role['value'],
        password: '',
        password_confirmation: '',
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/admin/users', { onSuccess: () => form.reset() });
    };

    return (
        <>
            <Head title="Usuarios" />
            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Usuarios</h1>
                    <p className="text-muted-foreground text-sm">
                        Acceso cerrado con tres roles fijos.
                    </p>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <UserPlus className="size-5" /> Crear usuario
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Correo</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={form.errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="role">Rol</Label>
                                <select
                                    id="role"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={form.data.role}
                                    onChange={(event) =>
                                        form.setData(
                                            'role',
                                            event.target.value as Role['value'],
                                        )
                                    }
                                >
                                    {roles.map((role) => (
                                        <option
                                            key={role.value}
                                            value={role.value}
                                        >
                                            {role.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.role} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    Contraseña temporal
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={form.errors.password} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirmar contraseña
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={form.data.password_confirmation}
                                    onChange={(event) =>
                                        form.setData(
                                            'password_confirmation',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                            <div className="flex items-end">
                                <Button
                                    className="w-full"
                                    disabled={form.processing}
                                >
                                    Crear usuario
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Cuentas registradas</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="pb-3">Usuario</th>
                                    <th className="pb-3">Rol</th>
                                    <th className="pb-3">Estado</th>
                                    <th className="pb-3 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.data.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-4">
                                            <p className="font-medium">
                                                {user.name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {user.email}
                                            </p>
                                        </td>
                                        <td className="py-4">
                                            <select
                                                aria-label={`Rol de ${user.name}`}
                                                disabled={
                                                    user.id === auth.user.id
                                                }
                                                className="border-input bg-background h-9 rounded-md border px-2"
                                                value={user.role}
                                                onChange={(event) =>
                                                    router.patch(
                                                        `/admin/users/${user.id}/role`,
                                                        {
                                                            role: event.target
                                                                .value,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {roles.map((role) => (
                                                    <option
                                                        key={role.value}
                                                        value={role.value}
                                                    >
                                                        {role.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="py-4">
                                            <Badge
                                                variant={
                                                    user.is_active
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {user.is_active
                                                    ? 'Activo'
                                                    : 'Inactivo'}
                                            </Badge>
                                        </td>
                                        <td className="py-4 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    user.id === auth.user.id
                                                }
                                                onClick={() =>
                                                    router.patch(
                                                        `/admin/users/${user.id}/status`,
                                                        {
                                                            is_active:
                                                                !user.is_active,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {user.is_active
                                                    ? 'Desactivar'
                                                    : 'Activar'}
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {users.links.map((link) =>
                                link.url ? (
                                    <Button
                                        key={link.label}
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        size="sm"
                                        asChild
                                    >
                                        <Link
                                            href={link.url}
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    </Button>
                                ) : null,
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Usuarios', href: '/admin/users' }],
};
