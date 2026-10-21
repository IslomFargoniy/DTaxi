import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { globalSip } from '@/components/webphone/sip-state';
import { WebPhone } from '@/components/webphone/webphone';
import { ChatPanel } from '@/pages/operator/chat-panel';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth, chatBranchIds } = usePage<{
        auth: {
            user: { id: number; name: string } | null;
            permissions: string[];
        };
        chatBranchIds?: number[];
    }>().props;
    // Qo'ng'iroqqa javob beradiganlar (operator, superadmin) — webfon har sahifada
    const canPhone =
        auth.permissions.includes('*') ||
        auth.permissions.includes('calls.answer');
    const phoneUser = auth.user && canPhone ? auth.user.id : null;

    // Telefonsiz foydalanuvchi kirdi (yoki ruxsat olib tashlandi) — oldingi ulanish yopiladi
    useEffect(() => {
        if (phoneUser === null && globalSip.ua) {
            globalSip.destroyUA();
        }
    }, [phoneUser]);

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
            {/* Layout sahifalar orasida saqlanadi — chat qayta yuklanmaydi, ochiq suhbat yopilmaydi */}
            {auth.user && chatBranchIds && chatBranchIds.length > 0 && (
                <ChatPanel branchIds={chatBranchIds} userId={auth.user.id} />
            )}
            {auth.user && canPhone && (
                <WebPhone userId={auth.user.id} userName={auth.user.name} />
            )}
        </AppLayoutTemplate>
    );
}
