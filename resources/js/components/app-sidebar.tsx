import { Link, usePage } from '@inertiajs/react';
import {
    BookOpenText,
    FolderKanban,
    Info,
    LayoutDashboard,
    Settings,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const mainNavItems: NavItem[] = [
        { title: 'Inicio', href: dashboard(), icon: LayoutDashboard },
        { title: 'Proyectos', href: '/projects', icon: FolderKanban },
        { title: 'Manuales', href: '/manuals', icon: BookOpenText },
        { title: 'Acerca de', href: '/about', icon: Info },
        {
            title: 'Configuración',
            href: '/settings/profile',
            icon: Settings,
        },
        ...(auth.user.role === 'ADMIN'
            ? [{ title: 'Usuarios', href: '/admin/users', icon: Users }]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>
            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
