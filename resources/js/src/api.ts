import axios, { type AxiosError, type AxiosResponse } from 'axios';
import type { ApiResponse, Paginated } from './api/types.generated';
import { requestStarted, requestFinished } from './utils/activity';

export type * from './api/types.generated';   // ENG-15: the API's shapes, generated from the server's Resources

const axiosInstance = axios.create({
    withCredentials: true, // Send the Sanctum session cookie with every request
    withXSRFToken: true,   // Let axios read XSRF-TOKEN cookie and set the header automatically
});

// UI-25: a 401 mid-session used to hard-navigate to /login, which threw
// away whatever the user was doing (an open modal, unsaved form state,
// the current route) with no explanation — it just looked like the app
// broke. Now we emit a DOM event instead and let a modal (mounted once
// in App.vue) offer to re-authenticate in place. The /api/v1/login request
// itself still 401s on bad credentials, so it's excluded here — that
// case is handled inline by the login form, not by this global modal.
export const AUTH_EXPIRED_EVENT = 'auth:expired';

// PLAT-2 / PLAT-13: the API says when the firm is (about to be) read-only, in an X-License-Notice header on every
// response and as 403 {code: license_expired | trial_expired} on a refused write. Both become one event the
// account-notice banner listens to; a refused write is not a permissions problem, so it never goes to the 403 page.
export const ACCOUNT_NOTICE_EVENT = 'account:notice';
const READ_ONLY_CODES = ['license_expired', 'trial_expired'];
export interface AccountNotice { message: string; readOnly: boolean; code?: string }
const announce = (detail: AccountNotice) => window.dispatchEvent(new CustomEvent<AccountNotice>(ACCOUNT_NOTICE_EVENT, { detail }));

// the preloader counts what is in flight (a page's reads, a save, a delete)
axiosInstance.interceptors.request.use((config) => { requestStarted(config.method); return config; });
axiosInstance.interceptors.response.use(
    (response: AxiosResponse) => { requestFinished(response.config?.method); return response; },
    (error: AxiosError) => { requestFinished(error.config?.method); return Promise.reject(error); },
);

axiosInstance.interceptors.response.use(
    (response: AxiosResponse) => {
        const notice = response.headers?.['x-license-notice'];
        if (notice) announce({ message: notice, readOnly: false });
        return response;
    },
    (error: AxiosError<Partial<ApiResponse<unknown>>> & { config?: { handles403?: boolean } }) => {
        const code = error.response?.data?.code;
        if (error.response && error.response.status === 403 && code && READ_ONLY_CODES.includes(code)) {
            announce({ message: error.response.data?.message ?? '', readOnly: true, code });
            return Promise.reject(error);
        }
        const isLoginRequest = error.config && typeof error.config.url === 'string' && (error.config.url.includes('/api/v1/login') || error.config.url.includes('/api/v1/session'));

        if (error.response && error.response.status === 401 && !isLoginRequest) {
            // Session expired or not authenticated. No token/user data is
            // kept client-side, so there's nothing to clear — just let the
            // person know and offer a way back in without losing context.
            window.dispatchEvent(new CustomEvent(AUTH_EXPIRED_EVENT));
        } else if (error.response && error.response.status === 403 && error.response.data?.code === 'mfa_enrollment_required') {
            // SEC-12: the firm requires two-factor and this person has not enrolled; that is the only page they may use.
            if (window.location.pathname !== '/auth/two-factor') window.location.href = '/auth/two-factor';
        } else if (error.response && error.response.status === 403 && !isLoginRequest && !error.config?.handles403) {
            // A 403 at sign-in (e.g. sso_required) is an answer for the login form, not a page change; a caller that
            // shows its own answer (e.g. a plan without the feature) passes `{ handles403: true }`.
            window.location.href = '/pages/error403';
        }
        return Promise.reject(error);
    }
);

// Fetch the CSRF cookie once before any stateful (session-authenticated)
// request is made. Required by Sanctum's SPA cookie authentication.
export const ensureCsrfCookie = () => axios.get('/sanctum/csrf-cookie', { withCredentials: true });

