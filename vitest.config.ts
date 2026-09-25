import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/*
 * La configuración de los tests del front, aparte de la del build.
 *
 * Vitest levanta un servidor de Vite, y con `vite.config.ts` arrastraba los
 * plugins del build: laravel-vite-plugin se niega a arrancar un servidor en
 * un CI («You should not run the Vite HMR server in CI environments»), y
 * Wayfinder corre `php artisan` en cada arranque. Los tests no necesitan
 * nada de eso: solo resolver `@/` y entender TSX.
 *
 * Vitest usa este archivo antes que `vite.config.ts`.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.{ts,tsx}'],
        environment: 'node',
    },
});
