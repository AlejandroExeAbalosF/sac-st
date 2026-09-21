import * as PopoverPrimitive from '@radix-ui/react-popover';
import * as React from 'react';
import { cn } from '@/lib/utils';

function Popover(props: React.ComponentProps<typeof PopoverPrimitive.Root>) {
    return <PopoverPrimitive.Root data-slot="popover" {...props} />;
}

function PopoverTrigger(
    props: React.ComponentProps<typeof PopoverPrimitive.Trigger>,
) {
    return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />;
}

function PopoverContent({
    className,
    align = 'center',
    sideOffset = 6,
    collisionPadding = 16,
    overlay = false,
    ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content> & {
    /**
     * Oscurece el resto de la pantalla mientras el popover está abierto.
     *
     * En un teléfono el popover ocupa casi todo el ancho y comparte la
     * paleta con lo que quedó detrás: sin el velo, no se lee dónde termina
     * uno y empieza lo otro. Va apagado por defecto porque un popover chico
     * —un menú, una ayuda— no necesita robarle la pantalla a nadie.
     */
    overlay?: boolean;
}) {
    return (
        <>
            {/*
             * El velo va en su propio portal: el de Radix hace `asChild`
             * sobre lo que le pasen, así que con dos hijos rompe. Al montarse
             * primero queda antes en el DOM, y el contenido —con el mismo
             * `z`— le pasa por encima.
             */}
            {overlay && (
                <PopoverPrimitive.Portal>
                    <div
                        data-slot="popover-overlay"
                        aria-hidden="true"
                        /*
                         * Mismo `z` que el contenido y no uno menor: con un
                         * `z` más bajo se metería debajo de un diálogo
                         * abierto, que es justo donde este picker se usa.
                         */
                        className="animate-in fade-in-0 fixed inset-0 z-50 bg-black/40 duration-200 motion-reduce:animate-none"
                    />
                </PopoverPrimitive.Portal>
            )}
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Content
                data-slot="popover-content"
                align={align}
                sideOffset={sideOffset}
                collisionPadding={collisionPadding}
                className={cn(
                    'z-50 origin-(--radix-popover-content-transform-origin) rounded-lg border bg-popover text-popover-foreground shadow-raised outline-hidden',
                    'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 duration-200',
                    'motion-reduce:animate-none',
                    className,
                )}
                {...props}
                />
            </PopoverPrimitive.Portal>
        </>
    );
}

export { Popover, PopoverContent, PopoverTrigger };
