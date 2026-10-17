<template>
    <div class="modern-expenses-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Expenses</a></li>
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
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <h4>Expenses Management</h4>
                </div>
                <div class="header-button-group">
                    <button type="button" class="btn-export" @click="exportCsv" :disabled="exporting">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="7 10 12 15 17 10" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <line x1="12" y1="15" x2="12" y2="3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        {{ exporting ? 'Exporting...' : 'Export CSV' }}
                    </button>
                    <button type="button" class="btn-add-expense" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#client_modal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Add New Expense
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/expenses"
                    table-id="expenses-list"
                    :selectable="true"
                    :columns="[
                        { key: 'case', label: 'Case' },
                        { key: 'expense_date', label: 'Date' },
                        { key: 'amount', label: 'Amount' },
                        { key: 'category_name', label: 'Category' },
                        { key: 'description', label: 'Description' },
                        { key: 'vendor', label: 'Vendor' },
                        { key: 'payment_method', label: 'Payment Method' },
                        { key: 'invoice_number', label: 'Invoice #' },
                        { key: 'user', label: 'Recorded By' },
                    ]"
                    :sortable-columns="['amount', 'description', 'vendor', 'payment_method', 'invoice_number']"
                    :searchable-columns="['description', 'vendor', 'invoice_number']"
                    empty-title="No expenses yet"
                    empty-description="Add your first expense to get started."
                    empty-action-label="Add New Expense"
                    @empty-action="openAddModal"
                >
                    <template #bulk-actions="{ selectedIds, clearSelection }">
                        <button type="button" class="btn-toolbar" @click="bulkDeleteExpenses(selectedIds, clearSelection)">Delete Selected</button>
                        <button type="button" class="btn-toolbar" @click="bulkExportExpenses(selectedIds)">Export Selected</button>
                    </template>
                    <template #cell-case="{ row }">
                        <div class="case-badge">{{ row.case }}</div>
                    </template>
                    <template #cell-expense_date="{ row }">
                        <div class="date-cell">{{ row.expense_date }}</div>
                    </template>
                    <template #cell-amount="{ row }">
                        <div class="amount-cell">${{ row.amount }}</div>
                    </template>
                    <template #cell-category_name="{ row }">
                        <span class="category-badge">{{ row.category_name }}</span>
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
                                <h5 class="section-title">Expense Details</h5>
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
                                        <label class="form-label">Expense Date *</label>
                                        <AppDatePicker v-model="client.expense_date"
                                               :invalid="is_submit_form4 && !client.expense_date" />
                                        <div class="invalid-feedback">Please provide a valid date</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Amount *</label>
                                        <input type="number" step="0.01" v-model="client.amount" class="modern-input"
                                               :class="[is_submit_form4 ? (client.amount ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="0.00" />
                                        <div class="invalid-feedback">Please fill the amount</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Category *</label>
                                        <select v-model="client.category" class="modern-select" :class="[is_submit_form4 ? (client.category ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Category</option>
                                            <option v-for="data in types" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the category</div>
                                    </div>

                                    <div class="col-md-12 form-group">
                                        <label class="form-label">Description *</label>
                                        <input type="text" v-model="client.description" class="modern-input"
                                               :class="[is_submit_form4 ? (client.description ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="Enter expense description" />
                                        <div class="invalid-feedback">Please fill the description</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Payment Information</h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Vendor *</label>
                                        <input type="text" v-model="client.vendor" class="modern-input"
                                               :class="[is_submit_form4 ? (client.vendor ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="Vendor name" />
                                        <div class="invalid-feedback">Please provide a valid vendor</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Payment Method *</label>
                                        <input type="text" v-model="client.payment_method" class="modern-input"
                                               :class="[is_submit_form4 ? (client.payment_method ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="e.g., Cash, Card, Bank Transfer" />
                                        <div class="invalid-feedback">Please provide a valid payment method</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Invoice Number *</label>
                                        <input type="text" v-model="client.invoice_number" class="modern-input"
                                               :class="[is_submit_form4 ? (client.invoice_number ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="INV-000" />
                                        <div class="invalid-feedback">Please provide a valid invoice number</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">User/Advocate *</label>
                                        <SearchSelect
                                            fetch-url="/api/v1/advocates"
                                            v-model="client.user_id"
                                            :initial-label="client.user_name"
                                            placeholder="Search users..."
                                            :invalid="is_submit_form4 && !client.user_id"
                                        />
                                        <div class="invalid-feedback" :style="is_submit_form4 && !client.user_id ? 'display:block' : ''">Please select the user</div>
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
import { useFileDownload } from '@/composables/use-file-download';
import { useFormErrors } from '@/composables/use-form-errors';
import DataTable from '@/components/ui/data-table.vue';
import SearchSelect from '@/components/ui/search-select.vue';
import { useModalDirtyGuard } from '@/composables/use-modal-dirty-guard';

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { setErrorsFromResponse, clearErrors } = useFormErrors();
const { downloadFile } = useFileDownload();
const exporting = ref(false);

