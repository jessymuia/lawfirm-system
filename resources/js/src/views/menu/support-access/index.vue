<template>
    <div class="support-page">
        <PageHeader title="Support access" subtitle="You decide if and when platform staff can enter your firm. Every step is recorded." icon="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3zM9 12l2 2 4-4" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row"><li><div class="page-header"><nav class="breadcrumb-one" aria-label="breadcrumb"><ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                <li class="breadcrumb-item active" aria-current="page"><span>Support access</span></li>
            </ol></nav></div></li></ul>
        </teleport>

        <section class="elevated-card" aria-labelledby="pol-title">
            <div class="card-header"><h2 id="pol-title" class="card-title">Your policy</h2></div>
            <div class="card-body">
                <p class="hint">Support staff never have standing access. They can only ask for a time-limited session, which ends by itself and can be cut off by you at any moment.</p>
                <div class="policy">
                    <label class="switch-row"><input type="checkbox" v-model="policy.enabled" /> <span><strong>Allow support requests</strong><small>Turning this off ends every session in progress.</small></span></label>
                    <label class="switch-row"><input type="checkbox" v-model="policy.require_approval" :disabled="!policy.enabled" /> <span><strong>Ask me to approve each request</strong><small>Recommended. Otherwise requests are approved automatically.</small></span></label>
                    <label class="switch-row"><input type="checkbox" v-model="policy.allow_write" :disabled="!policy.enabled" /> <span><strong>Allow staff to make changes</strong><small>Off means view-only: they can look but not edit.</small></span></label>
                    <label class="field">Longest session (minutes)
                        <input type="number" v-model.number="policy.max_minutes" min="5" max="480" class="form-control minutes" :disabled="!policy.enabled" />
                    </label>
                </div>
                <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="savePolicy">{{ saving ? 'Saving...' : 'Save policy' }}</button>
            </div>
        </section>

        <section class="elevated-card" aria-labelledby="ses-title">
            <div class="card-header"><h2 id="ses-title" class="card-title">Requests and sessions</h2></div>
            <div class="card-body">
                <div v-if="loading" class="hint">Loading...</div>
                <div v-else-if="!sessions.length" class="empty">No support requests yet. Nothing has ever entered this firm.</div>
                <div v-else class="table-wrap">
                    <table class="table table-sm">
                        <thead><tr><th scope="col">Requested</th><th scope="col">Staff</th><th scope="col">Reason</th><th scope="col">Access</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                            <tr v-for="s in sessions" :key="s.id">
                                <td data-label="Requested">{{ when(s.requested_at) }}</td>
                                <td data-label="Staff">{{ s.staff_name }}<small class="sub">{{ s.requested_minutes }} min</small></td>
                                <td data-label="Reason" class="reason">{{ s.reason }}</td>
                                <td data-label="Access">{{ s.mode === 'full' ? 'Can edit' : 'View only' }}</td>
                                <td data-label="Status"><span class="pill" :class="'st-' + s.status">{{ label(s.status) }}</span></td>
                                <td class="actions">
                                    <template v-if="s.status === 'pending'">
                                        <button type="button" class="btn btn-primary btn-sm" @click="act(s, 'approve')">Approve</button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" @click="act(s, 'deny')">Deny</button>
                                    </template>
                                    <button v-if="['approved', 'active'].includes(s.status)" type="button" class="btn btn-outline-danger btn-sm" @click="act(s, 'revoke')">Cut off</button>
                                    <button v-if="['active', 'ended', 'expired', 'revoked'].includes(s.status)" type="button" class="btn btn-link btn-sm" @click="openLog(s)">Activity</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section v-if="logFor" class="elevated-card" aria-labelledby="log-title">
            <div class="card-header"><h2 id="log-title" class="card-title">Activity during {{ logFor.staff_name }}'s session</h2><button type="button" class="btn btn-link btn-sm" @click="logFor = null">Close</button></div>
            <div class="card-body">
                <div v-if="!log.length" class="hint">Nothing was done in this session.</div>
                <div v-else class="table-wrap"><table class="table table-sm">
                    <thead><tr><th scope="col">Time</th><th scope="col">Action</th><th scope="col">Page</th><th scope="col">Result</th></tr></thead>
                    <tbody><tr v-for="l in log" :key="l.id"><td>{{ when(l.created_at) }}</td><td>{{ l.method }}</td><td class="reason">{{ l.path }}</td><td>{{ l.status }}</td></tr></tbody>
                </table></div>
            </div>
        </section>
    </div>
