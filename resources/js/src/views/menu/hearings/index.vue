<template>
    <div class="modern-hearings-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Hearings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-card">
            <div class="header-content">
                <div class="header-title">
                    <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                        <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h4>Hearings Management</h4>
                </div>
                <button type="button" class="btn-add-hearing" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Hearing
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/hearings"
                    table-id="hearings-list"
                    :selectable="true"
                    :columns="[
                        { key: 'case', label: 'Case' },
                        { key: 'court', label: 'Court' },
                        { key: 'hearing_date', label: 'Hearing Date' },
                        { key: 'hearing_type_name', label: 'Type' },
                        { key: 'notes', label: 'Notes' },
                        { key: 'outcome', label: 'Outcome' },
                    ]"
                    :sortable-columns="['hearing_date']"
                    :searchable-columns="['notes', 'outcome']"
                    empty-title="No hearings yet"
                    empty-description="Add your first hearing to get started."
                    empty-action-label="Add New Hearing"
                    @empty-action="openAddModal"
                >
                    <template #bulk-actions="{ selectedIds, clearSelection }">
                        <button type="button" class="btn-toolbar" @click="bulkDeleteHearings(selectedIds, clearSelection)">Delete Selected</button>
                    </template>
                    <template #cell-case="{ row }">
                        <a href="javascript:;" class="case-badge cross-link" @click="goToCase(row.case_id)">{{ row.case }}</a>
                    </template>
                    <template #cell-court="{ row }">
                        <div class="court-name">
                            <svg class="court-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M3 21h18M4 21V10l8-7 8 7v11M9 21v-6h6v6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            {{ row.court }}
                        </div>
                    </template>
                    <template #cell-hearing_date="{ row }">
                        <div class="date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2"/>
                            </svg>
                            {{ row.hearing_date }}
                        </div>
                    </template>
                    <template #cell-hearing_type_name="{ row }">
                        <span class="type-badge">{{ row.hearing_type_name }}</span>
                    </template>
                    <template #actions="{ row }">
                        <div class="actions text-center">
                            <a href="javascript:;" class="cancel me-1" @click="update_row(row)">
                                <button type="button" class="btn-edit" data-bs-toggle="modal" data-bs-target="#client_modal">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Edit
                                </button>
                            </a>
                            <a href="javascript:;" class="cancel" @click="delete_row(row)">
                                <button type="button" class="btn-delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <polyline points="3 6 5 6 21 6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Delete
                                </button>
                            </a>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <div class="modal fade" id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="modern-form" novalidate @submit.stop.prevent="submit_form4">
                            <div class="form-section">
                                <h5 class="section-title">Hearing Details</h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Case *</label>
                                        <SearchSelect
                                            fetch-url="/api/v1/casesDropDown"
                                            v-model="client.case_id"
                                            :initial-label="client.case_name"
                                            placeholder="Search cases..."
                                            :invalid="is_submit_form4 && !client.case_id"
                                        />
                                        <div class="invalid-feedback" :style="is_submit_form4 && !client.case_id ? 'display:block' : ''">Please select the case</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Court *</label>
                                        <SearchSelect
                                            fetch-url="/api/v1/courts"
                                            :paginated="true"
                                            v-model="client.court_id"
                                            :initial-label="client.court_name"
                                            placeholder="Search courts..."
                                            :invalid="is_submit_form4 && !client.court_id"
                                        />
                                        <div class="invalid-feedback" :style="is_submit_form4 && !client.court_id ? 'display:block' : ''">Please select the court</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Hearing Date *</label>
                                        <AppDatePicker v-model="client.hearing_date"
                                               :invalid="is_submit_form4 && !client.hearing_date" />
                                        <div class="invalid-feedback">Please provide a valid date</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Hearing Type *</label>
                                        <select v-model="client.hearing_type" class="modern-select" :class="[is_submit_form4 ? (client.hearing_type ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Hearing Type</option>
                                            <option v-for="data in types" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the hearing type</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Additional Information</h5>
                                <div class="row">
                                    <div class="col-md-12 form-group">
                                        <label class="form-label">Notes *</label>
                                        <textarea v-model="client.notes" rows="3" class="modern-input"
                                                  :class="[is_submit_form4 ? (client.notes ? 'is-valid' : 'is-invalid') : '']"
                                                  placeholder="Enter hearing notes, agenda, or relevant details"></textarea>
                                        <div class="invalid-feedback">Please fill the notes</div>
                                    </div>

                                    <div class="col-md-12 form-group">
                                        <label class="form-label">Outcome *</label>
                                        <textarea v-model="client.outcome" rows="3" class="modern-input"
                                                  :class="[is_submit_form4 ? (client.outcome ? 'is-valid' : 'is-invalid') : '']"
                                                  placeholder="Enter hearing outcome or decision"></textarea>
                                        <div class="invalid-feedback">Please fill the outcome</div>
                                    </div>

                                    <!-- FUN-16: court diary details (optional) -->
                                    <div class="col-md-4 form-group">
                                        <label class="form-label" for="client-time">Time</label>
                                        <input id="client-time" v-model="client.start_time" type="time" class="modern-input" />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label class="form-label" for="client-duration">Length (minutes)</label>
                                        <input id="client-duration" v-model="client.duration_minutes" type="number" min="5" max="720" step="5" class="modern-input" placeholder="60" />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label class="form-label" for="client-courtroom">Courtroom</label>
                                        <input id="client-courtroom" v-model="client.courtroom" type="text" maxlength="64" class="modern-input" placeholder="e.g. Court 4" />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="form-label" for="client-officer">Before (judge / magistrate)</label>
                                        <input id="client-officer" v-model="client.judicial_officer" type="text" maxlength="120" class="modern-input" />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="form-label" for="client-efiling">E-filing reference</label>
                                        <input id="client-efiling" v-model="client.efiling_reference" type="text" maxlength="64" class="modern-input" placeholder="Judiciary e-filing case number" />
                                    </div>
                                </div>
                            </div>

                            <button class="btn-submit" type="submit">{{ modal.button }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import AppDatePicker from '@/components/ui/app-date-picker.vue';
import { useFormErrors } from '@/composables/use-form-errors';
import DataTable from '@/components/ui/data-table.vue';
import SearchSelect from '@/components/ui/search-select.vue';
import { useModalDirtyGuard } from '@/composables/use-modal-dirty-guard';

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { setErrorsFromResponse, clearErrors } = useFormErrors();
useMeta({ title: 'Hearings' });

const tableRef = ref(null);

const bulkDeleteHearings = (selectedIds, clearSelection) => {
    if (!selectedIds.length) return;

    confirmAction({
        title: `Delete ${selectedIds.length} hearing${selectedIds.length > 1 ? 's' : ''}?`,
        text: 'This will delete every selected hearing. This can be undone by an administrator.',
        confirmText: 'Yes, delete all',
        danger: true,
    }).then((ok) => {
        if (!ok) return;

        axios.post('/api/v1/hearings/bulk-delete', { ids: selectedIds })
            .then(() => {
                showMessage(`Deleted ${selectedIds.length} hearing${selectedIds.length > 1 ? 's' : ''}.`);
                clearSelection();
                tableRef.value?.refresh();
            })
            .catch((error) => {
                const message = error.response?.data?.message || 'Error deleting selected hearings. Please try again.';
                showMessage(message, 'error');
            });
    });
};
const types = ref([]);
const modal = ref({title: "Add Hearing", button: "Add"});

onMounted(() => {
    fetchDropdowns();
});

const router = useRouter();

// UI-9: the case-details route has no id URL param — it reads
// localStorage (see cases/list-cases.vue's view_row) — so a cross-link
// to a case follows that same pattern rather than a plain router-link.
const goToCase = (caseId) => {
    if (!caseId) return;
    localStorage.setItem('caseId', caseId);
    router.push('/view-case');
};
const is_submit_form4 = ref(false);
const client = ref({id: "",case_id:"",case_name:"",court_id : "",court_name:"", hearing_date:"",hearing_type:"", notes:"", outcome:"", start_time: "", duration_minutes: "", courtroom: "", judicial_officer: "", efiling_reference: ""});

// UI-15: closing this modal with unsaved edits now asks for confirmation.
// Bug fix (found via a real E2E run): clearDirtyGuard() must be called
// on a successful save, before the modal auto-closes — see
// list-clients.vue's identical fix for the full explanation.
const { clear: clearDirtyGuard } = useModalDirtyGuard('client_modal', () => ({ ...client.value }));

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.case_id && client.value.hearing_type && client.value.hearing_date && client.value.court_id) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/hearings/${client.value.id}`, client.value)
                .then(() => {
                    showMessage('Hearing updated successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating hearing. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/hearings', client.value)
                .then(() => {
                    showMessage('Hearing added successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding hearing. Please try again.'), 'error');
                });
        }

        client.value.id = "";
        client.value.case_id = "";
        client.value.case_name = "";
        client.value.court_id = "";
        client.value.court_name = "";
        client.value.hearing_date = "";
        client.value.hearing_type = "";
        client.value.notes = "";
        client.value.outcome = "";
        Object.assign(client.value, { start_time: "", duration_minutes: "", courtroom: "", judicial_officer: "", efiling_reference: "" });
        modal.value.title = "Add Hearing";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const openAddModal = () => {
    client.value = {id: "",case_id:"",case_name:"",court_id : "",court_name:"", hearing_date:"",hearing_type:"", notes:"", outcome:"", start_time: "", duration_minutes: "", courtroom: "", judicial_officer: "", efiling_reference: ""};
    modal.value.title = "Add Hearing";
    modal.value.button = "Add";
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};

const fetchDropdowns = () => {
    axios.get('/api/v1/hearingtypes/options')
        .then(response => {
            types.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching types:', error);
            showMessage('Could not load types. Please try refreshing the page.', 'error');
        });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.case_id = item.case_id;
    client.value.case_name = item.case;
    client.value.court_id = item.court_id;
    client.value.court_name = item.court;
    client.value.hearing_date = item.hearing_date;
    client.value.notes = item.notes;
    client.value.outcome = item.outcome;
    client.value.start_time = item.start_time || "";
    client.value.duration_minutes = item.duration_minutes || "";
    client.value.courtroom = item.courtroom || "";
    client.value.judicial_officer = item.judicial_officer || "";
    client.value.efiling_reference = item.efiling_reference || "";
    client.value.hearing_type = item.hearing_type;

    modal.value.title = "Update Hearing";
    modal.value.button = "Update";
};

const delete_row = (item) => {
    confirmDelete({
        url: `/api/v1/hearings/${item.id}`,
        itemLabel: `the hearing for ${item.case} on ${item.hearing_date}`,
        onSuccess: () => { tableRef.value?.refresh(); },
        restoreUrl: `/api/v1/hearings/${item.id}/restore`,
        onRestore: () => { tableRef.value?.refresh(); },
    });
};
</script>

<style lang="scss" scoped>
.modern-hearings-page {
    padding: 20px;
    min-height: 100vh;
}

.cross-link {
    color: inherit;
    text-decoration: none;
    cursor: pointer;

    &:hover {
        color: var(--primary-600);
        text-decoration: underline;
    }
}

.page-header-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-icon {
    width: 48px;
    height: 48px;
    padding: 12px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border-radius: 12px;
    flex-shrink: 0;
}

.header-title h4 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
    color: #1f2937;
}

.btn-add-hearing {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(20, 184, 166, 0.3);
}

.btn-add-hearing:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(20, 184, 166, 0.4);
}

.btn-add-hearing svg {
    width: 20px;
    height: 20px;
    stroke-width: 2.5;
}

.table-container {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.table-card {
    overflow-x: auto;
}

.case-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #ccfbf1;
    color: #115e59;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.court-name {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #374151;
    font-size: 14px;
}

.court-icon {
    width: 18px;
    height: 18px;
    color: var(--primary-600);
    flex-shrink: 0;
    stroke-width: 2;
}

.date-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
    font-size: 14px;
}

.date-cell svg {
    width: 16px;
    height: 16px;
    color: var(--primary-600);
    stroke-width: 2;
}

.type-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #99f6e4;
    color: #134e4a;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-edit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(20, 184, 166, 0.3);
}

.btn-edit svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.btn-delete {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #e7515a, #c53040);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-delete:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(231, 81, 90, 0.3);
}

.btn-delete svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.modern-modal .modal-header {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border-radius: 12px 12px 0 0;
    padding: 20px 24px;
    border: none;
}

.modern-modal .modal-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
}

.modern-modal .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.8;
}

.modern-modal .btn-close:hover {
    opacity: 1;
}

.modern-modal .modal-body {
    padding: 32px;
}

.modern-form {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.form-section {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.section-title {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
    padding-bottom: 12px;
    border-bottom: 2px solid #f3f4f6;
}

.form-group {
    margin-bottom: 0;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #374151;
    font-size: 14px;
}

.modern-input,
.modern-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: white;
}

textarea.modern-input {
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
}

.modern-input:focus,
.modern-select:focus {
    outline: none;
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.1);
}

.modern-input.is-invalid,
.modern-select.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid,
.modern-select.is-valid {
    border-color: var(--primary-600);
}

.invalid-feedback {
    display: none;
    font-size: 12px;
    color: #ef4444;
    margin-top: 6px;
}

.is-invalid ~ .invalid-feedback {
    display: block;
}

.btn-submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 32px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(20, 184, 166, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(20, 184, 166, 0.4);
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-add-hearing {
        justify-content: center;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }
}
</style>