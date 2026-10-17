const STORAGE_KEY = 'recently-viewed';
const MAX_ITEMS = 8;

/**
 * UI-8: a small localStorage ring buffer of the cases/clients a person
 * has actually opened recently, surfaced in the Cmd/Ctrl-K palette
 * above search results (see global-search.vue) — this is a personal,
 * per-browser convenience, not synced anywhere server-side.
 */
export function useRecentlyViewed() {
    const getAll = () => {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        } catch {
            return [];
        }
    };

    const addRecentItem = ({ type, id, title, subtitle }) => {
        if (!type || !id) return;

        const existing = getAll().filter((item) => !(item.type === type && item.id === id));
        existing.unshift({ type, id, title, subtitle, viewedAt: Date.now() });

        localStorage.setItem(STORAGE_KEY, JSON.stringify(existing.slice(0, MAX_ITEMS)));
    };

    return { getAll, addRecentItem };
}
