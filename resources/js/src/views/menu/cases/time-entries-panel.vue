<template>
    <div class="elevated-card time-entries-card">
        <div class="card-header">
            <h2 class="card-title">Time Entries</h2>
            <div class="header-actions">
                <span class="unbilled-badge" v-if="unbilledTotal > 0">
                    Unbilled: {{ formatMoney(unbilledTotal) }}
                </span>
                <button
                    type="button"
                    class="btn-generate-invoice"
                    :disabled="unbilledTotal <= 0 || generating"
                    @click="generateInvoice"
                >
                    {{ generating ? 'Generating...' : 'Generate Invoice from Unbilled' }}
                </button>
                <button type="button" class="btn-add-entry" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#time_entry_modal">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Log Time
                </button>
            </div>
        </div>

        <div class="card-body">
            <div v-if="loading" class="te-loading">Loading time entries...</div>
            <div v-else-if="!entries.length" class="te-empty">
                No time logged for this case yet.
            </div>
            <div v-else class="custom-table">
                <table class="te-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>User</th>
                            <th>Hours</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th class="actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries" :key="entry.id">
                            <td>{{ entry.date }}</td>
                            <td>{{ entry.description }}</td>
                            <td>{{ entry.user }}</td>
                            <td>{{ entry.hours }}</td>
                            <td>{{ formatMoney(entry.hourly_rate) }}</td>
                            <td>{{ formatMoney(entry.amount) }}</td>
                            <td>
                                <span class="te-status" :class="{ billed: entry.billed, billable: entry.billable && !entry.billed }">
                                    {{ entry.billed ? 'Billed' : (entry.billable ? 'Unbilled' : 'Non-billable') }}
                                </span>
                            </td>
                            <td class="actions-col">
                                <button
                                    type="button"
                                    class="btn-action btn-edit"
                                    :disabled="entry.billed"
                                    @click="update_row(entry)"
                                    data-bs-toggle="modal"
                                    data-bs-target="#time_entry_modal"
                                    title="Edit"
                                    aria-label="Edit time entry"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    class="btn-action btn-delete"
                                    :disabled="entry.billed"
                                    @click="delete_row(entry)"
                                    title="Delete"
                                    aria-label="Delete time entry"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add/Edit modal -->
        <div class="modal fade" id="time_entry_modal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="te_closebtn" type="button" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form @submit.prevent="submitForm">
                            <div class="form-group">
                                <label class="form-label">Description <span class="required">*</span></label>
                                <input type="text" v-model="form.description" class="form-control modern-input"
                                    :class="[hasError('description') ? 'is-invalid' : (submitted ? (form.description ? 'is-valid' : 'is-invalid') : '')]"
                                    placeholder="e.g. Drafted pleadings, client consultation"
                                    @input="clearErrors('description')" />
                                <div class="invalid-feedback">{{ hasError('description') ? fieldError('description') : 'Description is required' }}</div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Date <span class="required">*</span></label>
                                    <AppDatePicker v-model="form.date" :invalid="submitted && !form.date" />
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Hours <span class="required">*</span></label>
                                    <input type="number" step="0.25" min="0.01" max="24" v-model="form.hours" class="form-control modern-input"
                                        :class="[hasError('hours') ? 'is-invalid' : (submitted ? (form.hours ? 'is-valid' : 'is-invalid') : '')]"
                                        @input="clearErrors('hours')" />
                                    <div class="invalid-feedback">{{ hasError('hours') ? fieldError('hours') : 'Hours required' }}</div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Hourly Rate <span class="required">*</span></label>
                                    <input type="number" step="0.01" min="0" v-model="form.hourly_rate" class="form-control modern-input"
                                        :class="[hasError('hourly_rate') ? 'is-invalid' : (submitted ? (form.hourly_rate ? 'is-valid' : 'is-invalid') : '')]"
                                        @input="clearErrors('hourly_rate')" />
                                    <div class="invalid-feedback">{{ hasError('hourly_rate') ? fieldError('hourly_rate') : 'Rate required' }}</div>
                                </div>
                                <div class="form-group checkbox-group">
                                    <label class="form-label">&nbsp;</label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" v-model="form.billable" />
                                        Billable
                                    </label>
                                </div>
                            </div>

                            <!-- FUN-13: UTBMS codes, needed when the client is e-billed (LEDES) -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="te-task">UTBMS task</label>
                                    <select id="te-task" v-model="form.utbms_task_code" class="form-control modern-input">
                                        <option value="">—</option>
                                        <option v-for="t in utbmsTasks" :key="t.code" :value="t.code">{{ t.code }} {{ t.name }}</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="te-activity">UTBMS activity</label>
                                    <select id="te-activity" v-model="form.utbms_activity_code" class="form-control modern-input">
                                        <option value="">—</option>
                                        <option v-for="a in utbmsActivities" :key="a.code" :value="a.code">{{ a.code }} {{ a.name }}</option>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" class="btn-submit">{{ modal.button }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
