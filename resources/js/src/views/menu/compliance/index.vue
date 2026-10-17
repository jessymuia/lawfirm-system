<template>
    <div class="compliance-page">
        <PageHeader title="Compliance" subtitle="Legal holds, retention periods and data-subject requests." icon="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Compliance</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="tabs" role="tablist" aria-label="Compliance">
            <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="tab" :class="{ active: tab === t.key }"
                    :aria-selected="tab === t.key" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <!-- Legal holds -->
        <section v-if="tab === 'holds'" class="elevated-card" aria-labelledby="holds-title">
            <div class="card-header"><h2 id="holds-title" class="card-title">Legal holds</h2></div>
            <div class="card-body">
                <p class="hint">A held client or matter, and its documents, cannot be deleted, disposed of under retention or erased on request until the hold is released.</p>
                <form class="inline-form" @submit.prevent="placeHold">
                    <label class="field">On
                        <select v-model="newHold.scope_type" class="form-control">
                            <option value="client">a client (and all its matters)</option>
                            <option value="case">a matter</option>
                        </select>
                    </label>
                    <div class="field grow">
                        <span>{{ newHold.scope_type === 'client' ? 'Client' : 'Matter' }}</span>
                        <SearchSelect :key="newHold.scope_type" v-model="newHold.scope_id"
                                      :fetch-url="newHold.scope_type === 'client' ? '/api/v1/clientsDropDown' : '/api/v1/casesDropDown'"
                                      :placeholder="newHold.scope_type === 'client' ? 'Search clients...' : 'Search matters...'" />
                    </div>
                    <label class="field grow">Reason <input v-model="newHold.reason" class="form-control" maxlength="2000" required /></label>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="!newHold.scope_id">Place hold</button>
                </form>
                <table class="table table-sm">
                    <thead><tr><th scope="col">On</th><th scope="col">Reason</th><th scope="col">Placed</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="h in holds" :key="h.id">
                            <td>{{ h.scope_type === 'client' ? 'Client' : 'Matter' }} {{ h.scope_label }}</td>
                            <td>{{ h.reason }}</td>
                            <td>{{ day(h.placed_at) }}</td>
                            <td>{{ h.released_at ? `Released ${day(h.released_at)}` : 'Active' }}</td>
                            <td><button v-if="!h.released_at" type="button" class="btn btn-link btn-sm" @click="releaseHold(h)">Release</button></td>
                        </tr>
                        <tr v-if="!holds.length"><td colspan="5" class="hint">No legal holds.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Retention -->
        <section v-if="tab === 'retention'" class="elevated-card" aria-labelledby="retention-title">
            <div class="card-header"><h2 id="retention-title" class="card-title">Retention schedule</h2></div>
            <div class="card-body">
                <p class="hint">How long each kind of record is kept. Nothing is disposed of until a period is set and automatic disposal is on. Records under legal hold are never touched. The right period depends on your regulator and jurisdiction: this is not legal advice. Minimum: {{ minimumYears }} year(s).</p>
                <table class="table table-sm">
                    <thead><tr><th scope="col">Records</th><th scope="col">Clock starts</th><th scope="col">On disposal</th><th scope="col">Keep (years)</th><th scope="col">Dispose automatically</th><th scope="col">Due now</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="c in classes" :key="c.record_class">
                            <td>{{ c.label }}</td>
                            <td>{{ c.clock }}</td>
                            <td>{{ c.disposal ?? 'kept' }}</td>
                            <template v-if="c.purgeable">
                                <td><input v-model.number="c.retain_years" type="number" min="1" max="100" class="form-control years" :aria-label="`Years to keep: ${c.label}`" /></td>
                                <td><input v-model="c.auto_purge" type="checkbox" :aria-label="`Dispose automatically: ${c.label}`" /></td>
                                <td>{{ due[c.record_class] ?? '—' }}</td>
                                <td><button type="button" class="btn btn-outline-primary btn-sm" :disabled="!c.retain_years" @click="savePolicy(c)">Save</button></td>
                            </template>
                            <td v-else colspan="4" class="hint">Never disposed of by retention.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Data-subject requests -->
        <section v-if="tab === 'requests'" class="elevated-card" aria-labelledby="dsar-title">
            <div class="card-header"><h2 id="dsar-title" class="card-title">Data-subject requests</h2></div>
            <div class="card-body">
                <p class="hint">A client's request to see, or erase, their personal data. Nothing is released or erased before the requester's identity is verified. A legal hold blocks erasure.</p>
                <form class="inline-form" @submit.prevent="createRequest">
                    <label class="field">Type
                        <select v-model="newRequest.type" class="form-control">
                            <option value="access">Access (a copy)</option>
                            <option value="erasure">Erasure</option>
                        </select>
                    </label>
                    <div class="field grow"><span>Client</span>
                        <SearchSelect v-model="newRequest.client_id" fetch-url="/api/v1/clientsDropDown" placeholder="Search clients..." />
                    </div>
                    <label class="field">Requester <input v-model="newRequest.requester_name" class="form-control" maxlength="255" required /></label>
                    <label class="field">Email <input v-model="newRequest.requester_email" type="email" class="form-control" maxlength="255" /></label>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="!newRequest.client_id">Record request</button>
                </form>
                <table class="table table-sm">
                    <thead><tr><th scope="col">Type</th><th scope="col">Client</th><th scope="col">Requester</th><th scope="col">Received</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="d in requests" :key="d.id">
                            <td>{{ d.type }}</td>
                            <td>{{ d.client_name }}</td>
                            <td>{{ d.requester_name }}</td>
                            <td>{{ d.received_at }}</td>
                            <td>{{ d.due_at }}</td>
                            <td>{{ d.status.replace('_', ' ') }}</td>
                            <td class="actions">
                                <button v-if="d.status === 'pending_verification'" type="button" class="btn btn-link btn-sm" @click="verify(d)">Verify identity</button>
                                <button v-if="d.status === 'pending_verification' || d.status === 'verified'" type="button" class="btn btn-link btn-sm" @click="reject(d)">Reject</button>
                                <button v-if="d.type === 'access' && ['verified', 'completed'].includes(d.status)" type="button" class="btn btn-link btn-sm" @click="exportData(d)">Download data</button>
                                <button v-if="d.type === 'erasure' && ['verified', 'blocked'].includes(d.status)" type="button" class="btn btn-link btn-sm text-danger" @click="erase(d)">Erase</button>
                            </td>
                        </tr>
                        <tr v-if="!requests.length"><td colspan="7" class="hint">No requests.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
