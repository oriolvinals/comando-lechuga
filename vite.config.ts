import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                google('Chivo', {
                    weights: [400, 500, 600, 700, 800, 900],
                    preload: [
                        { weight: 400 },
                        { weight: 700 },
                        { weight: 900 },
                    ],
                }),
                google('Chivo Mono', {
                    weights: [400, 500, 600, 700],
                    preload: [{ weight: 500 }, { weight: 700 }],
                }),
                google('Doto', {
                    weights: [700, 900],
                    preload: [{ weight: 900 }],
                }),
                google('Anton', {
                    weights: [400],
                }),
            ],
        }),
        inertia({ ssr: false }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
