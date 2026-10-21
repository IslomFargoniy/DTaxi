import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/hooks/use-trans';
import { cn } from '@/lib/utils';

/**
 * Ro'yxatdagi yozuvni qo'shish/tahrirlash oynasi.
 */
export function EditDialog({
    open,
    onOpenChange,
    title,
    onSubmit,
    processing,
    className,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    onSubmit: () => void;
    processing?: boolean;
    /** Keng shakllar uchun (masalan, ustunli filial oynasi) */
    className?: string;
    children: ReactNode;
}) {
    const { t } = useTrans();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'max-h-[90svh] overflow-y-auto sm:max-w-lg',
                    className,
                )}
            >
                <DialogHeader>
                    <DialogTitle>{t(title)}</DialogTitle>
                </DialogHeader>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        onSubmit();
                    }}
                >
                    {children}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button disabled={processing}>{t('Save')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
