import { reactive } from 'vue';

/**
 * What the app is busy with, for the preloader: reads (loading a page's data), writes (saving, deleting) and route
 * changes. api.ts counts requests; wireRouter() counts navigation (including lazy page chunks).
 */
export const activity = reactive({ reads: 0, writes: [] as string[], navigating: false, booted: false });

export const requestStarted = (method?: string) => {
    const m = (method || 'get').toLowerCase();
    if (m === 'get' || m === 'head') activity.reads++;
    else activity.writes.push(m);
};

export const requestFinished = (method?: string) => {
    const m = (method || 'get').toLowerCase();
    if (m === 'get' || m === 'head') {
        activity.reads = Math.max(0, activity.reads - 1);
    } else {
        const i = activity.writes.indexOf(m);
        if (i >= 0) activity.writes.splice(i, 1);
    }
};

export const wireRouter = (router: { beforeEach: Function; afterEach: Function; onError: Function }) => {
    router.beforeEach((_to: unknown, _from: unknown, next: () => void) => { activity.navigating = true; next(); });
    router.afterEach(() => { activity.navigating = false; });
    router.onError(() => { activity.navigating = false; });
};
