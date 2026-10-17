import { ref } from 'vue';
import axios from '@/api';

/**
 * FUN-18: the firm's offices and practice groups for form dropdowns and list filters. Loaded once per page load and
 * shared; an empty list (a firm that has not set any up) simply hides the fields.
 */
let pending = null;
const offices = ref([]);
const practiceGroups = ref([]);

export function useOrganisation() {
    if (!pending) {
        pending = axios.get('/api/v1/organisation/options')
            .then(({ data }) => {
                offices.value = data.data.offices;
                practiceGroups.value = data.data.practice_groups;
            })
            .catch(() => { pending = null; });
    }

    return { offices, practiceGroups, reload: () => { pending = null; return useOrganisation(); } };
}
