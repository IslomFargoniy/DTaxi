import {
    createContext,
    useCallback,
    useContext,
    useRef,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTrans } from '@/hooks/use-trans';

type ConfirmOptions = {
    /** Savol (tarjima kaliti) */
    title: string;
    description?: string;
    /** Tasdiqlash tugmasi matni; standart — "Delete" */
    confirmLabel?: string;
    /** O'chirish kabi qaytarib bo'lmaydigan amal — qizil tugma */
    destructive?: boolean;
};

type Confirm = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<Confirm | null>(null);

/**
 * Brauzerning window.confirm() o'rniga panel uslubidagi tasdiqlash oynasi.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const { t } = useTrans();
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    const resolver = useRef<((value: boolean) => void) | null>(null);

    const confirm = useCallback<Confirm>(
        (next) =>
            new Promise((resolve) => {
                resolver.current = resolve;
                setOptions(next);
            }),
        [],
    );

    const close = (value: boolean) => {
        resolver.current?.(value);
        resolver.current = null;
        setOptions(null);
    };

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <Dialog
                open={options !== null}
                onOpenChange={(open) => !open && close(false)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{options && t(options.title)}</DialogTitle>
                        {options?.description && (
                            <DialogDescription>
                                {t(options.description)}
                            </DialogDescription>
                        )}
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => close(false)}>
                            {t('Cancel')}
                        </Button>
                        <Button
                            autoFocus
                            variant={
                                (options?.destructive ?? true)
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={() => close(true)}
                        >
                            {t(options?.confirmLabel ?? 'Delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ConfirmContext.Provider>
    );
}

export function useConfirm(): Confirm {
    const confirm = useContext(ConfirmContext);
    if (!confirm) {
        throw new Error('useConfirm must be used inside ConfirmProvider');
    }

    return confirm;
}
