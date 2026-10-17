<template>
    <div class="elevated-card" data-testid="parties-tab">
        <div class="card-header">
            <h2 class="card-title">Parties</h2>
        </div>
        <div class="card-body">
            <p class="hint">
                Everyone this matter is against or about. Conflict checks for new clients and matters search these names
                across the whole firm, so keep them complete.
            </p>

            <SkeletonLoader v-if="loading" :rows="3" />
            <table v-else-if="parties.length" class="table parties">
                <thead>
                    <tr><th>Name</th><th>Role</th><th>ID / registration</th><th>Notes</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in parties" :key="p.id">
                        <td>{{ p.name }}</td>
                        <td>{{ roleLabel(p.role) }}<small v-if="p.from_opposing_party" class="muted"> (opposing party field)</small></td>
                        <td>{{ p.identifier || '—' }}</td>
                        <td>{{ p.notes || '' }}</td>
                        <td class="row-actions-cell">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="detail = p">View</button>
                            <template v-if="canEdit && !p.from_opposing_party">
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="startEdit(p)">Edit</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" @click="remove(p)">Delete</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="hint">No parties recorded.</p>

            <form v-if="canEdit" class="add-party" @submit.prevent="add">
                <select v-model="form.role" class="form-control" aria-label="Role">
                    <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
                <input v-model="form.name" class="form-control" placeholder="Name" aria-label="Name" required />
                <input v-model="form.identifier" class="form-control" placeholder="ID / registration no. (optional)" aria-label="ID or registration number" />
                <button type="submit" class="btn btn-primary" :disabled="saving || form.name.trim().length < 2">{{ editingId ? 'Save changes' : 'Add party' }}</button>
                <button v-if="editingId" type="button" class="btn btn-outline-secondary" @click="cancelEdit">Cancel</button>
            </form>

            <div v-if="detail" class="party-overlay" @click.self="detail = null">
                <div class="party-dialog" role="dialog" aria-modal="true">
                    <div class="d-head"><h3>{{ detail.name }}</h3><button type="button" class="btn-close-modern" aria-label="Close" @click="detail = null">×</button></div>
                    <dl>
                        <dt>Role</dt><dd>{{ roleLabel(detail.role) }}</dd>
                        <dt>ID / registration</dt><dd>{{ detail.identifier || '—' }}</dd>
                        <dt>Notes</dt><dd>{{ detail.notes || '—' }}</dd>
                    </dl>
                    <div class="d-foot"><button type="button" class="btn btn-outline-secondary" @click="detail = null">Close</button></div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/** FUN-9: the matter's parties register (adverse, co-party, related, opposing counsel). */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';
import SkeletonLoader from '@/components/ui/skeleton-loader.vue';

const props = defineProps({ caseId: { type: [Number, String], required: true } });
const { showMessage } = useToast();

const roles = [
    { value: 'adverse', label: 'Adverse party' },
    { value: 'co_party', label: 'Co-party' },
    { value: 'related', label: 'Related party' },
    { value: 'opposing_counsel', label: 'Opposing counsel' },
];
const roleLabel = (r) => roles.find((x) => x.value === r)?.label || r;
const canEdit = JSON.parse(localStorage.getItem('permissions') || '[]').includes('update-cases');

const parties = ref([]);
const loading = ref(true);
const saving = ref(false);
const form = ref({ role: 'adverse', name: '', identifier: '' });

const load = () => axios.get(`/api/v1/cases/${props.caseId}/parties`)
    .then(({ data }) => { parties.value = data.data; })
    .finally(() => { loading.value = false; });

const detail = ref(null);
const editingId = ref(null);
const startEdit = (p) => {
    editingId.value = p.id;
    form.value = { role: p.role, name: p.name, identifier: p.identifier || '' };
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
};
const cancelEdit = () => { editingId.value = null; form.value = { role: 'adverse', name: '', identifier: '' }; };

const add = () => {
    saving.value = true;
    const body = { ...form.value, identifier: form.value.identifier || null };
    const req = editingId.value
        ? axios.put(`/api/v1/cases/${props.caseId}/parties/${editingId.value}`, body)
        : axios.post(`/api/v1/cases/${props.caseId}/parties`, body);
    req
        .then(() => {
            const keepRole = form.value.role;
            editingId.value = null;
            form.value = { role: keepRole, name: '', identifier: '' };
            load();
        })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not add the party.', 'error'))
        .finally(() => { saving.value = false; });
};

const remove = async (p) => {
    if (!(await confirmAction({ title: `Remove ${p.name}?`, text: `${p.name} will be removed from this matter's parties. Future conflict checks will no longer match this name.`, confirmText: 'Yes, remove', danger: true }))) return;
    axios.delete(`/api/v1/cases/${props.caseId}/parties/${p.id}`)
        .then(load)
        .catch((e) => showMessage(e.response?.data?.message || 'Could not remove the party.', 'error'));
};

onMounted(load);
</script>

<style lang="scss" scoped>
.hint, .muted {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.row-actions-cell { white-space: nowrap; display: flex; gap: 6px; }
.party-overlay { position: fixed; inset: 0; background: rgba(15, 30, 24, .45); display: grid; place-items: center; z-index: 2000; }
.party-dialog { background: #fff; border-radius: 14px; padding: 20px; width: min(440px, 92vw); box-shadow: 0 20px 50px rgba(0,0,0,.25);
    .d-head { display: flex; justify-content: space-between; align-items: center; h3 { margin: 0; font-size: 18px; } }
    .btn-close-modern { width: 36px; height: 36px; border: 1px solid #cfd8d3; border-radius: 8px; background: #fff; font-size: 22px; line-height: 1; cursor: pointer; color: #3c4a44; }
    dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; margin: 16px 0; }
    dt { color: #64748b; font-weight: 500; } dd { margin: 0; }
    .d-foot { text-align: right; }
}

.add-party {
    display: grid;
    grid-template-columns: 180px 1fr 1fr auto;
    gap: 8px;
    margin-top: 12px;

    @media (max-width: 720px) {
        grid-template-columns: 1fr;
    }
}
</style>
