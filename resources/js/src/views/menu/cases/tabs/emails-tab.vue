<template>
    <div class="elevated-card" data-testid="emails-tab">
        <div class="card-header">
            <h2 class="card-title">Emails</h2>
        </div>
        <div class="card-body">
            <div v-if="address" class="address">
                <span class="hint">File email here by forwarding it, or copying (Cc/Bcc) this matter's address:</span>
                <div class="copy">
                    <code>{{ address }}</code>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="copy">Copy</button>
                </div>
            </div>
            <p v-else class="hint">Filing by address is not set up on this installation; you can still upload saved emails (.eml).</p>

            <div class="toolbar">
                <form class="search" role="search" @submit.prevent="load(1)">
                    <input v-model="q" type="search" class="form-control" placeholder="Search subject, sender or text" aria-label="Search emails" />
                    <button type="submit" class="btn btn-outline-secondary">Search</button>
                </form>
                <label v-if="canUpload" class="btn btn-primary upload">
                    Upload .eml
                    <input type="file" accept=".eml,message/rfc822" hidden @change="upload" />
                </label>
            </div>

            <table v-if="emails.length" class="table emails">
                <thead><tr><th>Date</th><th>From</th><th>Subject</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="e in emails" :key="e.id" :class="{ active: open && open.id === e.id }" @click="show(e)">
                        <td class="nowrap">{{ when(e.sent_at || e.created_at) }}</td>
                        <td>{{ e.from_name || e.from_email }}</td>
                        <td>{{ e.subject || '(no subject)' }}<span v-if="Number(e.attachments)" class="badge bg-light text-dark ms-1">{{ e.attachments }} attachment{{ Number(e.attachments) === 1 ? '' : 's' }}</span></td>
                        <td class="hint">{{ e.source === 'upload' ? 'uploaded' : '' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else-if="!loading" class="hint">No emails filed yet.</p>
            <div v-if="last > 1" class="pager">
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="page <= 1" @click="load(page - 1)">Previous</button>
                <span class="hint">Page {{ page }} of {{ last }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="page >= last" @click="load(page + 1)">Next</button>
            </div>

            <article v-if="open" class="message" aria-live="polite">
                <header>
                    <h3>{{ open.subject || '(no subject)' }}</h3>
                    <p class="hint">
                        From {{ open.from_name ? `${open.from_name} <${open.from_email}>` : open.from_email }} · {{ when(open.sent_at) }}<br />
                        To {{ people(open.to) }}<template v-if="open.cc && open.cc.length"> · Cc {{ people(open.cc) }}</template>
                    </p>
                </header>
                <pre class="body">{{ open.body_text }}</pre>
                <p v-if="open.had_html && !open.body_text" class="hint">This message had only a formatted (HTML) version; download the original to see it.</p>
                <div v-if="open.attachments.length" class="attachments">
                    <strong>Attachments (filed as documents):</strong>
                    <button v-for="a in open.attachments" :key="a.id" type="button" class="btn btn-sm btn-outline-primary" @click="preview(a.id)">{{ a.filename }}</button>
                </div>
                <p v-if="open.skipped_attachments.length" class="hint">Not filed: {{ open.skipped_attachments.map((s) => `${s.filename} (${s.reason})`).join(', ') }}</p>
                <p v-if="open.thread.length" class="hint">Same conversation: {{ open.thread.length }} other message{{ open.thread.length === 1 ? '' : 's' }} in this matter.</p>
                <div class="actions">
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="downloadRaw">Download original (.eml)</button>
                    <button v-if="canDelete" type="button" class="btn btn-sm btn-outline-danger" @click="remove">Remove from matter</button>
                </div>
            </article>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/** FUN-15: email filed to this matter (by its address, or uploaded as .eml). Message bodies are shown as text only. */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';
import { useFileDownload } from '@/composables/use-file-download';

const props = defineProps({ caseId: { type: [Number, String], required: true } });
const { showMessage } = useToast();
const { downloadFile } = useFileDownload();
const perms = JSON.parse(localStorage.getItem('permissions') || '[]');
const canUpload = perms.includes('create-documents');
const canDelete = perms.includes('delete-documents');

const address = ref(null);
const emails = ref([]);
const page = ref(1);
const last = ref(1);
const q = ref('');
const open = ref(null);
const loading = ref(true);

const when = (d) => (d ? new Date(String(d).replace(' ', 'T') + (String(d).includes('Z') || String(d).includes('+') ? '' : 'Z')).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
const people = (list) => (list || []).map((p) => p.name || p.email).join(', ');
const fail = (e, text) => showMessage(e.response?.data?.message || text, 'error');

const load = (p = 1) => {
    loading.value = true;
    return axios.get(`/api/v1/cases/${props.caseId}/emails`, { params: { page: p, q: q.value || undefined } })
        .then(({ data }) => {
            address.value = data.data.address;
            emails.value = data.data.emails.data;
            page.value = data.data.emails.current_page;
            last.value = data.data.emails.last_page;
        })
        .catch((e) => fail(e, 'Could not load the emails.'))
        .finally(() => { loading.value = false; });
};
const show = (e) => axios.get(`/api/v1/cases/${props.caseId}/emails/${e.id}`)
    .then(({ data }) => { open.value = data.data; })
    .catch((err) => fail(err, 'Could not open the email.'));
const upload = (ev) => {
    const file = ev.target.files[0];
    ev.target.value = '';
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    axios.post(`/api/v1/cases/${props.caseId}/emails`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(({ data }) => { showMessage(data.message); load(1); })
        .catch((e) => fail(e, 'Could not file the email.'));
};
const remove = async () => {
    if (!(await confirmAction({ title: 'Remove this email from the matter?', text: 'It will be taken out of the matter file. The original stays in your mailbox.', confirmText: 'Yes, remove', danger: true }))) return;
    return axios.delete(`/api/v1/cases/${props.caseId}/emails/${open.value.id}`)
    .then(({ data }) => { showMessage(data.message); open.value = null; load(page.value); })
    .catch((e) => fail(e, 'Could not remove the email.'));
};
const downloadRaw = () => downloadFile(`/api/v1/cases/${props.caseId}/emails/${open.value.id}/raw`, `email-${open.value.id}.eml`);
const preview = (id) => axios.get('/api/v1/preview', { params: { id }, responseType: 'blob' })
    .then((r) => window.open(window.URL.createObjectURL(new Blob([r.data], { type: r.headers['content-type'] || 'application/octet-stream' }))))
    .catch((e) => fail(e, 'Could not open the attachment.'));
const copy = () => navigator.clipboard?.writeText(address.value).then(() => showMessage('Address copied.'));

onMounted(() => load(1));
</script>

<style lang="scss" scoped>
.hint { font-size: 13px; color: var(--text-secondary, #64748b); }
.address { margin-bottom: 12px; }
.copy { display: flex; gap: 8px; align-items: center; margin-top: 4px; flex-wrap: wrap; }
.copy code { padding: 4px 8px; background: var(--surface-muted, #f1f5f9); border-radius: 6px; overflow-wrap: anywhere; }
.toolbar { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
.search { display: flex; gap: 8px; flex: 1; min-width: 220px; }
.upload { margin: 0; cursor: pointer; }
.emails tbody tr { cursor: pointer; }
.emails tr.active { background: var(--primary-100); }
.nowrap { white-space: nowrap; }
.pager { display: flex; gap: 8px; align-items: center; }
.message { margin-top: 16px; border-top: 1px solid var(--border-color, #e2e8f0); padding-top: 12px; }
.message h3 { font-size: 16px; margin: 0 0 4px; }
.body { white-space: pre-wrap; overflow-wrap: anywhere; font: inherit; background: var(--surface-muted, #f8fafc); padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; }
.attachments, .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 8px 0; }
</style>
