import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';
import cssInjectedByJs from 'vite-plugin-css-injected-by-js';

/**
 * Builds the admin SPA as ediarum.portal loads it: an ES module public/apps/webdav/embed.js that
 * exports `mount` and `contractVersion`, plus hashed chunks in assets/. The CSS (scoped to
 * .wd-root, see build-tools/postcss-scope-wd-root.js) is injected by embed.js itself, since the
 * portal only imports the module.
 *
 * `base` is the public URL, which the reverse proxy forwards unchanged to Laravel's
 * public/apps/webdav/; lazy-loaded chunks are fetched from it.
 * See planning/portal-integration.md.
 */
export default defineConfig({
    base: '/apps/webdav/',
    publicDir: false,
    plugins: [
        vue({
            // The platform's header controls (<ediarum-home>, <ediarum-user-menu>) are custom
            // elements the platform defines before mounting, not Vue components.
            template: {compilerOptions: {isCustomElement: (tag) => tag.startsWith('ediarum-')}},
        }),
        cssInjectedByJs(),
    ],
    build: {
        outDir: 'public/apps/webdav',
        emptyOutDir: true,
        rollupOptions: {
            input: 'resources/spa/embed.ts',
            // Without this, the bundler may drop the entry's exports.
            preserveEntrySignatures: 'exports-only',
            output: {
                entryFileNames: 'embed.js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
