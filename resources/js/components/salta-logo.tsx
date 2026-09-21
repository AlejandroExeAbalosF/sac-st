import type { ImgHTMLAttributes } from 'react';

/**
 * Escudo de la Provincia de Salta.
 *
 * Es un PNG y no un SVG a propósito: este es el archivo oficial que circula
 * en el Ministerio y redibujarlo a vectores lo alejaría del original. Viene
 * a 703 px, de sobra para los 44 px a los que se usa, incluso en pantallas
 * de densidad doble.
 *
 * Decorativo por defecto (`alt=""`): donde se usa, el organismo va escrito
 * al lado en texto y anunciarlo duplicaría la lectura. Si alguna pantalla
 * lo usa solo, hay que pasarle un `alt` explícito.
 */
export default function SaltaLogo({
    alt = '',
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            width={703}
            height={703}
            {...props}
            src="/img/logo-salta.png"
            alt={alt}
        />
    );
}
