<template>
    <div class="doc-actions" data-testid="document-actions">
        <div class="bar">
            <button v-if="canCreate && templates.length" type="button" class="btn btn-outline-primary btn-sm" @click="assembling = !assembling">Create from template</button>
            <button v-if="canCreate" type="button" class="btn btn-outline-primary btn-sm" @click="signing = !signing">Send for signature</button>
        </div>

        <!-- FUN-14: a new document from a template -->
        <form v-if="assembling" class="panel" @submit.prevent="assemble">
            <h3>Create from a template</h3>
            <select v-model="pick" class="form-control" required aria-label="Template">
                <option value="">Choose a template…</option>
                <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.category ? `${t.category}: ` : '' }}{{ t.name }}</option>
            </select>
            <template v-if="chosen">
                <label v-for="q in chosen.questions" :key="q">{{ q }}<input v-model="answers[q]" class="form-control" required maxlength="5000" /></label>
                <label>Title (optional)<input v-model="title" class="form-control" maxlength="255" :placeholder="`${chosen.name} – …`" /></label>
            </template>
            <div class="buttons"><button type="submit" class="btn btn-primary" :disabled="!pick || busy">Create document</button></div>
        </form>

        <!-- FUN-14: send a document for signature -->
        <form v-if="signing" class="panel" @submit.prevent="send">
            <h3>Send for signature</h3>
            <select v-model="sig.document_id" class="form-control" required aria-label="Document">
                <option value="">Choose the document…</option>
                <option v-for="d in documents" :key="d.id" :value="d.id">{{ d.title }} ({{ d.filename }})</option>
            </select>
            <input v-model="sig.title" class="form-control" required maxlength="255" placeholder="What is being signed" aria-label="Title" />
            <textarea v-model="sig.message" class="form-control" rows="2" maxlength="1000" placeholder="Message to the signers (optional)" aria-label="Message"></textarea>
            <div v-for="(s, i) in sig.signers" :key="i" class="signer">
                <input v-model="s.name" class="form-control" required placeholder="Signer's name" aria-label="Signer's name" />
                <input v-model="s.email" type="email" class="form-control" placeholder="Email" aria-label="Signer's email" />
                <input v-model="s.phone" class="form-control" placeholder="Phone (SMS)" aria-label="Signer's phone" />
                <button v-if="sig.signers.length > 1" type="button" class="btn btn-sm btn-outline-danger" @click="sig.signers.splice(i, 1)">Remove</button>
            </div>
            <div class="buttons">
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="sig.signers.push({ name: '', email: '', phone: '' })">Add signer</button>
                <label class="check"><input v-model="sig.in_order" type="checkbox" /> Sign one after another</label>
            </div>
            <p class="hint">Each signer gets a personal link by email and/or SMS. When everyone has signed, a signature certificate is added to the documents.</p>
            <div class="buttons"><button type="submit" class="btn btn-primary" :disabled="busy">Send</button></div>
        </form>

        <div v-if="requests.length" class="panel">
            <h3>Signatures</h3>
            <div v-for="r in requests" :key="r.id" class="request">
                <div>
                    <strong>{{ r.title }}</strong> <span class="badge" :class="badge(r.state)">{{ r.state }}</span>
                    <div class="hint">{{ r.filename }} · {{ r.signers.map((s) => `${s.name}: ${s.state}${s.decline_reason ? ` (${s.decline_reason})` : ''}`).join(' · ') }}</div>
                </div>
                <div class="buttons">
                    <button v-if="r.state === 'pending' && canCreate" type="button" class="btn btn-sm btn-outline-secondary" @click="act(r, 'remind')">Remind</button>
                    <button v-if="r.state === 'pending' && canCreate" type="button" class="btn btn-sm btn-outline-danger" @click="act(r, 'cancel')">Cancel</button>
                    <span v-if="r.state === 'completed'" class="hint">Certificate added to the documents</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/** FUN-14: on a matter's Documents tab: make a document from a template; send one for signature and follow it. */
import { computed, onMounted, ref } from 'vue';
import axios, { fetchAllPages } from '@/api';
import { useToast } from '@/composables/use-toast';

