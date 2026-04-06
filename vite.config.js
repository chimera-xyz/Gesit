import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.APP_URL ? new URL(env.APP_URL) : null;
    const devServerPort = Number(env.VITE_DEV_SERVER_PORT || 5173);
    const devServerHost = env.VITE_DEV_SERVER_HOST || appUrl?.hostname || '127.0.0.1';
    const devServerProtocol = env.VITE_DEV_SERVER_HTTPS === 'true' ? 'https' : 'http';
    const devServerUrl = `${devServerProtocol}://${devServerHost}:${devServerPort}`;

    return {
        plugins: [
            vue(),
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: devServerPort,
            strictPort: true,
            origin: devServerUrl,
            cors: true,
            hmr: {
                host: devServerHost,
                port: devServerPort,
                protocol: devServerProtocol === 'https' ? 'wss' : 'ws',
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
