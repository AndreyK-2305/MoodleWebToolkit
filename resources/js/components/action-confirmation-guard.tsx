import { router, usePage } from '@inertiajs/react';
import type { Method, PendingVisit, RequestPayload } from '@inertiajs/core';
import { Eye, LockKeyhole } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type ActionConfirmation = {
    required: boolean;
    expired: boolean;
    confirmed_at: number | null;
    expires_at: number | null;
    lifetime_minutes: number;
};

type PendingMutation = {
    url: string;
    method: Method;
    data: RequestPayload;
    headers: Record<string, string>;
};

const excludedPaths = [
    '/auth/confirm-action-password',
    '/user/confirm-password',
    '/logout',
    '/settings/password',
];

export default function ActionConfirmationGuard() {
    const { actionConfirmation } = usePage<{
        actionConfirmation: ActionConfirmation;
    }>().props;
    const [expiresAt, setExpiresAt] = useState(actionConfirmation.expires_at);
    const expiresAtRef = useRef(expiresAt);
    const [trackingMode, setTrackingMode] = useState(
        actionConfirmation.expired ||
            (expiresAt !== null && expiresAt <= Math.floor(Date.now() / 1000)),
    );
    const [open, setOpen] = useState(actionConfirmation.required);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const pending = useRef<PendingMutation | null>(null);

    useEffect(() => {
        if (actionConfirmation.required) {
            setOpen(true);
            setTrackingMode(true);
        }
    }, [actionConfirmation.required]);

    useEffect(() => {
        expiresAtRef.current = expiresAt;
        const refresh = () => {
            const expiry = expiresAtRef.current;
            setTrackingMode(
                expiry !== null && expiry <= Math.floor(Date.now() / 1000),
            );
        };
        refresh();
        const timer = window.setInterval(refresh, 30_000);

        return () => window.clearInterval(timer);
    }, [expiresAt]);

    useEffect(
        () =>
            router.on('before', (event) => {
                const visit: PendingVisit = event.detail.visit;

                if (
                    visit.method === 'get' ||
                    excludedPaths.some((path) => visit.url.pathname === path)
                ) {
                    return;
                }

                pending.current = {
                    url: visit.url.toString(),
                    method: visit.method,
                    data: visit.data,
                    headers: visit.headers,
                };
                const expiry = expiresAtRef.current;

                if (
                    expiry !== null &&
                    expiry <= Math.floor(Date.now() / 1000)
                ) {
                    event.preventDefault();
                    setTrackingMode(true);
                    setOpen(true);
                }
            }),
        [],
    );

    const confirm = async (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        try {
            const response = await fetch('/auth/confirm-action-password', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ password }),
            });

            if (!response.ok) {
                const body = (await response.json()) as {
                    message?: string;
                    errors?: { password?: string[] };
                };
                setError(
                    body.errors?.password?.[0] ??
                        body.message ??
                        'No fue posible confirmar la contraseña.',
                );

                return;
            }

            const body = (await response.json()) as { expires_at: number };
            expiresAtRef.current = body.expires_at;
            setExpiresAt(body.expires_at);
            setTrackingMode(false);
            setOpen(false);
            setPassword('');
            const retry = pending.current;
            pending.current = null;

            if (retry !== null) {
                router.visit(retry.url, {
                    method: retry.method,
                    data: retry.data,
                    headers: retry.headers,
                    preserveScroll: true,
                    preserveState: true,
                });
            }
        } catch {
            setError('Se perdió la conexión. El seguimiento no se modificó.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <>
            {trackingMode && (
                <div className="border-border bg-muted/60 flex items-center justify-center gap-2 border-b px-4 py-2 text-xs">
                    <Badge variant="outline">
                        <Eye className="size-3" /> Modo de seguimiento
                    </Badge>
                    <span className="text-muted-foreground">
                        Puede seguir consultando; las modificaciones requieren
                        confirmar la contraseña.
                    </span>
                </div>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <LockKeyhole className="size-5" /> Confirmar para
                            modificar
                        </DialogTitle>
                        <DialogDescription>
                            La sesión de consulta continúa activa. Confirme su
                            contraseña para renovar el permiso de modificación
                            durante {actionConfirmation.lifetime_minutes}{' '}
                            minutos. La acción pendiente se validará nuevamente.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={confirm} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="action-confirmation-password">
                                Contraseña actual
                            </Label>
                            <PasswordInput
                                id="action-confirmation-password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                autoComplete="current-password"
                                autoFocus
                                required
                            />
                            <InputError message={error ?? undefined} />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Sólo seguir consultando
                            </Button>
                            <Button disabled={processing || password === ''}>
                                {processing && <Spinner />}
                                Confirmar y reintentar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.split('=').slice(1).join('='))
        : '';
}