import { onMounted, ref, watch } from 'vue';
import axios from '@/api';
import SearchSelect from '@/components/ui/search-select.vue';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

// SEC-13: legal holds, the retention schedule and data-subject requests (docs/COMPLIANCE.md), for manage-compliance.
useMeta({ title: 'Compliance' });
const { showMessage } = useToast();

interface Hold { id: number; scope_type: string; scope_id: number; scope_label: string; reason: string; placed_at: string; released_at: string | null }
interface RetentionClass { record_class: string; label: string; clock: string; disposal?: string; purgeable: boolean; retain_years: number | null; auto_purge: boolean }
interface DsarRow { id: number; type: string; client_id: number; client_name: string; requester_name: string; received_at: string; due_at: string; status: string }

const tabs = [{ key: 'holds', label: 'Legal holds' }, { key: 'retention', label: 'Retention' }, { key: 'requests', label: 'Data-subject requests' }];
const tab = ref('holds');
const holds = ref<Hold[]>([]);
const classes = ref<RetentionClass[]>([]);
const due = ref<Record<string, number>>({});
const minimumYears = ref(1);
const requests = ref<DsarRow[]>([]);
const newHold = ref({ scope_type: 'client', scope_id: null as number | null, reason: '' });
const newRequest = ref({ type: 'access', client_id: null as number | null, requester_name: '', requester_email: '' });

const day = (v: string | null) => (v ? v.slice(0, 10) : '');
const failed = (e: unknown, fallback: string) =>
    showMessage((e as { response?: { data?: { message?: string } } }).response?.data?.message ?? fallback, 'error');

const loadHolds = async () => { holds.value = (await axios.get('/api/v1/compliance/holds')).data.data; };
const loadRequests = async () => { requests.value = (await axios.get('/api/v1/compliance/dsar')).data.data; };
const loadRetention = async () => {
    const { data } = await axios.get('/api/v1/compliance/retention');
    classes.value = data.data.classes;
    minimumYears.value = data.data.minimum_years;
    for (const c of classes.value.filter(x => x.purgeable && x.retain_years)) {
        due.value[c.record_class] = (await axios.get('/api/v1/compliance/retention/due', { params: { class: c.record_class } })).data.data.due_count;
    }
};

