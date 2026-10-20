import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

// Identificador de esta compilación (fecha y hora): la app lo lleva dentro y el servidor
// lo publica en build/version.json; cuando difieren, la app sabe que hay una versión nueva.
const stamp = new Date();
const two = (n) => String(n).padStart(2, '0');
const BUILD = `${stamp.getFullYear()}.${two(stamp.getMonth() + 1)}.${two(stamp.getDate())}-${two(stamp.getHours())}${two(stamp.getMinutes())}${two(stamp.getSeconds())}`;

const versionFile = {
    name: 'servipos-version',
    apply: 'build',
    generateBundle() {
        this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ build: BUILD }) });
    },
};

export default defineConfig({
    define: { __APP_BUILD__: JSON.stringify(BUILD) },
    plugins: [
        versionFile,
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
            fonts: [
                bunny('Plus Jakarta Sans', { weights: [400, 500, 600, 700] }),
                bunny('Urbanist', { weights: [600, 700, 800] }),
                // Respaldo redondeado de Arial Rounded MT Bold, la letra de la presentación del banco.
                bunny('Nunito', { weights: [800] }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        rolldownOptions: {
            output: {
                postBanner: '/*! ServiPos Tickets · Diseñado y desarrollado por Raúl Noguera · 2026 */',
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
