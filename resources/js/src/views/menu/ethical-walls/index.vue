<template>
    <div class="walls-page">
        <PageHeader title="Ethical walls" subtitle="Bar a person from a matter, or from every matter of a client, for conflicts of interest." icon="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row"><li><div class="page-header"><nav class="breadcrumb-one" aria-label="breadcrumb"><ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                <li class="breadcrumb-item active" aria-current="page"><span>Ethical walls</span></li>
            </ol></nav></div></li></ul>
        </teleport>

        <section class="elevated-card" aria-labelledby="new-title">
            <div class="card-header"><h2 id="new-title" class="card-title">Put up a wall</h2></div>
            <div class="card-body">
                <p class="hint">A walled person gets "not found" for the material: in lists, search, exports, documents and reminders. Walls are lifted, never deleted, so the history stays on record. You cannot wall yourself.</p>
                <form class="form" @submit.prevent="create">
                    <div class="field grow"><span>Person to wall off</span>
                        <SearchSelect fetch-url="/api/v1/advocates" v-model="form.user_id" placeholder="Search people..." />
                    </div>
                    <label class="field">Wall off
                        <select v-model="form.scope" class="form-control"><option value="case">one matter</option><option value="client">a client (all its matters)</option></select>
                    </label>
                    <div class="field grow"><span>{{ form.scope === 'case' ? 'Matter' : 'Client' }}</span>
                        <SearchSelect :key="form.scope" v-model="form.target_id" :fetch-url="form.scope === 'case' ? '/api/v1/casesDropDown' : '/api/v1/clientsDropDown'" :placeholder="form.scope === 'case' ? 'Search matters...' : 'Search clients...'" />
                    </div>
                    <label class="field grow">Reason<input v-model="form.reason" class="form-control" minlength="5" maxlength="1000" required placeholder="e.g. We previously acted for the other side" /></label>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="!form.user_id || !form.target_id || saving">Put up wall</button>
                </form>
            </div>
        </section>

        <div class="tabs" role="tablist" aria-label="Walls">
            <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="tab" :class="{ active: tab === t.key }" :aria-selected="tab === t.key" @click="switchTab(t.key)">{{ t.label }}</button>
            <span class="spacer"></span>
            <button v-if="tab !== 'attempts'" type="button" class="btn btn-link btn-sm" @click="csv('ethical-screens')">Download CSV</button>
            <button v-else type="button" class="btn btn-link btn-sm" @click="csv('ethical-screens/attempts')">Download CSV</button>
        </div>

        <section class="elevated-card"><div class="card-body">
            <div class="table-wrap" v-if="tab !== 'attempts'">
                <table v-if="rows.length" class="table table-sm">
                    <thead><tr><th scope="col">Person</th><th scope="col">Walled from</th><th scope="col">Reason</th><th scope="col">Put up</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id">
                            <td>{{ r.user_name }}</td>
                            <td>{{ r.case_number ? 'Matter ' + r.case_number : 'Client ' + r.client_name }}</td>
                            <td class="reason">{{ r.reason }}</td>
                            <td>{{ when(r.created_at) }}</td>
                            <td><span class="pill" :class="r.lifted_at ? 'lifted' : 'active'">{{ r.lifted_at ? 'Lifted ' + when(r.lifted_at) : 'Active' }}</span><small v-if="r.lift_reason" class="sub">{{ r.lift_reason }}</small></td>
                            <td><button v-if="!r.lifted_at" type="button" class="btn btn-outline-danger btn-sm" @click="lift(r)">Lift</button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else-if="!loading" class="hint">{{ tab === 'active' ? 'No walls are up.' : 'No walls have been lifted.' }}</p>
            </div>
            <div class="table-wrap" v-else>
                <p class="hint">Every time a walled person tried to reach walled material. They were refused; this is your proof.</p>
                <table v-if="rows.length" class="table table-sm">
                    <thead><tr><th scope="col">When</th><th scope="col">Person</th><th scope="col">Matter</th><th scope="col">Via</th><th scope="col">Request</th><th scope="col">Result</th></tr></thead>
                    <tbody><tr v-for="a in rows" :key="a.id"><td>{{ when(a.created_at) }}</td><td>{{ a.user_name }}</td><td>{{ a.case_number || '—' }}</td><td>{{ a.surface }}</td><td class="reason">{{ a.method }} {{ a.path }}</td><td>{{ a.status }}</td></tr></tbody>
                </table>
                <p v-else-if="!loading" class="hint">No attempts recorded.</p>
            </div>
        </div></section>
    </div>
