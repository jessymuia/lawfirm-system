<template>
    <div class="elevated-card" data-testid="portal-tab">
        <div class="card-header">
            <h2 class="card-title">Client portal</h2>
        </div>
        <div class="card-body">
            <SkeletonLoader v-if="loading" :rows="4" />
            <template v-else-if="state">
                <div class="access">
                    <div>
                        <strong>{{ state.client.name }}</strong>
                        <span v-if="state.client.portal_enabled" class="badge bg-success ms-2">Portal on</span>
                        <span v-else class="badge bg-secondary ms-2">No portal access</span>
                        <div class="hint">
                            <template v-if="state.client.last_login_at">Last signed in {{ when(state.client.last_login_at) }}.</template>
                            <template v-else-if="state.client.portal_enabled">Invited {{ when(state.client.invited_at) }}; not signed in yet.</template>
                            <template v-else>The client signs in at <code>{{ portalUrl }}</code> with a code texted to their phone.</template>
                        </div>
                    </div>
                    <button v-if="state.client.portal_enabled" type="button" class="btn btn-sm btn-outline-danger" :disabled="busy" @click="setAccess(false)">Withdraw access</button>
                    <button v-else type="button" class="btn btn-sm btn-primary" :disabled="busy || !state.client.has_phone" @click="setAccess(true)">Give access and text an invitation</button>
                </div>

                <h3 class="section">Shared documents</h3>
                <table v-if="state.documents.length" class="table">
                    <thead><tr><th>Document</th><th>Direction</th><th>When</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="d in state.documents" :key="d.id">
                            <td>{{ d.title }} <small class="muted">{{ d.filename }}</small></td>
                            <td>
                                <template v-if="d.direction === 'from_client'">From the client</template>
                                <template v-else>Shared by {{ d.shared_by || '—' }}<small v-if="d.viewed_at" class="muted"> · opened {{ when(d.viewed_at) }}</small></template>
                            </td>
                            <td>{{ when(d.shared_at) }}</td>
                            <td><button v-if="d.direction === 'to_client'" type="button" class="btn btn-sm btn-outline-secondary" @click="unshare(d)">Stop sharing</button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="hint">Nothing shared yet. Documents the client sends appear here and in the Documents tab.</p>
                <form class="share" @submit.prevent="share">
                    <select v-model="pick" class="form-control" aria-label="Document to share">
                        <option value="">Share a document of this matter…</option>
                        <option v-for="d in shareable" :key="d.id" :value="d.id">{{ d.title }} ({{ d.filename }})</option>
                    </select>
                    <label class="notify"><input v-model="notify" type="checkbox" /> Text the client</label>
                    <button type="submit" class="btn btn-primary" :disabled="!pick || busy">Share</button>
                </form>

                <h3 class="section">Messages</h3>
                <div ref="threadRef" class="thread">
                    <p v-if="!state.messages.length" class="hint">No messages yet.</p>
                    <div v-for="m in state.messages" :key="m.id" class="msg" :class="m.direction === 'to_client' ? 'out' : 'in'">
                        {{ m.body }}
                        <div class="meta">{{ m.direction === 'to_client' ? (m.sender || 'Firm') : state.client.name }} · {{ when(m.sent_at) }}<template v-if="m.direction === 'to_client' && m.read_at"> · read</template></div>
                    </div>
                </div>
                <form class="reply" @submit.prevent="send">
                    <textarea v-model="reply" class="form-control" rows="3" maxlength="5000" placeholder="Reply to the client (they get an SMS that a message is waiting)" aria-label="Reply"></textarea>
                    <button type="submit" class="btn btn-primary" :disabled="!reply.trim() || busy">Send</button>
                </form>
            </template>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/** FUN-10: the matter's client portal: the client's access, documents shared both ways, and the message thread. */
import { computed, nextTick, onMounted, ref } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';
import SkeletonLoader from '@/components/ui/skeleton-loader.vue';

const props = defineProps({ caseId: { type: [Number, String], required: true } });
const { showMessage } = useToast();

