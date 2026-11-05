import type {App} from 'vue';
import type {MountContext} from './portal/contract';
import {APP_CONTEXT, type AppContext, type StreamHandle, type StreamHandlers} from './context';
import {createHttp} from './http';

/**
 * Wires the app to one mount: the per-mount HTTP client and SSE streams. Returns the cleanup for
 * everything that outlives a component (open streams).
 */
export function install(app: App, ctx: MountContext): () => void {
    const http = createHttp(ctx);
    const streams = new Set<EventSource>();
    let disposed = false;

    function openStream(path: string, params: Record<string, string>, handlers: StreamHandlers): StreamHandle {
        const query = new URLSearchParams(params).toString();
        const source = new EventSource(`${ctx.apiBase}${path}${query ? `?${query}` : ''}`);
        streams.add(source);
        let closed = false;

        const close = () => {
            closed = true;
            source.close();
            streams.delete(source);
        };

        source.onmessage = (event) => {
            if (!closed) handlers.onMessage(event.data);
        };

        source.addEventListener('app-error', (event) => {
            if (closed) return;
            close();
            handlers.onAppError?.((event as MessageEvent).data || 'Unknown server error');
        });

        // EventSource doesn't expose the status code. A cheap authenticated request tells a lost
        // session (401 → the http interceptor calls onUnauthorized) from a lost connection.
        source.onerror = () => {
            if (closed) return;
            close();
            http.get('/ping')
                .then(() => !disposed && handlers.onConnectionLost?.())
                .catch((e) => {
                    if (!disposed && e?.response?.status !== 401) handlers.onConnectionLost?.();
                });
        };

        return {close};
    }

    const context: AppContext = {ctx, http, openStream};
    app.provide(APP_CONTEXT, context);

    return () => {
        disposed = true;
        streams.forEach((s) => s.close());
        streams.clear();
    };
}
