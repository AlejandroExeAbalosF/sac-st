import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { copyFileSync, cpSync, mkdirSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { defineConfig, type Plugin } from 'vite';

/*
 * pdf.js corre su trabajo pesado en un Web Worker, y un Worker solo se
 * puede crear desde el mismo origen que la página. En desarrollo los
 * módulos los sirve Vite desde otro puerto, así que el worker no puede
 * salir de ahí: se copia a `public/vendor/pdfjs/`, que sirve Laravel en
 * desarrollo y nginx en producción, y queda cubierto por `script-src
 * 'self'` sin abrir la CSP. Con él van los recursos que pdf.js pide por
 * `fetch` —mapas de caracteres, tipografías estándar, perfiles de color y
 * los decodificadores de JPEG 2000 y JBIG2 de los PDF escaneados—.
 *
 * No es un `postinstall`: `pnpm-workspace.yaml` apaga todos los scripts de
 * instalación. Corre al arrancar Vite y al compilar, y sale siempre de la
 * versión de `pdfjs-dist` que trae `react-pdf`: si el worker y la librería
 * no son de la misma versión, pdf.js se niega a abrir el documento.
 *
 * El worker se copia como `.js`: un `.mjs` depende de que el servidor lo
 * declare JavaScript, y un Worker de módulo con otro tipo no carga.
 */
function pdfjsAssets(): Plugin {
    return {
        name: 'sacst:pdfjs-assets',
        buildStart() {
            const fromReactPdf = createRequire(
                createRequire(import.meta.url).resolve('react-pdf'),
            );
            const source = dirname(
                fromReactPdf.resolve('pdfjs-dist/package.json'),
            );
            const target = join(import.meta.dirname, 'public/vendor/pdfjs');

            rmSync(target, { recursive: true, force: true });
            mkdirSync(target, { recursive: true });

            for (const folder of ['cmaps', 'standard_fonts', 'iccs', 'wasm']) {
                cpSync(join(source, folder), join(target, folder), {
                    recursive: true,
                });
            }

            copyFileSync(
                join(source, 'build/pdf.worker.min.mjs'),
                join(target, 'pdf.worker.min.js'),
            );
        },
    };
}

/*
 * Dentro de un contenedor, la interfaz en la que Vite escucha y la
 * dirección por la que el navegador lo alcanza dejan de ser la misma cosa:
 * escucha en `0.0.0.0` —el `localhost` del contenedor no sale de ahí— pero
 * tiene que anunciar `localhost:5173`, que es lo que el navegador del
 * anfitrión puede resolver. Ese anuncio es el que termina en `public/hot`,
 * de donde la CSP saca el origen que habilita.
 *
 * Fuera de Docker las tres variables no existen y todo se comporta igual
 * que antes.
 */
const devHost = process.env.VITE_DEV_HOST ?? 'localhost';
const devOrigin = process.env.VITE_DEV_ORIGIN;
const devPolling = process.env.VITE_DEV_POLLING === '1';
const appOrigin = process.env.APP_URL ?? 'http://localhost:8000';

export default defineConfig({
    server: {
        /*
         * Sin esto Vite queda escuchando en `[::1]` y lo anota así en
         * `public/hot`. La gramática de Content-Security-Policy no admite
         * literales IPv6 entre corchetes: el navegador descarta la fuente
         * con "invalid source" y bloquea todos los módulos, las hojas de
         * estilo y las tipografías del servidor de desarrollo. El síntoma
         * es una pantalla en blanco.
         */
        host: devHost,
        ...(devOrigin
            ? {
                  origin: devOrigin,
                  hmr: { host: new URL(devOrigin).hostname },
                  /*
                   * Declarar `origin` hace que Vite devuelva ese mismo valor
                   * en `Access-Control-Allow-Origin`, y quien pide los
                   * módulos es la aplicación, que está en otro puerto. Sin
                   * esta línea el navegador rechaza todo por CORS y la
                   * pantalla queda en blanco: mismo síntoma que la CSP, otra
                   * causa, y solo se distinguen mirando la consola.
                   */
                  cors: { origin: appOrigin },
              }
            : {}),
        /*
         * Los eventos de cambio de archivo del anfitrión no cruzan un bind
         * mount de Docker: está medido, sin sondeo Vite no se entera de
         * nada y hay que recargar a mano. El intervalo es explícito porque
         * el default de 100 ms sondea miles de archivos diez veces por
         * segundo sobre un sistema de archivos que ya de por sí es lento;
         * un segundo no se nota al editar y baja mucho el consumo.
         */
        /*
         * Precalentar al arrancar, solo en Docker. Wayfinder genera 133
         * archivos de rutas y acciones, y el layout los importa siempre:
         * sobre un bind mount, donde cada archivo cuesta cerca de un
         * segundo, eso son varios minutos la primera vez que se abre una
         * pantalla. El trabajo es el mismo, pero ocurre mientras el
         * contenedor levanta y no mientras alguien mira una pantalla en
         * blanco preguntándose si se colgó. Después queda en memoria y las
         * respuestas pasan a ser de milisegundos.
         */
        ...(devOrigin
            ? {
                  warmup: {
                      clientFiles: [
                          './resources/js/app.tsx',
                          './resources/js/routes/**/*.ts',
                          './resources/js/actions/**/*.ts',
                      ],
                  },
              }
            : {}),
        ...(devPolling
            ? {
                  watch: {
                      usePolling: true,
                      interval: 1000,
                      binaryInterval: 3000,
                  },
              }
            : {}),
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            /*
             * Las tipografías se descargan en el build y se sirven desde
             * el propio dominio: ningún pedido a un CDN externo, que es lo
             * que exige la CSP y lo que corresponde a un sistema interno
             * del Ministerio sin salida a internet.
             */
            fonts: [
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        pdfjsAssets(),
    ],
});
