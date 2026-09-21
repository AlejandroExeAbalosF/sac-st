import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import {
    CircleCheck,
    CircleX,
    Info,
    LoaderCircle,
    TriangleAlert,
} from 'lucide-react';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            closeButton
            duration={6000}
            visibleToasts={3}
            gap={10}
            offset={24}
            mobileOffset={16}
            richColors
            swipeDirections={['right', 'bottom']}
            containerAriaLabel="Notificaciones"
            icons={{
                success: <CircleCheck className="size-4" aria-hidden="true" />,
                info: <Info className="size-4" aria-hidden="true" />,
                warning: (
                    <TriangleAlert className="size-4" aria-hidden="true" />
                ),
                error: <CircleX className="size-4" aria-hidden="true" />,
                loading: (
                    <LoaderCircle
                        className="size-4 animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                ),
            }}
            toastOptions={{
                closeButtonAriaLabel: 'Cerrar notificación',
                classNames: {
                    toast: 'sac-toast',
                    title: 'text-sm font-medium leading-5',
                    description: 'text-xs leading-5 opacity-85',
                    icon: 'mt-0.5 self-start',
                    closeButton: 'sac-toast-cerrar',
                },
            }}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                    '--success-bg': 'var(--success-soft)',
                    '--success-text': 'var(--success-strong)',
                    '--success-border': 'var(--success)',
                    '--info-bg': 'var(--info-soft)',
                    '--info-text': 'var(--info-strong)',
                    '--info-border': 'var(--info)',
                    '--warning-bg': 'var(--warning-soft)',
                    '--warning-text': 'var(--warning-strong)',
                    '--warning-border': 'var(--warning)',
                    '--error-bg': 'var(--destructive-soft)',
                    '--error-text': 'var(--destructive-strong)',
                    '--error-border': 'var(--destructive)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
