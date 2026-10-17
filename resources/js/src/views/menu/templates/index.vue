<template>
    <div class="layout-px-spacing">
        <div class="page-header-modern">
            <h1 class="page-title">Document templates</h1>
            <p class="page-subtitle">Word templates the firm fills in from a matter: letters, pleadings, agreements.</p>
        </div>

        <div class="elevated-card" data-testid="templates">
            <div class="card-header"><h2 class="card-title">Templates</h2></div>
            <div class="card-body">
                <table v-if="templates.length" class="table">
                    <thead><tr><th>Name</th><th>Category</th><th>Questions asked</th><th>Version</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="t in templates" :key="t.id">
                            <td>
                                {{ t.name }}<div class="hint">{{ t.description }}</div>
                                <div v-if="t.unknown_fields.length" class="text-warning hint">Unknown fields (check the spelling): {{ t.unknown_fields.join(', ') }}</div>
                            </td>
                            <td>{{ t.category || '—' }}</td>
                            <td>{{ t.questions.join(', ') || '—' }}</td>
                            <td>{{ t.version }}</td>
                            <td class="actions">
                                <button v-if="canManage" type="button" class="btn btn-sm btn-outline-secondary" @click="edit(t)">Edit</button>
                                <button v-if="canManage" type="button" class="btn btn-sm btn-outline-secondary" @click="download(t)">Download</button>
                                <button v-if="canManage" type="button" class="btn btn-sm btn-outline-danger" @click="archive(t)">Archive</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="hint">No templates yet.</p>

                <form v-if="canManage" class="template-form" @submit.prevent="save">
                    <h3>{{ form.id ? 'Edit template' : 'Add a template' }}</h3>
                    <input v-model="form.name" class="form-control" placeholder="Name, e.g. Demand letter" required maxlength="191" aria-label="Template name" />
                    <input v-model="form.category" class="form-control" placeholder="Category (optional)" maxlength="64" aria-label="Category" />
                    <input v-model="form.description" class="form-control" placeholder="Description (optional)" maxlength="500" aria-label="Description" />
                    <input ref="fileRef" type="file" accept=".docx" class="form-control" :required="!form.id" aria-label="Word file (.docx)" />
                    <div class="buttons">
                        <button type="submit" class="btn btn-primary">{{ form.id ? 'Save' : 'Add template' }}</button>
                        <button v-if="form.id" type="button" class="btn btn-outline-secondary" @click="reset">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="elevated-card" data-testid="template-fields">
            <div class="card-header"><h2 class="card-title">Fields you can use</h2></div>
            <div class="card-body">
                <p class="hint">Type a field in the Word document with double braces, e.g. <code v-pre>{{ client.name }}</code>. To ask the person creating the document, write <code v-pre>{{ ask: Purchase price }}</code>.</p>
                <table class="table table-sm">
                    <tbody><tr v-for="(label, key) in known" :key="key"><td><code>{{ open }} {{ key }} {{ close }}</code></td><td>{{ label }}</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/** FUN-14: the firm's document templates (manage-document-templates to change them). */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useFileDownload } from '@/composables/use-file-download';

useMeta({ title: 'Document templates' });
const { showMessage } = useToast();
const { downloadFile } = useFileDownload();
const canManage = JSON.parse(localStorage.getItem('permissions') || '[]').includes('manage-document-templates');
const open = '{{';
const close = '}}';

const templates = ref([]);
const known = ref({});
const fileRef = ref(null);
const blank = () => ({ id: null, name: '', category: '', description: '' });
const form = ref(blank());
const fail = (e, t) => showMessage(e.response?.data?.message || t, 'error');

const load = () => axios.get('/api/v1/document-templates').then(({ data }) => {
    templates.value = data.data.templates;
    known.value = data.data.known_fields;
}).catch((e) => fail(e, 'Could not load the templates.'));
const reset = () => { form.value = blank(); if (fileRef.value) fileRef.value.value = ''; };
const edit = (t) => { form.value = { id: t.id, name: t.name, category: t.category || '', description: t.description || '' }; };
const save = () => {
    const fd = new FormData();
    ['name', 'category', 'description'].forEach((k) => fd.append(k, form.value[k] || ''));
    if (fileRef.value?.files[0]) fd.append('file', fileRef.value.files[0]);
    const url = form.value.id ? `/api/v1/document-templates/${form.value.id}` : '/api/v1/document-templates';
    axios.post(url, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(({ data }) => {
            showMessage(data.data.unknown_fields?.length ? `Saved. Check these fields: ${data.data.unknown_fields.join(', ')}` : data.message);
            reset();
            load();
        })
        .catch((e) => fail(e, 'Could not save the template.'));
};
const archive = async (t) => {
    if (!(await confirmAction({ title: `Archive "${t.name || t.title || 'this template'}"?`, text: 'It will no longer be offered when drafting documents. Documents already made from it are not affected.', confirmText: 'Archive template', danger: true }))) return;
    return axios.delete(`/api/v1/document-templates/${t.id}`).then(load).catch((e) => fail(e, 'Could not archive it.'));
};
const download = (t) => downloadFile(`/api/v1/document-templates/${t.id}/download`, t.filename);

onMounted(load);
</script>

<style lang="scss" scoped>
.hint { font-size: 13px; color: var(--text-secondary, #64748b); }
.actions { display: flex; gap: 6px; flex-wrap: wrap; }
.template-form { display: grid; gap: 8px; margin-top: 16px; max-width: 560px; }
.template-form h3 { font-size: 15px; margin: 0; }
.buttons { display: flex; gap: 8px; }
</style>
