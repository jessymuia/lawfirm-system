<template>
    <div class="elevated-card">
        <div class="card-header">
            <h2 class="card-title">Case Documents</h2>
            <button class="btn-add" @click="openAddDocumentModal" data-bs-toggle="modal" data-bs-target="#clientD_modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add Document
            </button>
        </div>

        <div class="card-body">
            <!-- FUN-14: create from a template; send for signature -->
            <DocumentActions ref="actionsRef" :case-id="caseId" @changed="documentsTableRef?.refresh(); emit('changed')" />

            <!-- FUN-8: search inside this matter's documents (OCR'd scans included) -->
            <form class="inside-search" role="search" @submit.prevent="searchInside">
                <input v-model="insideQ" type="search" class="form-control" placeholder="Search inside these documents… (use &quot;quotes&quot; for a phrase)" aria-label="Search inside documents" />
                <button type="submit" class="btn btn-primary" :disabled="insideQ.trim().length < 2">Search</button>
                <button v-if="inside" type="button" class="btn btn-outline-secondary" @click="inside = null; insideQ = ''">Clear</button>
            </form>
            <div v-if="inside" class="inside-results" aria-live="polite">
                <p class="hint">{{ inside.total }} document{{ inside.total === 1 ? '' : 's' }} contain{{ inside.total === 1 ? 's' : '' }} “{{ inside.q }}”.</p>
                <div v-for="d in inside.data" :key="d.id" class="inside-hit">
                    <button type="button" class="btn-link" @click="preview(d.id)">{{ d.title }}</button>
                    <small v-if="d.ocr" class="badge bg-light text-dark ms-1">read by OCR</small>
                    <p class="snippet"><template v-for="(part, i) in marks(d.snippet)" :key="i"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></p>
                </div>
            </div>

            <DataTable
:viewable="false"
                ref="documentsTableRef"
                :fetch-url="`/api/v1/documents/${caseId}`"
                :columns="[
                    { key: 'title', label: 'Title' },
                    { key: 'filename', label: 'Filename' },
                    { key: 'searchable', label: 'Text' },
                ]"
                :sortable-columns="['title']"
                :searchable-columns="['title', 'filename']"
                empty-title="No documents yet"
                empty-description="Add the first document for this case."
                empty-action-label="Add Document"
                @empty-action="openAddDocumentModal(); showModal('clientD_modal')"
            >
                <template #cell-searchable="{ row }">
                    <span v-if="!textStates[row.id] || textStates[row.id].state === 'pending'" class="hint">Reading…</span>
                    <span v-else-if="textStates[row.id].state === 'done'" class="hint" :title="`${textStates[row.id].chars} characters`">Searchable{{ textStates[row.id].method === 'ocr' ? ' (OCR)' : '' }}</span>
                    <span v-else class="hint" :title="textStates[row.id].error || ''">
                        Not searchable
                        <button v-if="canUpload" type="button" class="btn-link" @click="reindex(row.id)">read again</button>
                    </span>
                </template>
                <template #actions="{ row }">
                    <div class="actions text-center">
                        <button type="button" class="btn-action btn-preview" @click="preview(row.id)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            Preview
                        </button>
                        <button type="button" class="btn-action btn-delete" @click="deleteDocument(row)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                            </svg>
                            Delete
                        </button>
                    </div>
                </template>
            </DataTable>
        </div>

        <CrudModal ref="crudModalRef" modal-id="clientD_modal" :title="modalD.title" :submit-label="modalD.button" :form-snapshot="() => ({ title: clientD.title, fileName: clientD.file?.name || null })" @submit="submit_form4D">
            <div class="row">
                <div class="col-md-12 form-group">
                    <label for="document-title">Title</label>
                    <input type="text" v-model="clientD.title" class="form-control"
                        :class="[hasError('title') ? 'is-invalid' : (is_submit_form4D ? (clientD.title ? 'is-valid' : 'is-invalid') : '')]"
                        id="document-title" placeholder="Document Title" @input="clearErrors('title')" />
                    <div class="invalid-feedback">{{ hasError('title') ? fieldError('title') : 'Please fill the title' }}</div>
                </div>
                <div class="error-text text-danger">* Please upload one document at a time</div>
                <div class="col-md-12 form-group">
                    <label for="document-file">File Upload</label>
                    <label class="custom-file-container__custom-file">
                        <input type="file" class="custom-file-container__custom-file__custom-file-input" @change="handleFileInputChange" />
                        <input type="hidden" name="MAX_FILE_SIZE" value="10485760" />
                        <span class="custom-file-container__custom-file__custom-file-control"></span>
                    </label>
                    <div class="custom-file-container__image-preview"></div>
                </div>
                <div class="col-md-12 mt-3">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                            role="progressbar" :style="{ width: progress + '%' }"
                            :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                            {{ progress }}%
                        </div>
                    </div>
                </div>
            </div>
        </CrudModal>
    </div>
</template>

<script setup>
/**
 * UI-10/UI-35: Documents tab — split out of the former view-case.vue as
 * part of the tabbed redesign. Owns its own DataTable (server-driven —
 * see DocumentController::show's conversion to paginatedOrFull() done
 * alongside this) and its own add modal + preview/upload logic.
 */
import { ref } from 'vue';
import axios, { uploadDocument } from '@/api';
import { useToast } from '@/composables/use-toast';
import { useFormErrors } from '@/composables/use-form-errors';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import CrudModal from '@/components/ui/crud-modal.vue';
import DataTable from '@/components/ui/data-table.vue';
import DocumentActions from './document-actions.vue';

