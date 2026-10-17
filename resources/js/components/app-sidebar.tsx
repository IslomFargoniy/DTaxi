import { Link } from '@inertiajs/react';
import {
    Banknote,
    BarChart3,
    Building2,
    FileBarChart,
    Car,
    ClipboardList,
    Headset,
    LayoutGrid,
    MapPin,
    AudioLines,
    PhoneCall,
    PlugZap,
    Receipt,
    Waypoints,
    Settings2,
    Users,
    UserRound,
    Smartphone,
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
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as branches } from '@/routes/admin/branches';
import { index as calls } from '@/routes/admin/calls';
import { index as clients } from '@/routes/admin/clients';
import { edit as companySettings } from '@/routes/admin/company-settings';
import { index as driverApp } from '@/routes/admin/driver-app';
import { index as drivers } from '@/routes/admin/drivers';
import { index as integrations } from '@/routes/admin/integrations';
import { index as landmarks } from '@/routes/admin/landmarks';
import { index as orders } from '@/routes/admin/orders';
import { index as reports } from '@/routes/admin/reports';
import { index as sounds } from '@/routes/admin/sounds';
import { index as staff } from '@/routes/admin/staff';
import { index as tariffs } from '@/routes/admin/tariffs';
import { index as telephony } from '@/routes/admin/telephony';
import { index as cashier } from '@/routes/cashier';
import { index as operator } from '@/routes/operator';
import type { NavItem } from '@/types';

/**
 * Menyu rolga qarab: har bo'lim faqat ruxsati borlarga ko'rinadi (backend ham tekshiradi).
 */
export function AppSidebar() {
    const { can, hasRole } = useCan();

    const work: NavItem[] = [
        ...(can('reports.view')
            ? [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }]
            : []),
        ...(can('orders.manage')
            ? [{ title: 'Operator', href: operator(), icon: Headset }]
            : []),
        ...(can('balance.topup')
            ? [{ title: 'Cash desk', href: cashier(), icon: Banknote }]
            : []),
        ...(can('orders.view')
            ? [{ title: 'Orders', href: orders(), icon: ClipboardList }]
            : []),
    ];
    const manage: NavItem[] = [
        ...(can('drivers.manage')
            ? [{ title: 'Drivers', href: drivers(), icon: Car }]
            : []),
        ...(can('orders.view')
            ? [{ title: 'Clients', href: clients(), icon: UserRound }]
            : []),
        ...(can('orders.view')
            ? [{ title: 'Landmarks', href: landmarks(), icon: MapPin }]
            : []),
        ...(can('tariffs.manage')
            ? [{ title: 'Tariffs', href: tariffs(), icon: Receipt }]
            : []),
        ...(can('users.manage')
            ? [{ title: 'Staff', href: staff(), icon: Users }]
            : []),
        ...(can('calls.listen')
            ? [{ title: 'Calls', href: calls(), icon: PhoneCall }]
            : []),
        ...(can('reports.view')
            ? [{ title: 'Reports', href: reports(), icon: FileBarChart }]
            : []),
    ];
    const system: NavItem[] = hasRole('superadmin')
        ? [
              { title: 'Branches', href: branches(), icon: Building2 },
              {
                  title: 'Company settings',
                  href: companySettings(),
                  icon: Settings2,
              },
              { title: 'Integrations', href: integrations(), icon: PlugZap },
              { title: 'Telephony', href: telephony(), icon: Waypoints },
              { title: 'Voice clips', href: sounds(), icon: AudioLines },
              { title: 'Driver app', href: driverApp(), icon: Smartphone },
              {
                  title: 'Queues',
                  href: '/horizon',
                  icon: BarChart3,
                  external: true,
              },
          ]
        : [];

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
                {work.length > 0 && <NavMain items={work} label="Work" />}
                {manage.length > 0 && (
                    <NavMain items={manage} label="Management" />
                )}
                {system.length > 0 && (
                    <NavMain items={system} label="Administration" />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
