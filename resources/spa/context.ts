import {inject, onBeforeUnmount, type InjectionKey} from 'vue';
import type {AxiosInstance} from 'axios';
import type {MountContext} from './portal/contract';

export interface StreamHandlers {
    /** A plain `data:` message. The backend ends a successful stream with `[DONE]`. */
    onMessage: (data: string) => void;
    /** An `event: app-error` message; the stream is closed already. */
    onAppError?: (message: string) => void;
    /** The connection failed and the session is still valid; the stream is closed already. */
    onConnectionLost?: () => void;
}

export interface StreamHandle {
    close: () => void;
}

export interface AppContext {
    ctx: MountContext;
    http: AxiosInstance;
    /**
     * Opens a server-sent events stream at `apiBase + path`. Closed automatically when the app is
     * unmounted; components should still close their own streams (see useStreams()).
     */
    openStream: (path: string, params: Record<string, string>, handlers: StreamHandlers) => StreamHandle;
}

export const APP_CONTEXT: InjectionKey<AppContext> = Symbol('webdav-app');

export function useApp(): AppContext {
    const app = inject(APP_CONTEXT);
    if (!app) throw new Error('useApp() called outside the webdav app');
    return app;
}

/**
 * `openStream` for a component: every stream it opened is closed when the component unmounts.
 */
export function useStreams(): AppContext['openStream'] {
    const {openStream} = useApp();
    const open = new Set<StreamHandle>();

    onBeforeUnmount(() => {
        open.forEach((s) => s.close());
        open.clear();
    });

    return (path, params, handlers) => {
        const handle = openStream(path, params, handlers);
        open.add(handle);
        return {
            close() {
                handle.close();
                open.delete(handle);
            },
        };
    };
}
