import { ref } from 'vue';
import axios from '@/api';

/**
 * FUN-13: the UTBMS task, activity and expense codes and LEDES timekeeper classes, for e-billing pickers. Fixed public
 * code sets, so they are loaded once per page load and shared.
 */
let pending = null;
const tasks = ref([]);
const activities = ref([]);
const expenses = ref([]);
const timekeeperClasses = ref([]);

export function useUtbms() {
    if (!pending) {
        pending = axios.get('/api/v1/utbms')
            .then(({ data }) => {
                tasks.value = data.data.tasks;
                activities.value = data.data.activities;
                expenses.value = data.data.expenses;
                timekeeperClasses.value = data.data.timekeeper_classes;
            })
            .catch(() => { pending = null; });
    }

    return { tasks, activities, expenses, timekeeperClasses };
}