const placeHold = async () => {
    if (!(await confirmAction({ title: 'Place a legal hold?', text: 'Records in this scope cannot be deleted or erased until the hold is released.', confirmText: 'Place hold' }))) return;
    try {
        await axios.post('/api/v1/compliance/holds', newHold.value);
        newHold.value = { scope_type: newHold.value.scope_type, scope_id: null, reason: '' };
        showMessage('Legal hold placed.');
        await loadHolds();
    } catch (e) { failed(e, 'The hold could not be placed.'); }
};
const releaseHold = async (h: Hold) => {
    const reason = await confirmAction({ title: 'Release this legal hold?', text: `Records covered by the hold on ${h.scope_label} can be deleted under your retention policy again.`, input: 'Reason for releasing the hold', confirmText: 'Release hold', danger: true });
    if (!reason) return;
    try {
        await axios.post(`/api/v1/compliance/holds/${h.id}/release`, { reason });
        showMessage('Legal hold released.');
        await loadHolds();
    } catch (e) { failed(e, 'The hold could not be released.'); }
};

const savePolicy = async (c: RetentionClass) => {
    try {
        await axios.put(`/api/v1/compliance/retention/${c.record_class}`, { retain_years: c.retain_years, auto_purge: c.auto_purge });
        showMessage(`Retention for ${c.label.toLowerCase()} saved.`);
        await loadRetention();
    } catch (e) { failed(e, 'The retention period could not be saved.'); }
};

const createRequest = async () => {
    try {
        await axios.post('/api/v1/compliance/dsar', newRequest.value);
        newRequest.value = { type: newRequest.value.type, client_id: null, requester_name: '', requester_email: '' };
        showMessage('Request recorded.');
        await loadRequests();
    } catch (e) { failed(e, 'The request could not be recorded.'); }
};
const verify = async (d: DsarRow) => {
    const note = await confirmAction({ title: `Confirm ${d.requester_name}'s identity`, text: 'Record how you checked it. This is kept as evidence for the request.', input: 'How was identity checked? (e.g. national ID seen at the office)', confirmText: 'Mark as verified' });
    if (!note) return;
    try {
        await axios.post(`/api/v1/compliance/dsar/${d.id}/verify`, { method: 'other', note });
        showMessage('Identity verified.');
        await loadRequests();
    } catch (e) { failed(e, 'The request could not be verified.'); }
};
const reject = async (d: DsarRow) => {
    const reason = await confirmAction({ title: 'Reject this request?', text: 'The requester is told it was rejected. Give the reason you are relying on.', input: 'Reason for rejecting', confirmText: 'Reject request', danger: true });
    if (!reason) return;
    try {
        await axios.post(`/api/v1/compliance/dsar/${d.id}/reject`, { reason });
        await loadRequests();
    } catch (e) { failed(e, 'The request could not be rejected.'); }
};
const exportData = async (d: DsarRow) => {
    if (!(await confirmAction({ title: 'Export this person\'s data?', text: 'A file with everything held about them is downloaded. Handle it as confidential.', confirmText: 'Download export' }))) return;
    try {
        const { data } = await axios.post(`/api/v1/compliance/dsar/${d.id}/export`, {}, { responseType: 'blob' });
        const url = URL.createObjectURL(new Blob([data], { type: 'application/json' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: `dsar-${d.id}.json` });
        a.click();
        URL.revokeObjectURL(url);
        await loadRequests();
    } catch (e) { failed(e, 'The data could not be exported.'); }
};
const erase = async (d: DsarRow) => {
    if (!(await confirmAction({ title: `Erase ${d.client_name}'s personal data?`, text: 'Their personal details are permanently removed. Financial records are kept as the law requires.', confirmText: 'Erase personal data', danger: true, irreversible: true, typeToConfirm: 'ERASE' }))) return;
    try {
        await axios.post(`/api/v1/compliance/dsar/${d.id}/erase`, { confirm: true });
        showMessage('Personal data erased.');
    } catch (e) { failed(e, 'Erasure is blocked (a legal hold?) or failed.'); }
    await loadRequests();
};

const loaders: Record<string, () => Promise<void>> = { holds: loadHolds, retention: loadRetention, requests: loadRequests };
watch(tab, t => { loaders[t]().catch(() => showMessage('Could not load this tab.', 'error')); });
onMounted(() => loadHolds().catch(() => showMessage('Could not load legal holds.', 'error')));
</script>

<style lang="scss" scoped>
.tabs { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); }
.tab { border: 0; background: none; padding: 8px 14px; font-size: 14px; color: var(--text-muted); border-bottom: 2px solid transparent; }
.tab.active { color: var(--primary-700); border-bottom-color: var(--primary-600); font-weight: 600; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.grow { flex: 1; min-width: 200px; }
.inline-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 12px 0 16px; }
.years { width: 80px; }
.actions { white-space: nowrap; }
.hint { font-size: 12px; color: var(--text-muted); }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
