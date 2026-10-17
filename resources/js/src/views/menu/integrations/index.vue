<template>
    <div class="api-page">
        <PageHeader title="API access" subtitle="Keys and webhooks, so your other systems can read your firm's data and hear about changes." icon="M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row"><li><div class="page-header"><nav class="breadcrumb-one" aria-label="breadcrumb"><ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                <li class="breadcrumb-item active" aria-current="page"><span>API access</span></li>
            </ol></nav></div></li></ul>
        </teleport>

        <div v-if="secret" class="secret-box" role="alert" data-testid="api-secret">
            <strong>{{ secret.title }}</strong>
            <p>Copy it now. It is shown only once.</p>
            <div class="copy"><code>{{ secret.value }}</code><button type="button" class="btn btn-sm btn-outline-secondary" @click="copy">Copy</button></div>
            <button type="button" class="btn btn-link btn-sm" @click="secret = null">I've saved it</button>
        </div>

        <div class="tabs" role="tablist" aria-label="API access">
            <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="tab" :class="{ active: tab === t.key }" :aria-selected="tab === t.key" @click="tab = t.key">{{ t.label }}</button>
        </div>

        <!-- API keys -->
        <template v-if="tab === 'keys'">
            <section class="elevated-card"><div class="card-header"><h2 class="card-title">Create a key</h2></div><div class="card-body">
                <p class="hint">A key can read only what you tick, and never more than the person it acts as. It can be revoked at any time.</p>
                <form class="form" @submit.prevent="createKey">
                    <label class="field grow">Name<input v-model="kf.name" class="form-control" required maxlength="120" placeholder="e.g. Accounting system" /></label>
                    <fieldset class="field"><legend>Can read</legend>
                        <label v-for="(label, scope) in scopeLabels" :key="scope" class="check"><input type="checkbox" :value="scope" v-model="kf.scopes" /> {{ label }}</label>
                    </fieldset>
                    <label class="field">Expires (optional)<input v-model="kf.expires_at" type="date" class="form-control" :min="tomorrow" /></label>
                    <button class="btn btn-primary btn-sm" type="submit" :disabled="!kf.name || !kf.scopes.length">Create key</button>
                </form>
            </div></section>
            <section class="elevated-card"><div class="card-header"><h2 class="card-title">Your keys</h2></div><div class="card-body"><div class="table-wrap">
                <table v-if="keys.length" class="table table-sm">
                    <thead><tr><th scope="col">Name</th><th scope="col">Key</th><th scope="col">Can read</th><th scope="col">Last used</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody><tr v-for="k in keys" :key="k.id">
                        <td>{{ k.name }}</td><td><code>{{ k.prefix }}…</code></td><td>{{ (k.scopes || []).map((s) => scopeLabels[s] || s).join(', ') }}</td>
                        <td>{{ k.last_used_at ? when(k.last_used_at) : 'Never' }}</td>
                        <td><span class="pill" :class="k.revoked_at ? 'off' : 'on'">{{ k.revoked_at ? 'Revoked' : (k.expires_at ? 'Until ' + day(k.expires_at) : 'Active') }}</span></td>
                        <td><button v-if="!k.revoked_at" type="button" class="btn btn-outline-danger btn-sm" @click="revokeKey(k)">Revoke</button></td>
                    </tr></tbody>
                </table>
                <p v-else class="hint">No keys yet.</p>
            </div></div></section>
        </template>

        <!-- Webhooks -->
        <template v-else>
            <section class="elevated-card"><div class="card-header"><h2 class="card-title">Add a webhook</h2></div><div class="card-body">
                <p class="hint">We send a signed message to your address whenever the events you choose happen. Failed deliveries are retried.</p>
                <form class="form" @submit.prevent="createHook">
                    <label class="field grow">Address (https)<input v-model="hf.url" type="url" class="form-control" required placeholder="https://example.com/hooks/law" /></label>
                    <label class="field grow">Note (optional)<input v-model="hf.description" class="form-control" maxlength="255" /></label>
                    <fieldset class="field wide"><legend>Tell me when</legend>
                        <div class="checks"><label v-for="e in events" :key="e" class="check"><input type="checkbox" :value="e" v-model="hf.events" /> {{ e }}</label></div>
                    </fieldset>
                    <button class="btn btn-primary btn-sm" type="submit" :disabled="!hf.url || !hf.events.length">Add webhook</button>
                </form>
            </div></section>
            <section class="elevated-card"><div class="card-header"><h2 class="card-title">Your webhooks</h2></div><div class="card-body"><div class="table-wrap">
                <table v-if="hooks.length" class="table table-sm">
                    <thead><tr><th scope="col">Address</th><th scope="col">Events</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody><tr v-for="h in hooks" :key="h.id">
                        <td class="reason">{{ h.url }}<small v-if="h.description" class="sub">{{ h.description }}</small></td>
                        <td class="reason">{{ (h.events || []).join(', ') }}</td>
                        <td><span class="pill" :class="h.active ? 'on' : 'off'">{{ h.active ? 'Active' : 'Paused' }}</span><small v-if="h.disabled_reason" class="sub">{{ h.disabled_reason }}</small></td>
                        <td class="actions">
                            <button type="button" class="btn btn-link btn-sm" @click="ping(h)">Send test</button>
                            <button type="button" class="btn btn-link btn-sm" @click="openDeliveries(h)">Deliveries</button>
                            <button type="button" class="btn btn-link btn-sm" @click="toggle(h)">{{ h.active ? 'Pause' : 'Resume' }}</button>
                            <button type="button" class="btn btn-link btn-sm" @click="rotate(h)">New secret</button>
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="removeHook(h)">Delete</button>
                        </td>
                    </tr></tbody>
                </table>
                <p v-else class="hint">No webhooks yet.</p>
            </div></div></section>
            <section v-if="deliveryFor" class="elevated-card"><div class="card-header"><h2 class="card-title">Recent deliveries to {{ deliveryFor.url }}</h2><button type="button" class="btn btn-link btn-sm" @click="deliveryFor = null">Close</button></div><div class="card-body"><div class="table-wrap">
                <table v-if="deliveries.length" class="table table-sm">
                    <thead><tr><th scope="col">When</th><th scope="col">Event</th><th scope="col">Result</th><th scope="col">Tries</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody><tr v-for="d in deliveries" :key="d.id"><td>{{ when(d.created_at) }}</td><td>{{ d.event }}</td><td>{{ d.status }}<small v-if="d.last_error" class="sub">{{ d.last_error }}</small></td><td>{{ d.attempts }}</td>
                        <td><button type="button" class="btn btn-link btn-sm" @click="replay(d)">Send again</button></td></tr></tbody>
                </table>
                <p v-else class="hint">Nothing sent yet.</p>
            </div></div></section>
        </template>
    </div>
