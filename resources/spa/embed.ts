/**
 * Entry module for ediarum.portal (built to public/apps/webdav/embed.js, served as
 * /apps/webdav/embed.js); see planning/portal-integration.md.
 */
import {createApp} from 'vue';
import {createRouter, createWebHistory} from 'vue-router';
import {CONTRACT_VERSION, type Mount} from './portal/contract';
import App from './App.vue';
import {routes} from './routes';
import {install} from './install';
import './styles.css';

export {CONTRACT_VERSION as contractVersion};

export const mount: Mount = (el, ctx) => {
    const router = createRouter({history: createWebHistory(ctx.routerBase), routes});
    const app = createApp(App);
    const uninstall = install(app, ctx);
    app.use(router);
    app.mount(el);

    return () => {
        app.unmount();
        uninstall();
        // Removes the router's popstate listener; the next mount creates a fresh router.
        router.options.history.destroy();
    };
};