const props = defineProps({ caseId: { type: [Number, String], required: true } });
const emit = defineEmits(['changed']);
const { showMessage } = useToast();
const canCreate = JSON.parse(localStorage.getItem('permissions') || '[]').includes('create-documents');

const templates = ref([]);
const documents = ref([]);
const requests = ref([]);
const assembling = ref(false);
const signing = ref(false);
const busy = ref(false);
const pick = ref('');
const answers = ref({});
const title = ref('');
const chosen = computed(() => templates.value.find((t) => t.id === pick.value));
const blankSig = () => ({ document_id: '', title: '', message: '', in_order: false, signers: [{ name: '', email: '', phone: '' }] });
const sig = ref(blankSig());
const fail = (e, t) => showMessage(e.response?.data?.message || t, 'error');
const badge = (s) => ({ completed: 'bg-success', pending: 'bg-info', declined: 'bg-danger' }[s] || 'bg-secondary');

const loadRequests = () => axios.get(`/api/v1/cases/${props.caseId}/signatures`).then(({ data }) => { requests.value = data.data; }).catch(() => {});
const loadDocuments = () => fetchAllPages(`/api/v1/documents/${props.caseId}`).then((rows) => { documents.value = rows; }).catch(() => {});

const assemble = () => {
    busy.value = true;
    axios.post(`/api/v1/cases/${props.caseId}/documents/from-template`, { template_id: pick.value, answers: answers.value, title: title.value || null })
        .then(({ data }) => {
            showMessage(data.message);
            assembling.value = false; pick.value = ''; answers.value = {}; title.value = '';
            emit('changed'); loadDocuments();
        })
        .catch((e) => fail(e, 'Could not create the document.'))
        .finally(() => { busy.value = false; });
};
const send = async () => {
    if (!(await confirmAction({ title: 'Send for signature?', text: `${sig.value.signers.length} signer${sig.value.signers.length === 1 ? '' : 's'} will be messaged a link to sign now.`, confirmText: 'Send now' }))) return;
    busy.value = true;
    const body = { ...sig.value, signers: sig.value.signers.map((s) => ({ name: s.name, email: s.email || null, phone: s.phone || null })) };
    axios.post(`/api/v1/cases/${props.caseId}/signatures`, body)
        .then(({ data }) => { showMessage(data.message); signing.value = false; sig.value = blankSig(); loadRequests(); })
        .catch((e) => fail(e, 'Could not send it for signature.'))
        .finally(() => { busy.value = false; });
};
const act = async (r, what) => {
    const cancel = what === 'cancel';
    if (!(await confirmAction({
        title: cancel ? 'Cancel this signature request?' : 'Send a reminder?',
        text: cancel ? 'Signers will no longer be able to sign this document through the link they were sent.' : 'Everyone who has not yet signed will get another message now.',
        confirmText: cancel ? 'Cancel request' : 'Send reminder', cancelText: cancel ? 'Keep it' : 'Cancel', danger: cancel,
    }))) return;
    return axios.post(`/api/v1/cases/${props.caseId}/signatures/${r.id}/${what}`)
    .then(({ data }) => { showMessage(data.message); loadRequests(); })
    .catch((e) => fail(e, 'Could not do that.'));
};

onMounted(() => {
    axios.get('/api/v1/document-templates').then(({ data }) => { templates.value = data.data.templates; }).catch(() => {});
    loadDocuments();
    loadRequests();
});

defineExpose({ refresh: () => { loadDocuments(); loadRequests(); } });
</script>

<style lang="scss" scoped>
.doc-actions { margin-bottom: 12px; }
.bar, .buttons { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.panel { display: grid; gap: 8px; margin-top: 12px; padding: 12px; border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; }
.panel h3 { font-size: 15px; margin: 0; }
.panel label { font-size: 13px; display: grid; gap: 4px; }
.signer { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 6px; @media (max-width: 720px) { grid-template-columns: 1fr; } }
.check { display: flex !important; gap: 6px; align-items: center; }
.request { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border-color, #e2e8f0); flex-wrap: wrap; }
.hint { font-size: 13px; color: var(--text-secondary, #64748b); }
</style>