</template>

<script setup>
/** PLAT-11: API keys and webhooks (manage-api-access; plan feature api_access). Secrets are shown once. */
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'API access' });
const { showMessage } = useToast();
const tabs = [{ key: 'keys', label: 'API keys' }, { key: 'hooks', label: 'Webhooks' }];
const tab = ref('keys');
const keys = ref([]); const hooks = ref([]); const events = ref([]);
const secret = ref(null);
const deliveryFor = ref(null); const deliveries = ref([]);
const kf = ref({ name: '', scopes: ['clients:read'], expires_at: '' });
const hf = ref({ url: '', description: '', events: [] });
const scopeLabels = { 'clients:read': 'Clients', 'cases:read': 'Matters', 'hearings:read': 'Hearings' };
const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
const when = (v) => new Date(v).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const day = (v) => new Date(v).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
const err = (e, m) => showMessage(e.response?.data?.message || e.response?.data?.errors && Object.values(e.response.data.errors)[0][0] || m, 'error');

const load = async () => {
    try {
        keys.value = (await axios.get('/api/v1/integrations/api-keys')).data.data;
        const h = (await axios.get('/api/v1/integrations/webhooks')).data;
        hooks.value = h.data; events.value = h.events || [];
    } catch (e) { err(e, 'Could not load API access.'); }
};
const copy = () => navigator.clipboard?.writeText(secret.value.value).then(() => showMessage('Copied.'));
const createKey = async () => {
    try {
        const { data } = await axios.post('/api/v1/integrations/api-keys', { name: kf.value.name, scopes: kf.value.scopes, expires_at: kf.value.expires_at || null });
        secret.value = { title: `Key "${data.data.name}" created`, value: data.data.key };
        kf.value = { name: '', scopes: ['clients:read'], expires_at: '' }; await load();
    } catch (e) { err(e, 'Could not create the key.'); }
};
const revokeKey = async (k) => {
    if (!(await confirmAction({ title: 'Revoke key', message: `"${k.name}" will stop working immediately.`, confirmText: 'Revoke' }))) return;
    try { await axios.delete(`/api/v1/integrations/api-keys/${k.id}`); showMessage('Key revoked.'); await load(); } catch (e) { err(e, 'Could not revoke.'); }
};
const createHook = async () => {
    try {
        const { data } = await axios.post('/api/v1/integrations/webhooks', { url: hf.value.url, description: hf.value.description || null, events: hf.value.events });
        secret.value = { title: 'Webhook added: signing secret', value: data.data.secret };
        hf.value = { url: '', description: '', events: [] }; await load();
    } catch (e) { err(e, 'Could not add the webhook.'); }
};
const toggle = async (h) => { try { await axios.put(`/api/v1/integrations/webhooks/${h.id}`, { active: !h.active }); await load(); } catch (e) { err(e, 'Could not update.'); } };
const ping = async (h) => { try { await axios.post(`/api/v1/integrations/webhooks/${h.id}/ping`); showMessage('Test message queued.'); } catch (e) { err(e, 'Could not send a test.'); } };
const rotate = async (h) => {
    if (!(await confirmAction({ title: 'New signing secret', message: 'The old secret stops working straight away.', confirmText: 'Create new secret' }))) return;
    try { const { data } = await axios.post(`/api/v1/integrations/webhooks/${h.id}/rotate-secret`); secret.value = { title: 'New signing secret', value: data.data.secret }; } catch (e) { err(e, 'Could not rotate.'); }
};
const removeHook = async (h) => {
    if (!(await confirmAction({ title: 'Delete webhook', message: `Stop sending to ${h.url}?`, confirmText: 'Delete' }))) return;
    try { await axios.delete(`/api/v1/integrations/webhooks/${h.id}`); showMessage('Webhook deleted.'); await load(); } catch (e) { err(e, 'Could not delete.'); }
};
const openDeliveries = async (h) => {
    deliveryFor.value = h;
    try { deliveries.value = (await axios.get(`/api/v1/integrations/webhooks/${h.id}/deliveries`)).data.data; } catch { deliveries.value = []; }
};
const replay = async (d) => { try { await axios.post(`/api/v1/integrations/webhooks/deliveries/${d.id}/replay`); showMessage('Queued again.'); await openDeliveries(deliveryFor.value); } catch (e) { err(e, 'Could not resend.'); } };
onMounted(load);
</script>

