<?php

declare(strict_types=1);

return [

    /*
    | Las cabeceras se aplican siempre, también en desarrollo. En Bitácora
    | quedaron apagadas por defecto y eso hace que la CSP recién se pruebe
    | en producción, que es el peor momento para descubrir que rompe algo.
    | Vite necesita conectarse a su servidor de HMR, así que en local se
    | agregan sus orígenes en lugar de desactivar la política.
    */
    'enabled' => env('SECURITY_HEADERS_ENABLED', true),

    'headers' => [
        'x_frame_options' => env('SECURITY_HEADERS_X_FRAME_OPTIONS', 'DENY'),
        'referrer_policy' => env('SECURITY_HEADERS_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env(
            'SECURITY_HEADERS_PERMISSIONS_POLICY',
            'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
        ),

        /*
         * Aislamiento entre orígenes.
         *
         * - Opener: una pestaña de otro sitio que abra el sistema, o que el
         *   sistema abra, no conserva una referencia a esta ventana.
         * - Resource: ningún otro sitio puede incrustar las respuestas del
         *   sistema —imágenes de comprobantes, PDF, JSON— como recurso
         *   propio.
         *
         * No se manda Cross-Origin-Embedder-Policy: solo hace falta para el
         * aislamiento total (SharedArrayBuffer), que el sistema no usa, y con
         * `require-corp` arriesga el visor de PDF dentro del iframe. OWASP
         * ZAP lo marca como riesgo bajo; la decisión está tomada.
         */
        'cross_origin_opener_policy' => env('SECURITY_HEADERS_COOP', 'same-origin'),
        'cross_origin_resource_policy' => env('SECURITY_HEADERS_CORP', 'same-origin'),

        /*
         * Rutas que si pueden mostrarse dentro de un iframe del mismo
         * origen: la vista previa de comprobantes.
         *
         * El resto del sistema sigue con `frame-ancestors 'none'`, que es
         * lo que protege del clickjacking. Estas son documentos para
         * mirar, sin botones que apretar, y el dialogo que los muestra no
         * tiene otra forma de aislar su hoja de estilos.
         *
         * Los nombres tienen que existir: la lista estuvo vacia con un
         * 'receipts.preview' comentado que nunca fue el nombre real, y el
         * efecto era un iframe en blanco sin ningun error visible.
         */
        'same_origin_frame_routes' => [
            // El recibo de ingreso: su borrador y el comprobante emitido.
            'haberes.installments.receipt.preview',
            'recibos.view',
            // El recibo de egreso: su borrador. El comprobante ya emitido
            // sale por `recibos.view`, que es el mismo visor para los dos
            // tipos de recibo.
            'haberes.installments.disbursement.preview',
            // La documentación de pago: los borradores de la Orden y de su
            // nota, y los dos documentos ya emitidos.
            'haberes.installments.order.preview',
            'haberes.installments.pase.preview',
            'ordenes.view',
            'pases.view',
        ],
    ],

    'hsts' => [
        'enabled' => env('SECURITY_HEADERS_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HEADERS_HSTS_MAX_AGE', 31_536_000),
        'include_subdomains' => env('SECURITY_HEADERS_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => env('SECURITY_HEADERS_HSTS_PRELOAD', false),
    ],

    'csp' => [
        'enabled' => env('SECURITY_HEADERS_CSP_ENABLED', true),

        // En modo report-only la política se anuncia pero no bloquea:
        // sirve para estrenarla en producción sin romper nada.
        'report_only' => env('SECURITY_HEADERS_CSP_REPORT_ONLY', false),

        'directives' => [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'object-src' => ["'none'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:'],
            /*
             * Sin `'unsafe-inline'`: OWASP ZAP lo marca como riesgo medio y
             * es lo que el data center escanea antes de publicar. Los
             * `<style>` propios llevan el nonce de cada respuesta —lo agrega
             * `SecurityHeaders`, junto con los hashes de abajo— y las
             * vistas previas de documentos suman `style-src-attr` (ver
             * `same_origin_frame_routes`).
             */
            'style-src' => ["'self'"],
            'script-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'upgrade-insecure-requests' => true,
        ],

        /*
        | Los `<style>` que inyectan librerías sin soporte de nonce.
        |
        | Se permiten por su hash, que es el de su contenido exacto: una
        | versión nueva de la librería cambia el CSS y el hash deja de
        | coincidir. `resources/js/lib/csp-hashes.test.ts` los recalcula
        | desde `node_modules` y falla si no coinciden, así que actualizar
        | la librería rompe un test y no los estilos en silencio.
        */
        'style_hashes' => [
            // sonner 2.0.7: los estilos de los avisos, al cargar el módulo.
            'sonner' => "'sha256-CIxDM5jnsGiKqXs2v7NKCY5MzdR9gu6TtiMJrDw29AY='",
            // input-otp: un `<style>` vacío que después llena por CSSOM,
            // que la CSP no controla. Es el hash de la cadena vacía.
            'input-otp' => "'sha256-47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU='",
        ],

        /*
        | Directivas a las que se les agrega el origen del servidor de
        | desarrollo de Vite mientras está corriendo.
        |
        | El origen NO se escribe acá: se lee de `public/hot`, que es el
        | archivo donde el propio Vite anota dónde quedó escuchando. Una
        | lista fija se desincroniza —Vite puede levantar en `localhost`,
        | en `127.0.0.1`, en `[::1]` o en otro puerto si el 5173 está
        | ocupado— y el síntoma es una pantalla en blanco con la consola
        | llena de bloqueos de CSP.
        |
        | Nada de esto llega a producción: ahí `public/hot` no existe.
        */
        'vite_dev_directives' => [
            'script-src',
            'style-src',
            'font-src',
            'img-src',
            'connect-src',
        ],
    ],

];
