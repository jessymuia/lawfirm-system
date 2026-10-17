<template>
    <div class="layout-px-spacing">
        <div class="page-header-modern no-print">
            <h1 class="page-title">Court diary</h1>
            <p class="page-subtitle">Every hearing, day by day, in the matters you can see.</p>
        </div>

        <form class="filters no-print" @submit.prevent="load">
            <label>From <input v-model="q.from" type="date" class="form-control" /></label>
            <label>To <input v-model="q.to" type="date" class="form-control" /></label>
            <label>Court
                <select v-model="q.court_id" class="form-control">
                    <option value="">All courts</option>
                    <option v-for="c in courts" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </label>
            <label>Advocate
                <select v-model="q.assigned_to" class="form-control">
                    <option value="">Everyone</option>
                    <option v-for="u in people" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </label>
            <button type="submit" class="btn btn-primary">Show</button>
            <button type="button" class="btn btn-outline-secondary" @click="print">Print</button>
        </form>

        <div class="elevated-card diary">
            <h2 class="print-only">{{ firm }} · Court diary {{ q.from }} to {{ q.to }}</h2>
            <p v-if="!loading && !Object.keys(days).length" class="hint">No hearings in this period.</p>
            <section v-for="(rows, day) in days" :key="day" class="day">
                <h3>{{ dayLabel(day) }}</h3>
                <table class="table table-sm">
                    <thead><tr><th>Time</th><th>Court / room</th><th>Matter</th><th>Client</th><th>Type</th><th>Before</th><th>Advocate</th></tr></thead>
                    <tbody>
                        <tr v-for="h in rows" :key="h.id">
                            <td>{{ h.start_time || '—' }}</td>
                            <td>{{ h.court }}<small v-if="h.courtroom" class="muted"> · {{ h.courtroom }}</small></td>
                            <td><router-link :to="`/view-case?case=${h.case_id}#hearings`">{{ h.case_number }}</router-link><small v-if="h.efiling_reference" class="muted"> · {{ h.efiling_reference }}</small></td>
                            <td>{{ h.client }}</td>
                            <td>{{ h.type }}</td>
                            <td>{{ h.judicial_officer || '' }}</td>
                            <td>{{ h.lawyer }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>

<script setup>
/** FUN-16: the court diary (list-hearings), printable. */
import { onMounted, ref } from 'vue';
import axios, { fetchAllPages } from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Court diary' });
const { showMessage } = useToast();
const iso = (d) => d.toISOString().slice(0, 10);
const today = new Date();
const q = ref({ from: iso(today), to: iso(new Date(today.getTime() + 13 * 86400000)), court_id: '', assigned_to: '' });
const days = ref({});
const courts = ref([]);
const people = ref([]);
const loading = ref(false);
const firm = document.getElementById('app')?.dataset.firmName || '';

const dayLabel = (d) => new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const load = () => {
    loading.value = true;
    const params = Object.fromEntries(Object.entries(q.value).filter(([, v]) => v !== ''));
    axios.get('/api/v1/court-diary', { params })
        .then(({ data }) => { days.value = data.data.days; })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not load the diary.', 'error'))
        .finally(() => { loading.value = false; });
};
const print = () => window.print();

onMounted(() => {
    load();
    fetchAllPages('/api/v1/courts').then((rows) => { courts.value = rows; }).catch(() => {});
    fetchAllPages('/api/v1/users').then((rows) => { people.value = rows; }).catch(() => {});
});
</script>

<style lang="scss" scoped>
.filters { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; }
.filters label { font-size: 13px; display: flex; flex-direction: column; gap: 4px; }
.day h3 { font-size: 15px; margin: 18px 0 6px; }
.hint, .muted { font-size: 13px; color: var(--text-secondary, #64748b); }
.print-only { display: none; }
@media print {
    .no-print { display: none !important; }
    .print-only { display: block; font-size: 16px; }
    .diary { box-shadow: none; border: 0; }
}
</style>
