<template>
    <div class="elevated-card">
        <div class="card-header">
            <h2 class="card-title">Case Hearings</h2>
            <button class="btn-add" @click="openAddHearingModal" data-bs-toggle="modal" data-bs-target="#clientH_modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add Hearing
            </button>
        </div>

        <div class="card-body">
            <DataTable
                ref="hearingsTableRef"
                fetch-url="/api/v1/hearings"
                :extra-params="{ case_id: caseId }"
                :columns="[
                    { key: 'court', label: 'Court' },
                    { key: 'hearing_date', label: 'Date' },
                    { key: 'hearing_type_name', label: 'Type' },
                    { key: 'notes', label: 'Notes' },
                    { key: 'outcome', label: 'Outcome' },
                ]"
                :sortable-columns="['hearing_date']"
                :searchable-columns="['notes', 'outcome']"
                empty-title="No hearings yet"
                empty-description="Add the first hearing for this case."
                empty-action-label="Add Hearing"
                @empty-action="openAddHearingModal(); showModal('clientH_modal')"
            >
                <template #actions="{ row }">
                    <div class="actions text-center">
                        <button type="button" class="btn-action btn-edit" @click="update_rowH(row)" data-bs-toggle="modal" data-bs-target="#clientH_modal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Update
                        </button>
                        <button type="button" class="btn-action btn-delete" @click="delete_row(row)">Delete</button>
                    </div>
                </template>
            </DataTable>
        </div>

        <CrudModal ref="crudModalRef" modal-id="clientH_modal" :title="modalH.title" :submit-label="modalH.button" size="xl" :form-snapshot="() => ({ ...clientH })" @submit="submit_form4H">
            <div class="row">
                <div id="select_menu" class="form-group form-group">
                    <SearchSelect
                        fetch-url="/api/v1/courts"
                        :paginated="true"
                        v-model="clientH.court_id"
                        :initial-label="clientH.court_name"
                        placeholder="Search courts..."
                        :invalid="hasError('court_id') || (is_submit_form4H && !clientH.court_id)"
                        @select="clearErrors('court_id')"
                    />
                    <div class="invalid-feedback" :style="hasError('court_id') || (is_submit_form4H && !clientH.court_id) ? 'display:block' : ''">{{ hasError('court_id') ? fieldError('court_id') : 'Please Select the Court' }}</div>
                </div>
                <div class="col-md-12 form-group">
                    <div class="form-group">
                        <label for="hearing-date">Hearing Date (dd/mm/yyyy)</label>
                        <input v-model="clientH.hearing_date" type="text" id="hearing-date" class="form-control"
                               :class="[hasError('hearing_date') ? 'is-invalid' : (is_submit_form4H ? (clientH.hearing_date ? 'is-valid' : 'is-invalid') : '')]"
                               v-maska="'##/##/####'" placeholder="__/__/____" @input="clearErrors('hearing_date')" />
                        <div class="invalid-feedback">{{ hasError('hearing_date') ? fieldError('hearing_date') : 'Please provide a valid date.' }}</div>
                    </div>
                </div>
                <div class="col-md-12 form-group">
                    <div id="select_menu" class="form-group form-group">
                        <select v-model="clientH.hearing_type" class="form-select"
                                :class="[hasError('hearing_type') ? 'is-invalid' : (is_submit_form4H ? (clientH.hearing_type ? 'is-valid' : 'is-invalid') : '')]"
                                @change="clearErrors('hearing_type')">
                            <option value="">Select Hearing Type</option>
                            <option v-for="data in types" :key="data.id" :value="data.id">{{ data.name }}</option>
                        </select>
                        <div class="invalid-feedback">{{ hasError('hearing_type') ? fieldError('hearing_type') : 'Please Select the type' }}</div>
                    </div>
                </div>
                <div class="col-md-12 form-group">
                    <label for="hearing-notes">Notes</label>
                    <input type="text" v-model="clientH.notes" class="form-control"
                        :class="[hasError('notes') ? 'is-invalid' : (is_submit_form4H ? (clientH.notes ? 'is-valid' : 'is-invalid') : '')]"
                        id="hearing-notes" placeholder="Notes" @input="clearErrors('notes')" />
                    <div class="invalid-feedback">{{ hasError('notes') ? fieldError('notes') : 'Please fill the Notes' }}</div>
                </div>
                <div class="col-md-12 form-group">
                    <label for="hearing-outcome">Outcomes</label>
                    <input type="text" v-model="clientH.outcome" class="form-control"
                        :class="[hasError('outcome') ? 'is-invalid' : (is_submit_form4H ? (clientH.outcome ? 'is-valid' : 'is-invalid') : '')]"
                        id="hearing-outcome" placeholder="Description" @input="clearErrors('outcome')" />
                    <div class="invalid-feedback">{{ hasError('outcome') ? fieldError('outcome') : 'Please fill the outcomes' }}</div>
                </div>

                <!-- FUN-16: court diary details (optional) -->
                <div class="col-md-4 form-group">
                    <label class="form-label" for="clientH-time">Time</label>
                    <input id="clientH-time" v-model="clientH.start_time" type="time" class="form-control" />
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label" for="clientH-duration">Length (minutes)</label>
                    <input id="clientH-duration" v-model="clientH.duration_minutes" type="number" min="5" max="720" step="5" class="form-control" placeholder="60" />
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label" for="clientH-courtroom">Courtroom</label>
                    <input id="clientH-courtroom" v-model="clientH.courtroom" type="text" maxlength="64" class="form-control" placeholder="e.g. Court 4" />
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label" for="clientH-officer">Before (judge / magistrate)</label>
                    <input id="clientH-officer" v-model="clientH.judicial_officer" type="text" maxlength="120" class="form-control" />
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label" for="clientH-efiling">E-filing reference</label>
                    <input id="clientH-efiling" v-model="clientH.efiling_reference" type="text" maxlength="64" class="form-control" placeholder="Judiciary e-filing case number" />
                </div>
            </div>
        </CrudModal>
    </div>
