import { ref } from 'vue';
import axios from '../api';

/**
 * UI-17: debounced existence check against a uniqueness the backend
 * already enforces (see DATA-5's composite unique-with-deleted_at
 * migration and each controller's check*Exists() endpoint) — shown as a
 * non-blocking warning hint. Submission is never stopped here; the
 * server stays the actual authority and returns its own validation error
 * if the person submits a genuine duplicate anyway.
 *
 * Usage:
 *   const { hint, check, clearHint } = useDuplicateHint('/api/v1/clients-check-phone', 'phone_number');
 *   // on @input of the field:
 *   check(client.value.phone_number, client.value.id);
 */
export function useDuplicateHint(checkUrl, fieldParam) {
    const hint = ref('');
    let debounceTimer = null;
    let requestSeq = 0;

    const clearHint = () => {
        hint.value = '';
    };

    const check = (value, excludeId) => {
        clearTimeout(debounceTimer);
        hint.value = '';

        if (!value) return;

        debounceTimer = setTimeout(() => {
            const seq = ++requestSeq;
            const params = { [fieldParam]: value };
            if (excludeId) params.exclude_id = excludeId;

            axios.get(checkUrl, { params })
                .then((response) => {
                    if (seq !== requestSeq) return; // a newer keystroke's request already landed
                    hint.value = response.data.data?.exists ? 'Already in use by another record.' : '';
                })
                .catch(() => {
                    // Silent — this is a courtesy hint, not a hard validation
                    // path, so a failed check just means no hint is shown.
                });
        }, 400);
    };

    return { hint, check, clearHint };
}
