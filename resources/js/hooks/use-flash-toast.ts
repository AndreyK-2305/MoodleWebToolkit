import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            // Errors explain why an action did not happen; they stay long enough to be read.
            toast[data.type](
                data.message,
                data.type === 'error' ? { duration: 12000 } : undefined,
            );
        });
    }, []);
}