const exportCsv = () => {
    exporting.value = true;
    downloadFile('/api/v1/export/expenses', `expenses-${new Date().toISOString().slice(0, 10)}.csv`)
        .finally(() => { exporting.value = false; });
};

const openAddModal = () => {
    clearErrors();
    client.value = {
        id: "", case_id: "", case_name: "", expense_date: "", amount: "", category: "",
        description: "", vendor: "", payment_method: "", invoice_number: "", user_id: "", user_name: ""
    };
    is_submit_form4.value = false;
    modal.value.title = "Add Expense";
    modal.value.button = "Add";
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};
useMeta({ title: 'Expenses' });

const tableRef = ref(null);

const bulkDeleteExpenses = (selectedIds, clearSelection) => {
    if (!selectedIds.length) return;

    confirmAction({
        title: `Delete ${selectedIds.length} expense${selectedIds.length > 1 ? 's' : ''}?`,
        text: 'This will delete every selected expense. This can be undone by an administrator.',
        confirmText: 'Yes, delete all',
        danger: true,
    }).then((ok) => {
        if (!ok) return;

        axios.post('/api/v1/expenses/bulk-delete', { ids: selectedIds })
            .then(() => {
                showMessage(`Deleted ${selectedIds.length} expense${selectedIds.length > 1 ? 's' : ''}.`);
                clearSelection();
                tableRef.value?.refresh();
            })
            .catch((error) => {
                const message = error.response?.data?.message || 'Error deleting selected expenses. Please try again.';
                showMessage(message, 'error');
            });
    });
};

const bulkExportExpenses = (selectedIds) => {
    if (!selectedIds.length) return;
    downloadFile('/api/v1/export/expenses', `expenses-selected-${new Date().toISOString().slice(0, 10)}.csv`, { ids: selectedIds.join(',') });
};
const types = ref([]);
const modal = ref({title: "Add Expense", button: "Add"});

onMounted(() => {
    fetchDropdowns();
});

const router = useRouter();
const is_submit_form4 = ref(false);
const client = ref({id: "",case_id:"",case_name:"",expense_date : "",amount:"",category:"",description:"",vendor:"",payment_method:"",invoice_number:"",user_id:"",user_name:""});

// UI-15: closing this modal with unsaved edits now asks for confirmation.
// Bug fix (found via a real E2E run): clearDirtyGuard() must be called
// on a successful save, before the modal auto-closes — see
// list-clients.vue's identical fix for the full explanation.
const { clear: clearDirtyGuard } = useModalDirtyGuard('client_modal', () => ({ ...client.value }));

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.case_id  && client.value.expense_date && client.value.payment_method && client.value.amount && client.value.payment_method && client.value.invoice_number && client.value.user_id) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/expenses/${client.value.id}`, client.value)
                .then(() => {
                    showMessage('Expense updated successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating expense. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/expenses', client.value)
                .then(() => {
                    showMessage('Expense added successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding expense. Please try again.'), 'error');
                });
        }

        client.value.id = "";
        client.value.case_id = "";
        client.value.case_name = "";
        client.value.expense_date = "";
        client.value.amount = "";
        client.value.category = "";
        client.value.description = "";
        client.value.vendor = "";
        client.value.payment_method = "";
        client.value.invoice_number = "";
        client.value.user_id = "";
        client.value.user_name = "";
        modal.value.title = "Add Expense";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const fetchDropdowns = () => {
    axios.get('/api/v1/expense-categories/options')
        .then(response => {
            types.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching categories:', error);
            showMessage('Could not load categories. Please try refreshing the page.', 'error');
        });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.case_id = item.case_id;
    client.value.case_name = item.case;
    client.value.expense_date = item.expense_date;
    client.value.amount = item.amount;
    client.value.category = item.category;
    client.value.description = item.description;
    client.value.vendor = item.vendor;
    client.value.payment_method = item.payment_method;
    client.value.invoice_number = item.invoice_number;
    client.value.user_id = item.user_id;
    client.value.user_name = item.user;

    modal.value.title = "Update Expense";
    modal.value.button = "Update";
};

const delete_row = (item) => {
    confirmDelete({
        url: `/api/v1/expenses/${item.id}`,
        itemLabel: `this ${item.amount} expense`,
        onSuccess: () => { tableRef.value?.refresh(); },
    });
};
</script>

<style lang="scss" scoped>
.modern-expenses-page {
    padding: 20px;
    min-height: 100vh;
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

.header-button-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn-export {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: white;
    color: #c2410c;
    border: 1.5px solid #c2410c;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;

    &:hover:not(:disabled) {
        background: rgba(249, 115, 22, 0.08);
    }

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}

.btn-add-expense {
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
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
}

.btn-add-expense:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
}

.btn-add-expense svg {
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
    background: #fef3c7;
    color: #92400e;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.date-cell {
    color: #6b7280;
    font-size: 14px;
}

.amount-cell {
    font-weight: 600;
    color: var(--primary-700);
    font-size: 15px;
}

.category-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #fed7aa;
    color: #9a3412;
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
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
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

.modern-input:focus,
.modern-select:focus {
    outline: none;
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
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
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-add-expense {
        justify-content: center;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }
}
</style>