</template>

<script setup>
/** SEC-11: ethical walls (manage-ethical-screens). */
import PageHeader from '@/components/ui/page-header.vue';
import SearchSelect from '@/components/ui/search-select.vue';
import { confirmAction } from '@/composables/use-confirm';
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Ethical walls' });
const { showMessage } = useToast();
const tabs = [{ key: 'active', label: 'Active walls' }, { key: 'lifted', label: 'Lifted' }, { key: 'attempts', label: 'Blocked attempts' }];
const tab = ref('active');
const rows = ref([]);
const loading = ref(true);
const saving = ref(false);
const form = ref({ user_id: null, scope: 'case', target_id: null, reason: '' });
const when = (v) => (v ? new Date(v).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');

const load = async () => {
    loading.value = true;
    try {
        const url = tab.value === 'attempts' ? '/api/v1/ethical-screens/attempts' : '/api/v1/ethical-screens';
        const { data } = await axios.get(url, { params: tab.value === 'attempts' ? {} : { status: tab.value } });
        rows.value = data.data;
    } catch (e) { showMessage(e.response?.data?.message || 'Could not load walls.', 'error'); }
    loading.value = false;
};
const switchTab = (k) => { tab.value = k; load(); };
const create = async () => {
    saving.value = true;
    try {
        const body = { user_id: form.value.user_id, reason: form.value.reason };
        body[form.value.scope === 'case' ? 'case_id' : 'client_id'] = form.value.target_id;
        await axios.post('/api/v1/ethical-screens', body);
        showMessage('Wall put up.');
        form.value = { user_id: null, scope: form.value.scope, target_id: null, reason: '' };
        tab.value = 'active'; await load();
    } catch (e) { showMessage(e.response?.data?.message || 'Could not put up the wall.', 'error'); }
    saving.value = false;
};
const lift = async (r) => {
    const res = await window.Swal.fire({ title: 'Lift this wall?', text: `${r.user_name} will be able to see the material again. Say why:`, input: 'text', inputAttributes: { minlength: 5 }, showCancelButton: true, confirmButtonText: 'Lift wall', inputValidator: (v) => (!v || v.length < 5 ? 'Give a reason (5+ characters)' : null) });
    if (!res.isConfirmed) return;
    try { await axios.post(`/api/v1/ethical-screens/${r.id}/lift`, { reason: res.value }); showMessage('Wall lifted.'); await load(); }
    catch (e) { showMessage(e.response?.data?.message || 'Could not lift the wall.', 'error'); }
};
const csv = async (path) => {
    try {
        const { data } = await axios.get(`/api/v1/${path}`, { params: { format: 'csv', status: tab.value === 'attempts' ? undefined : tab.value }, responseType: 'blob' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(data); a.download = `${path.replace('/', '-')}.csv`; a.click(); URL.revokeObjectURL(a.href);
    } catch { showMessage('Could not download.', 'error'); }
};
onMounted(load);
</script>

<style lang="scss" scoped>
.walls-page .elevated-card { margin-bottom: 16px; }
.hint { font-size: 13px; color: var(--text-muted); margin: 0 0 12px; }
.form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; min-width: 160px; }
.grow { flex: 1; min-width: 200px; }
.tabs { display: flex; gap: 4px; align-items: center; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; }
.tab { border: 0; background: none; padding: 8px 14px; font-size: 14px; color: var(--text-muted); border-bottom: 2px solid transparent; }
.tab.active { color: var(--primary-700); border-bottom-color: var(--primary-600); font-weight: 600; }
.spacer { flex: 1; }
.table-wrap { overflow-x: auto; }
.reason { max-width: 320px; word-break: break-word; }
.sub { display: block; color: var(--text-muted); font-size: 12px; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.pill.active { background: var(--error-100); color: var(--error-700); }
.pill.lifted { background: var(--neutral-200); color: var(--neutral-700); }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.btn-link { box-shadow: none; background: transparent; }
</style>