<style lang="scss" scoped>
.api-page .elevated-card { margin-bottom: 16px; }
.hint { font-size: 13px; color: var(--text-muted); margin: 0 0 12px; }
.form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; min-width: 160px; border: 0; padding: 0; margin: 0; }
.field legend { font-size: 13px; margin-bottom: 4px; padding: 0; float: none; width: auto; }
.grow { flex: 1; min-width: 220px; }
.wide { flex-basis: 100%; }
.checks { display: flex; flex-wrap: wrap; gap: 6px 16px; }
.check { display: flex; gap: 6px; align-items: center; font-size: 13px; }
.tabs { display: flex; gap: 4px; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); }
.tab { border: 0; background: none; padding: 8px 14px; font-size: 14px; color: var(--text-muted); border-bottom: 2px solid transparent; }
.tab.active { color: var(--primary-700); border-bottom-color: var(--primary-600); font-weight: 600; }
.table-wrap { overflow-x: auto; }
.reason { max-width: 320px; word-break: break-word; }
.sub { display: block; color: var(--text-muted); font-size: 12px; }
.actions { white-space: nowrap; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.pill.on { background: var(--success-100); color: var(--success-700); }
.pill.off { background: var(--neutral-200); color: var(--neutral-700); }
.secret-box { background: var(--warning-100); border: 1px solid var(--warning-500); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
.secret-box p { margin: 4px 0 8px; font-size: 13px; }
.copy { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.copy code { background: var(--card-bg); padding: 6px 10px; border-radius: 6px; word-break: break-all; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.btn-link { box-shadow: none; background: transparent; }
</style>
