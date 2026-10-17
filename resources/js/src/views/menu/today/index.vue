<template>
    <div class="today">
        <header class="top">
            <div>
                <h1>Today</h1>
                <p class="hint">{{ dateLabel }}<span v-if="offline" class="offline"> · offline, showing the last copy</span></p>
            </div>
            <button v-if="installable" type="button" class="btn btn-primary btn-sm" @click="install">Install app</button>
        </header>

        <p v-if="loading" class="hint">Loading…</p>
        <template v-else>
            <section v-for="day in ['today', 'tomorrow']" :key="day">
                <h2>{{ day === 'today' ? 'My hearings today' : 'Tomorrow' }}</h2>
                <p v-if="!byDay(day).length" class="hint">No hearings.</p>
                <article v-for="h in byDay(day)" :key="h.id" class="hearing">
                    <div class="when">{{ h.start_time || 'Any time' }}</div>
                    <div class="what">
                        <strong>{{ h.case_number }}</strong> · {{ h.type }}
                        <div>{{ h.client }}</div>
                        <div class="hint">{{ h.court }}<template v-if="h.courtroom"> · {{ h.courtroom }}</template><template v-if="h.judicial_officer"> · {{ h.judicial_officer }}</template></div>
                        <div v-if="h.outcome" class="outcome">Outcome: {{ h.outcome }}</div>
                    </div>
                    <div class="buttons">
                        <a v-if="h.client_phone" class="btn btn-outline-secondary" :href="`tel:${h.client_phone}`">Call client</a>
                        <router-link class="btn btn-outline-secondary" :to="`/view-case?case=${h.case_id}`">Matter</router-link>
                        <button v-if="data.can_record && day === 'today' && !offline" type="button" class="btn btn-primary" @click="startRecord(h)">Record outcome</button>
                    </div>

                    <form v-if="recording && recording.id === h.id" class="record" @submit.prevent="save">
                        <label>What happened<textarea v-model="form.outcome" rows="3" required maxlength="5000" class="form-control"></textarea></label>
                        <div class="next">
                            <label>Next date<input v-model="form.next_date" type="date" class="form-control" :min="tomorrowIso" /></label>
                            <label>Time<input v-model="form.next_time" type="time" class="form-control" /></label>
                        </div>
                        <label v-if="form.next_date">Next for
                            <select v-model="form.next_type" class="form-control">
                                <option :value="null">Same as today ({{ h.type }})</option>
                                <option v-for="t in data.hearing_types" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </label>
                        <div class="buttons">
                            <button type="submit" class="btn btn-primary" :disabled="saving">Save</button>
                            <button type="button" class="btn btn-outline-secondary" @click="recording = null">Cancel</button>
                        </div>
                    </form>
                </article>
            </section>

            <section>
                <h2>Tasks due</h2>
                <p v-if="!data.tasks.length" class="hint">Nothing due.</p>
                <div v-for="t in data.tasks" :key="t.id" class="task">
                    <span>{{ t.title }}</span>
                    <span class="hint" :class="{ overdue: t.due_date < data.date }">{{ t.due_date < data.date ? 'overdue' : 'today' }}</span>
                </div>
            </section>
        </template>
    </div>
</template>

<script setup>
/** FUN-19: the hearing-day view, built for a phone; readable offline from the last copy (see public/sw.js). */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Today' });
const { showMessage } = useToast();
const data = ref({ hearings: [], tasks: [], hearing_types: [], can_record: false, date: '' });
const loading = ref(true);
const offline = ref(!navigator.onLine);
const recording = ref(null);
const saving = ref(false);
const form = ref({});
const installable = ref(Boolean(window.$installPrompt));

const iso = (d) => d.toISOString().slice(0, 10);
const tomorrowIso = iso(new Date(Date.now() + 86400000));
const dateLabel = computed(() => (data.value.date ? new Date(`${data.value.date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) : ''));
const byDay = (day) => data.value.hearings.filter((h) => h.day === day);

const load = () => axios.get('/api/v1/today')
    .then(({ data: d }) => { data.value = d.data; })
    .catch(() => { if (!navigator.onLine) offline.value = true; else showMessage('Could not load today.', 'error'); })
    .finally(() => { loading.value = false; });
const startRecord = (h) => {
    recording.value = h;
    form.value = { outcome: h.outcome || '', next_date: '', next_time: '', next_type: null };
};
const save = () => {
    saving.value = true;
    const body = { outcome: form.value.outcome, next_date: form.value.next_date || null, next_time: form.value.next_time || null, next_type: form.value.next_type };
    axios.post(`/api/v1/hearings/${recording.value.id}/outcome`, body)
        .then(({ data: d }) => { showMessage(d.message); recording.value = null; load(); })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not save the outcome.', 'error'))
        .finally(() => { saving.value = false; });
};
const install = () => {
    window.$installPrompt?.prompt();
    window.$installPrompt = null;
    installable.value = false;
};
const online = () => { offline.value = false; load(); };
const off = () => { offline.value = true; };

onMounted(() => {
    load();
    window.addEventListener('online', online);
    window.addEventListener('offline', off);
});
onUnmounted(() => {
    window.removeEventListener('online', online);
    window.removeEventListener('offline', off);
});
</script>

<style lang="scss" scoped>
.today { padding: 16px; max-width: 720px; margin: 0 auto; }
.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
h1 { font-size: 24px; margin: 0; }
h2 { font-size: 16px; margin: 20px 0 8px; }
.hint { font-size: 13px; color: var(--text-secondary, #64748b); margin: 0; }
.offline { color: #b45309; }
.hearing { display: grid; grid-template-columns: 64px 1fr; gap: 8px 12px; padding: 12px; margin-bottom: 10px; background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; }
.when { font-weight: 700; font-size: 15px; }
.outcome { margin-top: 4px; font-size: 14px; }
.buttons { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; }
.buttons .btn { min-height: 44px; display: inline-flex; align-items: center; }   /* thumb-sized */
.record { grid-column: 1 / -1; display: grid; gap: 8px; }
.record label { display: grid; gap: 4px; font-size: 13px; }
.next { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.task { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-color, #e2e8f0); }
.overdue { color: #b42318; }
</style>