const props = defineProps({
    caseId: { type: [Number, String], required: true },
});

const emit = defineEmits(['changed']);

const { showMessage } = useToast();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();
const { confirmDelete } = useConfirmDelete();

const documentsTableRef = ref(null);
const actionsRef = ref(null);
const crudModalRef = ref(null);
const modalD = ref({ title: 'Add Document', button: 'Add' });
const is_submit_form4D = ref(false);
const clientD = ref({ id: '', title: '', case_id: '', file: null });
const selected_file = ref(null);
const uploading = ref(false);
const progress = ref(0);

const showModal = (modalId) => {
    const modalEl = document.getElementById(modalId);
    if (modalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
};

const openAddDocumentModal = () => {
    clearErrors();
    clientD.value = { id: '', title: '', case_id: '', file: null };
    selected_file.value = null;
    modalD.value.title = 'Add Document';
    modalD.value.button = 'Add';
};

// FUN-8
const insideQ = ref('');
const inside = ref(null);
const textStates = ref({});
const canUpload = JSON.parse(localStorage.getItem('permissions') || '[]').includes('create-documents');
const loadTextStates = () => axios.get(`/api/v1/cases/${props.caseId}/document-text`)
    .then(({ data }) => { textStates.value = data.data || {}; }).catch(() => {});
const searchInside = () => {
    const q = insideQ.value.trim();
    axios.get('/api/v1/documents/search', { params: { q, case_id: props.caseId, per_page: 20 } })
        .then(({ data }) => { inside.value = { ...data.data, q }; })
        .catch((e) => showMessage(e.response?.data?.message || 'Search failed.', 'error'));
};
// the server marks matched words «like this»; render them as <mark> without ever parsing HTML
const marks = (snippet) => String(snippet || '').split(/(«[^»]*»)/).filter(Boolean)
    .map((s) => (s.startsWith('«') ? { hit: true, text: s.slice(1, -1) } : { hit: false, text: s }));
const reindex = (id) => axios.post(`/api/v1/documents/${id}/reindex`)
    .then(() => { showMessage('Reading the document again.'); setTimeout(loadTextStates, 1500); })
    .catch((e) => showMessage(e.response?.data?.message || 'Could not read it again.', 'error'));
loadTextStates();

const preview = (id) => {
    axios.get('/api/v1/preview', { params: { id }, responseType: 'blob' })
        .then((response) => {
            const blob = new Blob([response.data], { type: 'application/pdf' });
            const url = window.URL.createObjectURL(blob);
            window.open(url);
        })
        .catch((error) => {
            showMessage('Error viewing document. Please try again.', 'error');
            console.error(error);
        });
};

const deleteDocument = (item) => {
    confirmDelete({
        url: `/api/v1/documents/${item.id}`,
        itemLabel: item.title || 'this document',
        onSuccess: () => { documentsTableRef.value?.refresh(); emit('changed'); },
    });
};

const simulateUpload = () => {
    let interval = setInterval(() => {
        if (progress.value >= 100) {
            clearInterval(interval);
            setTimeout(() => { uploading.value = false; }, 500);
        } else {
            progress.value += 10;
        }
    }, 500);
};

const handleFileInputChange = (event) => {
    selected_file.value = event.target.files[0];
    if (selected_file.value) {
        uploading.value = true;
        progress.value = 0;
        simulateUpload();
    }
};

const submit_form4D = () => {
    is_submit_form4D.value = true;
    clearErrors();
    clientD.value.case_id = props.caseId;
    if (clientD.value.case_id && clientD.value.title) {
        // Document versioning (FUN-5) models a changed document as a new
        // version, not an in-place edit — a single "add" flow is the
        // correct shape here, not a missing feature.
        // ENG-22: straight to the firm's bucket when it has one, otherwise through the API
        uploadDocument({ caseId: clientD.value.case_id, title: clientD.value.title, file: selected_file.value }).then(() => {
            documentsTableRef.value?.refresh();
            emit('changed');
            setTimeout(loadTextStates, 1500);   // FUN-8: text is read in the background
            actionsRef.value?.refresh();       // FUN-14: the new document can be sent for signature
            showMessage('Doc added successfully.');
            crudModalRef.value?.clearDirtyGuard();
            document.getElementById('clientD_modal-closebtn').click();
        })
            .catch((error) => {
                showMessage(setErrorsFromResponse(error, 'Error adding document. Please try again.'), 'error');
            });

        clientD.value.id = '';
        clientD.value.title = '';
        clientD.value.file = null;
        selected_file.value = null;
        modalD.value.title = 'Add Document';
        modalD.value.button = 'Add';
    }
    is_submit_form4D.value = false;
};
</script>

<style scoped>
/* FUN-8 */
.inside-search { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.inside-search input { flex: 1; min-width: 200px; }
.inside-results { margin-bottom: 16px; }
.inside-hit { padding: 8px 0; border-bottom: 1px solid var(--border-color, #e2e8f0); }
.inside-hit .snippet { margin: 4px 0 0; font-size: 13px; color: var(--text-secondary, #475569); overflow-wrap: anywhere; }
.inside-hit mark { padding: 0 2px; background: #fef08a; }
.hint { font-size: 12px; color: var(--text-secondary, #64748b); }
.btn-link { background: none; border: 0; padding: 0; color: var(--primary, var(--primary-700)); cursor: pointer; font-size: inherit; }

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 28px 32px;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
}

.card-title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

.card-body {
    padding: 20px 32px 32px;
}

.btn-add {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    border: none;
    border-radius: var(--radius-md, 8px);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
</style>