export default axiosInstance;

// ENG-16: list endpoints are always paginated. For a bounded set the UI genuinely shows in full (one case's
// hearings, one invoice's payments, one month of the calendar) walk the pages, 100 rows at a time. `pick` finds the
// paginator in the response body when it is not the top-level `data` (e.g. the trust ledger's `data.transactions`).
// Capped at `maxPages` so a mistake can never turn into an unbounded crawl.
export const fetchAllPages = async <T = unknown, B = unknown>(
    url: string,
    params: Record<string, unknown> = {},
    { pick = (body: ApiResponse<any>) => body.data as Paginated<T>, maxPages = 50 }: { pick?: (body: ApiResponse<any>) => Paginated<T>; maxPages?: number } = {},
): Promise<{ rows: T[]; body: B | null }> => {
    const rows: T[] = [];
    let lastBody: B | null = null;
    for (let page = 1; page <= maxPages; page++) {
        const response = await axiosInstance.get<ApiResponse<any>>(url, { params: { ...params, page, per_page: 100 } });
        lastBody = response.data.data;
        const paginator = pick(response.data);
        rows.push(...(paginator.data || []));
        if (!paginator.last_page || page >= paginator.last_page) break;
    }
    return { rows, body: lastBody };
};

// ENG-23: reports and the dashboard may be read from a replica. It says how many seconds behind its figures were
// (X-Data-Lag); a screen shows a note once that is worth mentioning. null = up to date (or not known).
export const SHOW_LAG_AFTER_SECONDS = 60;
export const dataLag = (response?: AxiosResponse): number | null => {
    const lag = Number(response?.headers?.['x-data-lag']);
    return Number.isFinite(lag) && lag >= SHOW_LAG_AFTER_SECONDS ? lag : null;
};
export const lagText = (seconds: number): string => (seconds < 120 ? 'about a minute' : `about ${Math.round(seconds / 60)} minutes`);

// ENG-22: a document goes straight to the firm's bucket when it has one: the API reserves the upload and answers a
// signed S3 form (this key, this type, exactly this size), the file is posted to the bucket, then the API checks it and
// files it. Without a bucket (409 direct_uploads_unavailable), or for a file the browser types wrongly, it is uploaded
// through the API as before. Resolves with the filed document.
export interface DirectUploadSlot { id: string; url: string; fields: Record<string, string>; expires_at: string }

export const uploadDocument = async ({ caseId, title, file, groupId = null }: { caseId: number | string; title: string; file: File; groupId?: number | null }) => {
    let slot: DirectUploadSlot | null = null;
    if (file.type) {
        try {
            const { data } = await axiosInstance.post<ApiResponse<DirectUploadSlot>>('/api/v1/documents/uploads', {
                case_id: caseId, title, filename: file.name, mimetype: file.type, size: file.size, document_group_id: groupId,
            });
            slot = data.data;
        } catch (e) {
            const error = e as AxiosError<ApiResponse<unknown>>;
            // no bucket, or a type label the browser got wrong (the API path reads the content instead)
            const fallBack = error.response?.data?.code === 'direct_uploads_unavailable' || error.response?.data?.errors?.mimetype;
            if (!fallBack) throw error;
        }
    }
    if (slot) {
        const form = new FormData();
        Object.entries(slot.fields).forEach(([k, v]) => form.append(k, v));
        form.append('file', file);   // S3 needs the file last
        // a plain request: the bucket is another origin and must not receive the app's cookies or XSRF header
        const sent = await fetch(slot.url, { method: 'POST', body: form });
        if (!sent.ok) throw new Error('The file could not be sent to storage. Please try again.');
        const { data } = await axiosInstance.post<ApiResponse<unknown>>(`/api/v1/documents/uploads/${slot.id}/complete`);
        return data.data;
    }
    const form = new FormData();
    form.append('case_id', String(caseId));
    form.append('title', title);
    if (groupId) form.append('document_group_id', String(groupId));
    form.append('file', file);
    const { data } = await axiosInstance.post<ApiResponse<unknown>>('/api/v1/documents', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    return data.data;
};
