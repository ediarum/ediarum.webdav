import axios, {AxiosError, type AxiosInstance, type InternalAxiosRequestConfig} from 'axios';
import type {MountContext} from './portal/contract';

/**
 * One axios instance per mount, rooted at `ctx.apiBase`. Requests are same-origin and authenticated
 * by the session cookie; axios echoes the `XSRF-TOKEN` cookie as `X-XSRF-TOKEN` by itself.
 *
 * - 401: the session is gone. Tell the portal, which unmounts the app and shows its login.
 * - 419: the CSRF token expired. `GET ping` gets a fresh XSRF-TOKEN cookie, then the request is
 *   retried once.
 */
export function createHttp(ctx: MountContext): AxiosInstance {
    const http = axios.create({
        baseURL: ctx.apiBase,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    http.interceptors.response.use(undefined, async (error: AxiosError) => {
        const status = error.response?.status;
        const config = error.config as (InternalAxiosRequestConfig & { wdRetried?: boolean }) | undefined;

        if (status === 401) {
            ctx.onUnauthorized();
        } else if (status === 419 && config && !config.wdRetried) {
            config.wdRetried = true;
            await http.get('/ping');
            return http.request(config);
        }

        return Promise.reject(error);
    });

    return http;
}

/** The message to show for a failed request: the backend's `{ error }`, then its `message`. */
export function errorMessage(e: unknown, fallback: string): string {
    if (axios.isAxiosError(e)) {
        const data = e.response?.data as { error?: string; message?: string } | undefined;
        return data?.error || data?.message || fallback;
    }
    return fallback;
}

/** Laravel's 422 `errors` object, or null if `e` is not a validation error. */
export function validationErrors(e: unknown): Record<string, string[]> | null {
    if (axios.isAxiosError(e) && e.response?.status === 422) {
        return (e.response.data as { errors?: Record<string, string[]> }).errors ?? {};
    }
    return null;
}