</template>

<script setup>
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Support access' });
const { showMessage } = useToast();
const policy = ref({ enabled: true, require_approval: true, allow_write: false, max_minutes: 60 });
const sessions = ref([]);
const loading = ref(true);
const saving = ref(false);
const logFor = ref(null);
const log = ref([]);

const LABELS = { pending: 'Waiting for you', approved: 'Approved', active: 'In progress', ended: 'Ended', expired: 'Expired', revoked: 'Cut off', denied: 'Denied' };
const label = (s) => LABELS[s] || s;
const when = (v) => (v ? new Date(v).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

const load = async () => {
    try {
        const { data } = await axios.get('/api/v1/support-access');
        policy.value = { ...policy.value, ...data.data.policy };
        sessions.value = data.data.sessions;
    } catch (e) { showMessage(e.response?.data?.message || 'Could not load support access.', 'error'); }
    loading.value = false;
};
const savePolicy = async () => {
    saving.value = true;
    try {
        const { data } = await axios.put('/api/v1/support-access/policy', policy.value);
        policy.value = { ...policy.value, ...data.data };
        showMessage('Policy saved.');
        load();
    } catch (e) { showMessage(e.response?.data?.message || 'Could not save the policy.', 'error'); }
    saving.value = false;
};
const act = async (s, action) => {
    const text = { approve: 'Approve this request? Staff can then start a time-limited session.', deny: 'Deny this request?', revoke: 'Cut off this session now? Their access ends immediately.' }[action];
    if (!(await confirmAction({ title: 'Support access', message: text, confirmText: { approve: 'Approve', deny: 'Deny', revoke: 'Cut off' }[action] }))) return;
    try { await axios.post(`/api/v1/support-access/${s.id}/${action}`); showMessage('Done.'); load(); }
    catch (e) { showMessage(e.response?.data?.message || 'That did not work.', 'error'); }
};
const openLog = async (s) => {
    logFor.value = s;
    try { log.value = (await axios.get(`/api/v1/support-access/${s.id}/log`)).data.data; } catch { log.value = []; }
};
onMounted(load);
</script>

<style lang="scss" scoped>
.support-page .elevated-card { margin-bottom: 16px; }
.hint { font-size: 13px; color: var(--text-muted); margin: 0 0 12px; }
.empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 14px; }
.policy { display: grid; gap: 12px; margin-bottom: 16px; }
.switch-row { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
.switch-row input { margin-top: 4px; width: 16px; height: 16px; flex: none; }
.switch-row strong { display: block; font-size: 14px; }
.switch-row small { display: block; color: var(--text-muted); font-size: 12.5px; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; max-width: 220px; }
.table-wrap { overflow-x: auto; }
.reason { max-width: 320px; word-break: break-word; }
.sub { display: block; color: var(--text-muted); font-size: 12px; }
.actions { white-space: nowrap; display: flex; gap: 6px; flex-wrap: wrap; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--neutral-200); color: var(--neutral-700); }
.st-pending { background: var(--warning-100); color: var(--text-primary); }
.st-active { background: var(--primary-100); color: var(--primary-800); }
.st-approved { background: var(--success-100); color: var(--success-700); }
.st-denied, .st-revoked { background: var(--error-100); color: var(--error-700); }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.btn-link { box-shadow: none; background: transparent; }
@media (max-width: 640px) {
    thead { display: none; }
    tr { display: block; border: 1px solid var(--border-color); border-radius: 10px; padding: 8px 12px; margin-bottom: 10px; }
    td { display: flex; justify-content: space-between; gap: 12px; border: 0 !important; padding: 4px 0 !important; }
    td[data-label]::before { content: attr(data-label); color: var(--text-muted); font-size: 12px; flex: none; }
    td.actions { justify-content: flex-start; padding-top: 8px !important; }
}
</style>
