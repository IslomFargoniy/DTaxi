import { usePage } from '@inertiajs/react';

/**
 * Ruxsatni tekshirish (backend Gate bilan bir xil nomlar). Superadmin uchun `*`.
 */
export function useCan() {
    const { auth } = usePage<{
        auth: { roles: string[]; permissions: string[] };
    }>().props;

    const can = (permission: string) =>
        auth.permissions.includes('*') || auth.permissions.includes(permission);

    const hasRole = (role: string) => auth.roles.includes(role);

    return { can, hasRole };
}
