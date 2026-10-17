import { reactive } from 'vue';
import axios from '@/api';

/**
 * PLAT-1 / PLAT-3: what this firm's plan includes (entitlements) and which feature flags are on. The server enforces both
 * itself; the web app only uses them to hide what the firm cannot use, so nobody clicks into a "not on your plan" error.
 * Loaded once per page load. Until it has answered, everything is treated as available (never hide on a guess).
 */
const state = reactive({ loaded: false, features: {}, flags: {}, plan: '', readOnly: false });
let pending = null;

const signedIn = () => {
    try { return Boolean(localStorage.getItem('permissions')); } catch { return false; }
};

export const loadFeatures = (force = false) => {
    if (!signedIn()) { pending = null; return Promise.resolve(); }   // never ask while signed out (a 401 would show "session expired")
    if (pending && !force) return pending;
    pending = Promise.all([
        axios.get('/api/v1/entitlements').then((r) => r.data).catch(() => null),
        axios.get('/api/v1/flags').then((r) => r.data?.data).catch(() => null),
    ]).then(([ent, flags]) => {
        if (ent) {
            state.features = ent.features || {};
            state.plan = ent.plan || '';
            state.readOnly = Boolean(ent.read_only);
        }
        if (flags) state.flags = flags;
        state.loaded = Boolean(ent);
    });
    return pending;
};

export function useFeatures() {
    loadFeatures();
    return {
        state,
        /** true unless the plan says this feature is off */
        entitled: (name) => !state.loaded || state.features[name] !== false,
        /** true unless the flag is registered and off */
        flag: (name) => state.flags[name] !== false,
    };
}