</template>

<script setup>
/**
 * UI-10/UI-35: Hearings tab — split out of the former view-case.vue as
 * part of the tabbed redesign. Owns its own DataTable (server-driven,
 * scoped by case_id — see HearingController::index's case_id filter
 * added alongside this conversion) and its own add/edit modal.
 */
import { ref, onMounted } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';
import { useFormErrors } from '@/composables/use-form-errors';
import CrudModal from '@/components/ui/crud-modal.vue';
import SearchSelect from '@/components/ui/search-select.vue';
import DataTable from '@/components/ui/data-table.vue';
import { useConfirmDelete } from '@/composables/use-confirm-delete';

const props = defineProps({
    caseId: { type: [Number, String], required: true },
});

const emit = defineEmits(['changed']);

const { showMessage } = useToast();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const { confirmDelete } = useConfirmDelete();
const hearingsTableRef = ref(null);
const delete_row = (item) => confirmDelete({
    url: `/api/v1/hearings/${item.id}`,
    itemLabel: `the hearing on ${item.hearing_date}`,
    onSuccess: () => { hearingsTableRef.value?.refresh(); emit('changed'); },
    restoreUrl: `/api/v1/hearings/${item.id}/restore`,
    onRestore: () => { hearingsTableRef.value?.refresh(); emit('changed'); },
});
const crudModalRef = ref(null);
const types = ref([]);
const modalH = ref({ title: 'Add Hearing', button: 'Add' });
const is_submit_form4H = ref(false);
const clientH = ref({ id: '', case_id: '', court_id: '', court_name: '', hearing_date: '', hearing_type: '', notes: '', outcome: '', start_time: '', duration_minutes: '', courtroom: '', judicial_officer: '', efiling_reference: '' });

const showModal = (modalId) => {
    const modalEl = document.getElementById(modalId);
    if (modalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
};

const openAddHearingModal = () => {
    clearErrors();
    clientH.value = { id: '', case_id: '', court_id: '', court_name: '', hearing_date: '', hearing_type: '', notes: '', outcome: '', start_time: '', duration_minutes: '', courtroom: '', judicial_officer: '', efiling_reference: '' };
    modalH.value.title = 'Add Hearing';
    modalH.value.button = 'Add';
};

const update_rowH = (item) => {
    clearErrors();
    clientH.value.id = item.id;
    clientH.value.case_id = props.caseId;
    clientH.value.court_id = item.court_id;
    clientH.value.court_name = item.court;
    clientH.value.hearing_date = item.hearing_date;
    clientH.value.notes = item.notes;
    clientH.value.outcome = item.outcome;
    clientH.value.start_time = item.start_time || '';
    clientH.value.duration_minutes = item.duration_minutes || '';
    clientH.value.courtroom = item.courtroom || '';
    clientH.value.judicial_officer = item.judicial_officer || '';
    clientH.value.efiling_reference = item.efiling_reference || '';
    clientH.value.hearing_type = item.hearing_type;
    modalH.value.title = 'Update Hearing';
    modalH.value.button = 'Update';
};

const submit_form4H = () => {
    is_submit_form4H.value = true;
    clearErrors();
    clientH.value.case_id = props.caseId;
    if (clientH.value.case_id && clientH.value.hearing_type && clientH.value.hearing_date && clientH.value.court_id) {
        const request = clientH.value.id > 0
            ? axios.put(`/api/v1/hearings/${clientH.value.id}`, clientH.value)
            : axios.post('/api/v1/hearings', clientH.value);

        request
            .then(() => {
                hearingsTableRef.value?.refresh();
                emit('changed');
                showMessage(clientH.value.id > 0 ? 'Hearing updated successfully.' : 'Hearing added successfully.');
                crudModalRef.value?.clearDirtyGuard();
                document.getElementById('clientH_modal-closebtn').click();
            })
            .catch((error) => {
                showMessage(setErrorsFromResponse(error, 'Error saving hearing. Please try again.'), 'error');
            });

        clientH.value.id = '';
        clientH.value.hearing_date = '';
        clientH.value.hearing_type = '';
        clientH.value.notes = '';
        clientH.value.outcome = '';
        Object.assign(clientH.value, { start_time: '', duration_minutes: '', courtroom: '', judicial_officer: '', efiling_reference: '' });
        modalH.value.title = 'Add Hearing';
        modalH.value.button = 'Add';
    }
    is_submit_form4H.value = false;
};

onMounted(() => {
    axios.get('/api/v1/hearingtypes/options')
        .then((response) => {
            types.value = response.data.data;
        })
        .catch(() => {
            showMessage('Could not load hearing types. Please try refreshing the page.', 'error');
        });
});
</script>

<style scoped>
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
