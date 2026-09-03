import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            actionConfirmation: {
                required: boolean;
                expired: boolean;
                confirmed_at: number | null;
                expires_at: number | null;
                lifetime_minutes: number;
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
