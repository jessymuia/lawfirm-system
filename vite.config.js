import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

/*
 * ENG-13: the web app is built with Vite (was Laravel Mix / webpack). Output goes to public/build with a manifest that
 * Blade's @vite() reads; every file name carries a content hash, so the service worker (FUN-19) can cache them forever.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/src/assets/sass/app.scss', 'resources/js/src/main.js'],
            refresh: true,
        }),
        vue({
            template: {
                // asset URLs in templates stay as written (they point at /public, as they did under Mix)
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/src', import.meta.url)),
            '@themeConfig': fileURLToPath(new URL('./resources/js/theme.config.js', import.meta.url)),
            vue: 'vue/dist/vue.esm-bundler.js',
        },
    },
    css: {
        preprocessorOptions: {
            scss: {
                api: 'modern-compiler',
                loadPaths: ['node_modules', 'resources/js/src/assets'],
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'slash-div'],
            },
        },
    },
    build: {
        chunkSizeWarningLimit: 1500,
    },
});