import { ref, computed, onMounted } from 'vue';
import axios, { fetchAllPages } from '../../../api';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import { useFormErrors } from '@/composables/use-form-errors';
import AppDatePicker from '@/components/ui/app-date-picker.vue';
import { useUtbms } from '@/composables/use-utbms';

// FUN-2 frontend: time tracking / billable hours. Self-contained so it
// can be dropped into view-case.vue without risking the surrounding
// (already large, legacy-pattern) file.
const props = defineProps({
    caseId: { type: [String, Number], required: true },
    clientId: { type: [String, Number], default: null },
});

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const entries = ref([]);
const loading = ref(true);
const generating = ref(false);
const submitted = ref(false);
const modal = ref({ title: 'Log Time', button: 'Save' });
const { tasks: utbmsTasks, activities: utbmsActivities } = useUtbms();
const form = ref({ id: '', description: '', date: '', hours: '', hourly_rate: '', billable: true, utbms_task_code: '', utbms_activity_code: '' });

const unbilledTotal = computed(() =>
    entries.value
        .filter(e => e.billable && !e.billed)
        .reduce((sum, e) => sum + Number(e.amount || 0), 0)
);

const formatMoney = (value) => {
    const n = Number(value) || 0;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const fetchEntries = () => {
    loading.value = true;
    fetchAllPages('/api/v1/time-entries', { case_id: props.caseId })
        .then(({ rows }) => {
            entries.value = rows;
        })
        .catch(error => {
            console.error('Error fetching time entries:', error);
            showMessage('Could not load time entries. Please try refreshing the page.', 'error');
        })
        .finally(() => {
            loading.value = false;
        });
};

const openAddModal = () => {
    clearErrors();
    form.value = { id: '', description: '', date: '', hours: '', hourly_rate: '', billable: true, utbms_task_code: '', utbms_activity_code: '' };
    submitted.value = false;
    modal.value = { title: 'Log Time', button: 'Save' };
};

const update_row = (entry) => {
    if (entry.billed) return;
    clearErrors();
    form.value = {
        id: entry.id,
        description: entry.description,
        date: entry.date,
        hours: entry.hours,
        hourly_rate: entry.hourly_rate,
        billable: entry.billable,
        utbms_task_code: entry.utbms_task_code || '',
        utbms_activity_code: entry.utbms_activity_code || '',
    };
    modal.value = { title: 'Edit Time Entry', button: 'Update' };
};

const submitForm = () => {
    submitted.value = true;
    clearErrors();

    if (!form.value.description || !form.value.date || !form.value.hours || !form.value.hourly_rate) return;

    const payload = {
        case_id: props.caseId,
        description: form.value.description,
        date: form.value.date,
        hours: form.value.hours,
        hourly_rate: form.value.hourly_rate,
        billable: form.value.billable,
        utbms_task_code: form.value.utbms_task_code || null,
        utbms_activity_code: form.value.utbms_activity_code || null,
    };

    const isUpdate = form.value.id;
    const request = isUpdate
        ? axios.put(`/api/v1/time-entries/${form.value.id}`, payload)
        : axios.post('/api/v1/time-entries', payload);

    request
        .then(response => {
            const returned = response.data.data;
            if (isUpdate) {
                const idx = entries.value.findIndex(row => row.id === returned.id);
                if (idx !== -1) entries.value[idx] = returned;
            } else {
                entries.value.unshift(returned);
            }
            showMessage(`Time entry ${isUpdate ? 'updated' : 'logged'} successfully.`);
            document.getElementById('te_closebtn').click();
        })
        .catch(error => {
            showMessage(setErrorsFromResponse(error, `Error ${isUpdate ? 'updating' : 'logging'} time entry.`), 'error');
        });
};

const delete_row = (entry) => {
    if (entry.billed) return;
    confirmDelete({
        url: `/api/v1/time-entries/${entry.id}`,
        itemLabel: `this time entry (${entry.hours} hrs)`,
        onSuccess: (data) => {
            entries.value = entries.value.filter(row => row.id !== data.id);
        },
    });
};

const generateInvoice = async () => {
    if (!props.clientId) {
        showMessage('Cannot generate an invoice: this case has no client on file.', 'error');
        return;
    }
    if (!(await confirmAction({ title: 'Create an invoice from unbilled time?', text: 'All unbilled time entries on this case will be added to a new draft invoice and marked as billed.', confirmText: 'Create draft invoice' }))) return;

    generating.value = true;
    axios.post('/api/v1/time-entries/generate-invoice', {
        case_id: props.caseId,
        client_id: props.clientId,
    })
        .then(() => {
            showMessage('Draft invoice generated from unbilled time.');
            fetchEntries();
        })
        .catch(error => {
            showMessage(error.response?.data?.message || 'Error generating invoice.', 'error');
        })
        .finally(() => {
            generating.value = false;
        });
};

onMounted(() => {
    fetchEntries();
});
</script>

<style lang="scss" scoped>
.time-entries-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.unbilled-badge {
    font-size: 12px;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(245, 158, 11, 0.12);
    color: #f59e0b;
}

.btn-generate-invoice, .btn-add-entry {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-generate-invoice {
    background: rgba(2, 132, 199, 0.1);
    color: var(--primary-600);

    &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
}

.btn-add-entry {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
}

.te-loading, .te-empty {
    padding: 24px 0;
    text-align: center;
    color: #6b7280;
    font-size: 13px;
}

.te-table {
    width: 100%;
    border-collapse: collapse;

    th, td {
        padding: 10px 12px;
        text-align: left;
        font-size: 13px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    }

    th {
        font-size: 11px;
        text-transform: uppercase;
        color: #6b7280;
        font-weight: 600;
    }
}

.actions-col {
    white-space: nowrap;
    text-align: center;
}

.te-status {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
    background: rgba(107, 114, 128, 0.1);
    color: #6b7280;

    &.billable {
        background: rgba(245, 158, 11, 0.12);
        color: #f59e0b;
    }

    &.billed {
        background: rgba(16, 185, 129, 0.1);
        color: var(--primary-600);
    }
}

.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    margin: 0 2px;

    &.btn-edit {
        background: #fef3c7;
        color: #f59e0b;
    }

    &.btn-delete {
        background: #fee2e2;
        color: #e7515a;
    }

    &:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
}

.required {
    color: #e7515a;
}

.checkbox-group {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
}

.btn-submit {
    width: 100%;
    padding: 10px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.invalid-feedback {
    display: none;
    font-size: 12px;
    color: #e7515a;
    margin-top: 4px;
}

.is-invalid ~ .invalid-feedback {
    display: block;
}
</style>