const state = ref(null);
const docs = ref([]);
const loading = ref(true);
const busy = ref(false);
const pick = ref('');
const notify = ref(true);
const reply = ref('');
const threadRef = ref(null);
const portalUrl = `${window.location.origin}/portal`;

const when = (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
const fail = (e, text) => showMessage(e.response?.data?.message || text, 'error');

const sharedIds = computed(() => new Set((state.value?.documents || []).map((d) => d.document_id)));
const shareable = computed(() => docs.value.filter((d) => !sharedIds.value.has(d.id)));

const scrollThread = () => nextTick(() => { if (threadRef.value) threadRef.value.scrollTop = threadRef.value.scrollHeight; });

const load = () => Promise.all([
    axios.get(`/api/v1/cases/${props.caseId}/portal`),
    axios.get(`/api/v1/documents/${props.caseId}`, { params: { page: 1, per_page: 100 } }),
]).then(([p, d]) => {
    state.value = p.data.data;
    docs.value = d.data.data?.data || [];
    scrollThread();
}).catch((e) => fail(e, 'Could not load the client portal.'))
    .finally(() => { loading.value = false; });

const setAccess = async (enabled) => {
    if (!(await confirmAction(enabled
        ? { title: 'Give this client portal access?', text: 'They will be texted an invitation to see shared documents and message the firm.', confirmText: 'Give access and invite' }
        : { title: 'Withdraw portal access?', text: 'The client will no longer be able to sign in to the portal or see shared documents.', confirmText: 'Withdraw access', danger: true }))) return;
    busy.value = true;
    axios.put(`/api/v1/clients/${state.value.client.id}/portal`, { enabled })
        .then(({ data }) => { showMessage(data.message); return load(); })
        .catch((e) => fail(e, 'Could not change portal access.'))
        .finally(() => { busy.value = false; });
};

const share = () => {
    busy.value = true;
    axios.post(`/api/v1/cases/${props.caseId}/portal/documents`, { document_id: pick.value, notify: notify.value })
        .then(({ data }) => { state.value.documents = data.data; pick.value = ''; showMessage('Shared with the client.'); })
        .catch((e) => fail(e, 'Could not share the document.'))
        .finally(() => { busy.value = false; });
};

const unshare = async (d) => {
    if (!(await confirmAction({ title: 'Stop sharing this document?', text: `The client will no longer see "${d.title || d.name || 'this document'}" in their portal.`, confirmText: 'Stop sharing', danger: true }))) return;
    axios.delete(`/api/v1/cases/${props.caseId}/portal/documents/${d.id}`)
        .then(({ data }) => { state.value.documents = data.data; })
        .catch((e) => fail(e, 'Could not stop sharing.'));
};

const send = () => {
    busy.value = true;
    axios.post(`/api/v1/cases/${props.caseId}/portal/messages`, { body: reply.value })
        .then(({ data }) => { state.value.messages = data.data; reply.value = ''; scrollThread(); })
        .catch((e) => fail(e, 'Could not send the message.'))
        .finally(() => { busy.value = false; });
};

onMounted(load);
</script>

<style lang="scss" scoped>
.hint, .muted {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.access {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.section {
    font-size: 15px;
    margin: 20px 0 8px;
}

.share, .reply {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 8px;
    align-items: center;
    margin-top: 8px;

    @media (max-width: 720px) {
        grid-template-columns: 1fr;
    }
}

.reply {
    grid-template-columns: 1fr auto;
}

.notify {
    font-size: 13px;
    white-space: nowrap;
}

.thread {
    max-height: 340px;
    overflow-y: auto;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    padding: 10px;
}

.msg {
    max-width: 80%;
    margin: 6px 0;
    padding: 8px 10px;
    border-radius: 10px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;

    &.in { background: var(--surface-muted, #f1f5f9); }
    &.out { background: var(--primary-100); margin-left: auto; }

    .meta {
        font-size: 11px;
        color: var(--text-secondary, #64748b);
        margin-top: 2px;
    }
}
</style>
