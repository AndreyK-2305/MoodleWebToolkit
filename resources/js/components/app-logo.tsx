import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-9 items-center justify-center rounded-lg bg-amber-500 text-slate-950 shadow-sm">
                <AppLogoIcon className="size-6" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold">
                    {name as string}
                </span>
                <span className="text-muted-foreground truncate text-[10px] tracking-widest uppercase">
                    Administración
                </span>
            </div>
        </>
    );
}
