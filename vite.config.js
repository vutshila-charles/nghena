import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const proxyTarget = loadEnv(mode, process.cwd(), '').LARAVEL_DEV_SERVER || 'http://127.0.0.1:8000';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
            vue(),
        ],
        server: {
            proxy: {
                '/api': { target: proxyTarget, changeOrigin: false },
                '/sanctum': { target: proxyTarget, changeOrigin: false },
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
