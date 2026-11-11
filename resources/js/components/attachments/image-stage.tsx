import { useRef, useState } from 'react';
import type { PointerEvent, WheelEvent } from 'react';
import { cn } from '@/lib/utils';

/**
 * Una foto ajustada a la pantalla, con zoom —rueda, doble clic o los
 * botones del visor— y paneo arrastrando cuando está ampliada.
 *
 * Es lo que hace legible el número de operación de un ticket fotografiado
 * con el teléfono, que a tamaño de pantalla es una mancha.
 */
export function ImageStage({
    url,
    alt,
    zoom,
    rotation,
    onZoomChange,
}: {
    url: string;
    alt: string;
    zoom: number;
    rotation: number;
    onZoomChange: (zoom: number) => void;
}) {
    const [offset, setOffset] = useState({ x: 0, y: 0 });
    const [panning, setPanning] = useState(false);
    const drag = useRef<{ x: number; y: number; ox: number; oy: number }>(null);

    // Sin zoom no hay paneo: la foto vuelve siempre al centro.
    const translate = zoom > 1 ? offset : { x: 0, y: 0 };

    const onPointerDown = (e: PointerEvent<HTMLDivElement>) => {
        if (zoom <= 1 || e.button !== 0) {
            return;
        }

        e.currentTarget.setPointerCapture(e.pointerId);
        drag.current = {
            x: e.clientX,
            y: e.clientY,
            ox: translate.x,
            oy: translate.y,
        };
        setPanning(true);
    };

    const onPointerMove = (e: PointerEvent<HTMLDivElement>) => {
        if (drag.current === null) {
            return;
        }

        setOffset({
            x: drag.current.ox + (e.clientX - drag.current.x) / zoom,
            y: drag.current.oy + (e.clientY - drag.current.y) / zoom,
        });
    };

    const endPan = () => {
        drag.current = null;
        setPanning(false);
    };

    const onWheel = (e: WheelEvent<HTMLDivElement>) => {
        const next = zoom * (e.deltaY < 0 ? 1.15 : 1 / 1.15);
        onZoomChange(Math.min(4, Math.max(0.5, next)));
    };

    return (
        <div
            className={cn(
                'flex size-full touch-none items-center justify-center overflow-hidden select-none',
                zoom > 1
                    ? panning
                        ? 'cursor-grabbing'
                        : 'cursor-grab'
                    : 'cursor-zoom-in',
            )}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={endPan}
            onPointerCancel={endPan}
            onWheel={onWheel}
            onDoubleClick={() => {
                setOffset({ x: 0, y: 0 });
                onZoomChange(zoom > 1 ? 1 : 2);
            }}
        >
            <img
                src={url}
                alt={alt}
                draggable={false}
                className={cn(
                    'max-h-full max-w-full rounded-sm object-contain shadow-2xl',
                    !panning &&
                        'transition-transform duration-200 ease-out motion-reduce:transition-none',
                )}
                style={{
                    transform: `scale(${zoom}) translate(${translate.x}px, ${translate.y}px) rotate(${rotation}deg)`,
                }}
            />
        </div>
    );
}
