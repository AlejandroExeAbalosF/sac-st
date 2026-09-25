{{--
    Marco común de las pantallas de error.

    Va con los estilos escritos acá adentro y sin `@vite`: una de estas
    pantallas es justamente la que aparece cuando algo se rompió, y hacerla
    depender del build es apostar a que el build está sano. Del mismo modo,
    la marca entra por `/favicon.svg`, que ya se sirve estático: si no
    carga, la pantalla igual se lee.

    Variables: $codigo, $titulo, $detalle y, opcional, $volver / $volverA.
--}}
<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $titulo }} · SAC</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <style nonce="{{ Vite::cspNonce() }}">
            :root {
                --azul: #1e3a5f;
                --fondo: #f5f7fa;
                --tinta: #1c2536;
                --tinta-2: #5b6779;
                --borde: #e2e6ec;
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100dvh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background: var(--fondo);
                color: var(--tinta);
                font-family: Inter, ui-sans-serif, system-ui, -apple-system,
                    "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
            }

            .tarjeta {
                width: 100%;
                max-width: 30rem;
                background: #fff;
                border: 1px solid var(--borde);
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 1px 2px rgba(16, 24, 40, .04),
                    0 8px 24px rgba(16, 24, 40, .06);
            }

            /* El mismo filete que ata la tarjeta del ingreso al panel azul. */
            .filete { height: 4px; background: var(--azul); }

            .cuerpo { padding: 32px; }

            .marca {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 28px;
            }

            .marca img { width: 36px; height: 36px; display: block; }

            .marca b {
                font-size: 15px;
                font-weight: 700;
                letter-spacing: .02em;
            }

            .marca span {
                display: block;
                font-size: 11px;
                font-weight: 400;
                color: var(--tinta-2);
            }

            .codigo {
                font-size: 11px;
                font-weight: 600;
                letter-spacing: .16em;
                text-transform: uppercase;
                color: var(--tinta-2);
            }

            h1 {
                margin: 8px 0 0;
                font-size: 22px;
                line-height: 1.3;
                font-weight: 600;
                letter-spacing: -.01em;
            }

            p { margin: 10px 0 0; font-size: 14px; color: var(--tinta-2); }

            .acciones { margin-top: 28px; }

            .boton {
                display: inline-block;
                padding: 10px 18px;
                border-radius: 8px;
                background: var(--azul);
                color: #fff;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
            }

            .boton:hover { background: #24466f; }

            .boton:focus-visible {
                outline: 2px solid var(--azul);
                outline-offset: 2px;
            }

            .pie {
                margin-top: 28px;
                padding-top: 16px;
                border-top: 1px solid var(--borde);
                font-size: 11.5px;
                color: var(--tinta-2);
            }
        </style>
    </head>
    <body>
        <main class="tarjeta">
            <div class="filete"></div>

            <div class="cuerpo">
                <div class="marca">
                    <img src="/favicon.svg" alt="">
                    <div>
                        <b>SAC</b>
                        <span>Sistema administrativo contable</span>
                    </div>
                </div>

                <p class="codigo">Error {{ $codigo }}</p>
                <h1>{{ $titulo }}</h1>
                <p>{{ $detalle }}</p>

                <div class="acciones">
                    <a class="boton" href="{{ $volverA ?? url('/') }}">
                        {{ $volver ?? 'Volver al sistema' }}
                    </a>
                </div>

                <p class="pie">
                    {{ __('errores.ayuda', ['codigo' => $codigo]) }}
                </p>
            </div>
        </main>
    </body>
</html>
