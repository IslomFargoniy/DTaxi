import { usePage } from '@inertiajs/react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
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
        auth: { user: { id: number } | null };
        chatBranchIds?: number[];
    }>().props;

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
            {/* Layout sahifalar orasida saqlanadi — chat qayta yuklanmaydi, ochiq suhbat yopilmaydi */}
            {auth.user && chatBranchIds && chatBranchIds.length > 0 && (
                <ChatPanel branchIds={chatBranchIds} userId={auth.user.id} />
            )}
        </AppLayoutTemplate>
    );
}
