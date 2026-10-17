import { Link } from '@inertiajs/react';
import { Building2, Headset, LayoutGrid, PlugZap } from 'lucide-react';
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
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { edit as companySettings } from '@/routes/admin/company-settings';
import { index as integrations } from '@/routes/admin/integrations';
import { index as operator } from '@/routes/operator';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const superadminNavItems: NavItem[] = [
    {
        title: 'Company settings',
        href: companySettings(),
        icon: Building2,
    },
    {
        title: 'Integrations',
        href: integrations(),
        icon: PlugZap,
    },
];

export function AppSidebar() {
    const { can, hasRole } = useCan();
    const items: NavItem[] = [
        ...mainNavItems,
        ...(can('orders.manage')
            ? [{ title: 'Operator', href: operator(), icon: Headset }]
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
                <NavMain items={items} />
                {hasRole('superadmin') && (
                    <NavMain
                        items={superadminNavItems}
                        label="Administration"
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
