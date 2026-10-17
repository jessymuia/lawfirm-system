<template>
    <div class="modern-invoices-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Invoices</a></li>
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
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <h4>Invoices Management</h4>
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
                    <button type="button" class="btn-add-invoice" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#client_modal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Add New Invoice
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <DataTable
:viewable="false"
                    ref="tableRef"
                    fetch-url="/api/v1/invoices"
                    table-id="invoices-list"
                    :selectable="true"
                    :columns="[
                        { key: 'invoice_number', label: 'Invoice #' },
                        { key: 'case', label: 'Case' },
                        { key: 'client', label: 'Client' },
                        { key: 'invoice_date', label: 'Invoice Date' },
                        { key: 'invoice_due_date', label: 'Due Date' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :sortable-columns="['invoice_date', 'invoice_due_date']"
                    :searchable-columns="['invoice_number']"
                    :filter-params="['payment_status', 'workflow_status']"
                    empty-title="No invoices yet"
                    empty-description="Add your first invoice to get started."
                    empty-action-label="Add New Invoice"
                    @empty-action="openAddModal"
                >
                    <template #bulk-actions="{ selectedIds, clearSelection }">
                        <button type="button" class="btn-toolbar" @click="bulkDeleteInvoices(selectedIds, clearSelection)">Delete Selected</button>
                        <button type="button" class="btn-toolbar" @click="bulkExportInvoices(selectedIds)">Export Selected</button>
                    </template>
                    <template #cell-case="{ row }">
                        <a href="javascript:;" class="case-badge cross-link" @click="goToCase(row.case_id)">{{ row.case }}</a>
                    </template>
                    <template #cell-client="{ row }">
                        <router-link :to="{ name: 'client-details', params: { id: row.client_public_id || row.client_id } }" class="client-name cross-link">
                            <svg class="client-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="12" cy="7" r="4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            {{ row.client }}
                        </router-link>
                    </template>
                    <template #cell-invoice_date="{ row }">
                        <div class="date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2"/>
                            </svg>
                            {{ row.invoice_date }}
                        </div>
                    </template>
                    <template #cell-invoice_due_date="{ row }">
                        <div class="due-date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="12" cy="12" r="10" stroke-width="2"/>
                                <path d="M12 6v6l4 2" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            {{ row.invoice_due_date }}
                        </div>
                    </template>
                    <template #cell-status="{ row }">
                        <InvoiceStatus :payment-status="row.payment_status" :status="row.status" :due-date="row.invoice_due_date" />
                    </template>
                    <template #actions="{ row }">
                        <div class="actions text-center">
                            <a v-if="row.delete" href="javascript:" class="cancel me-1" @click="view_row(row.id)">
                                <button type="button" class="btn-items">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9l2 2 4-4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Items
                                </button>
                            </a>
                            <a v-if="row.delete" href="javascript:" class="cancel me-1" @click="update_row(row)">
                                <button type="button" class="btn-edit" data-bs-toggle="modal" data-bs-target="#client_modal">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Edit
                                </button>
                            </a>
                            <a v-if="row.delete" href="javascript:" class="cancel" @click="submit_form6(row)">
                                <button type="button" class="btn-delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
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
                                <h5 class="section-title">Invoice Information</h5>
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
                                        <label class="form-label">Client *</label>
                                        <SearchSelect
                                            fetch-url="/api/v1/clientsDropDown"
                                            v-model="client.client_id"
                                            :initial-label="client.client_name"
                                            placeholder="Search clients..."
                                            :invalid="is_submit_form4 && !client.client_id"
                                        />
                                        <div class="invalid-feedback" :style="is_submit_form4 && !client.client_id ? 'display:block' : ''">Please select the client</div>
                                    </div>

                                    <!-- DATA-14: shown when the firm invoices in more than one currency -->
                                    <div v-if="currencies.length > 1" class="col-md-6 form-group">
                                        <label class="form-label" for="invoice-currency">Currency</label>
                                        <select id="invoice-currency" v-model="client.currency" class="form-control">
                                            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Invoice Date *</label>
                                        <AppDatePicker v-model="client.invoice_date"
                                               :invalid="is_submit_form4 && !client.invoice_date" />
                                        <div class="invalid-feedback">Please provide a valid date</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Due Date *</label>
                                        <AppDatePicker v-model="client.invoice_due_date"
                                               :invalid="is_submit_form4 && !client.invoice_due_date"
                                               :min-date="client.invoice_date || null" />
                                        <div class="invalid-feedback">Please provide a valid due date</div>
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
import InvoiceStatus from '@/components/ui/invoice-status.vue';
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
    downloadFile('/api/v1/export/invoices', `invoices-${new Date().toISOString().slice(0, 10)}.csv`)
        .finally(() => { exporting.value = false; });
};

const openAddModal = () => {
    clearErrors();
    client.value = { id: "", case_id: "", case_name: "", client_id: "", client_name: "", invoice_date: "", invoice_due_date: "", currency: baseCurrency.value };
    is_submit_form4.value = false;
    modal.value.title = "Add Invoice";
    modal.value.button = "Add";
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};
useMeta({ title: 'Invoices' });

const tableRef = ref(null);

const bulkDeleteInvoices = (selectedIds, clearSelection) => {
    if (!selectedIds.length) return;

    confirmAction({
        title: `Delete ${selectedIds.length} invoice${selectedIds.length > 1 ? 's' : ''}?`,
        text: 'Only invoices you have rights to delete (your own drafts, or any if you\'re an admin) will actually be removed.',
        confirmText: 'Yes, delete all',
        danger: true,
    }).then((ok) => {
        if (!ok) return;

        axios.post('/api/v1/invoices/bulk-delete', { ids: selectedIds })
            .then((response) => {
                const { deleted, skipped } = response.data.data;
                if (skipped.length) {
                    showMessage(`Deleted ${deleted.length}. Skipped ${skipped.length} you don't have rights to delete.`, 'warning');
                } else {
                    showMessage(`Deleted ${deleted.length} invoice${deleted.length > 1 ? 's' : ''}.`);
                }
                clearSelection();
                tableRef.value?.refresh();
            })
            .catch((error) => {
                const message = error.response?.data?.message || 'Error deleting selected invoices. Please try again.';
                showMessage(message, 'error');
            });
    });
};

const bulkExportInvoices = (selectedIds) => {
    if (!selectedIds.length) return;
    downloadFile('/api/v1/export/invoices', `invoices-selected-${new Date().toISOString().slice(0, 10)}.csv`, { ids: selectedIds.join(',') });
};
const modal = ref({title: "Add Invoice", button: "Add"});

onMounted(() => {
    axios.get('/api/v1/finance/settings').then(({ data }) => {
        currencies.value = data.data.invoice_currencies;
        baseCurrency.value = data.data.base_currency;
        if (!client.value.id) client.value.currency = baseCurrency.value;
    }).catch(() => {});
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
const client = ref({id: "",case_id:"",case_name:"",client_id:"",client_name:"",invoice_date:"",invoice_due_date:"",currency:""});
// DATA-14: the currencies the firm invoices in (its base currency first)
const currencies = ref([]);
const baseCurrency = ref('');

// UI-15: closing this modal with unsaved edits now asks for confirmation.
// Bug fix (found via a real E2E run): clearDirtyGuard() must be called
// on a successful save, before the modal auto-closes — see
// list-clients.vue's identical fix for the full explanation.
const { clear: clearDirtyGuard } = useModalDirtyGuard('client_modal', () => ({ ...client.value }));

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.case_id && client.value.invoice_date && client.value.invoice_due_date && client.value.client_id) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/invoices/${client.value.id}`, client.value)
                .then(() => {
                    showMessage('Invoice updated successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating invoice. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/invoices', client.value)
                .then(() => {
                    showMessage('Invoice added successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding invoice. Please try again.'), 'error');
                });
        }

        client.value.id = "";
        client.value.client_id = "";
        client.value.client_name = "";
        client.value.case_id = "";
        client.value.case_name = "";
        client.value.invoice_date = "";
        client.value.invoice_due_date = "";
        client.value.currency = baseCurrency.value;
        modal.value.title = "Add Invoice";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const submit_form6 = (item) => {
    if (!item) return;
    confirmDelete({
        url: `/api/v1/invoices/${item.id}`,
        itemLabel: `invoice ${item.invoice_number || ''}`.trim(),
        onSuccess: () => { tableRef.value?.refresh(); },
    });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.client_id = item.client_id;
    client.value.client_name = item.client;
    client.value.case_id = item.case_id;
    client.value.case_name = item.case;
    client.value.invoice_date = item.invoice_date;
    client.value.invoice_due_date = item.invoice_due_date;
    client.value.currency = item.currency;

    modal.value.title = "Update Invoice";
    modal.value.button = "Update";
};

const view_row = (itemId) => {
    localStorage.setItem("invoiceId", itemId);
    router.push("/view-invoice");
};
</script>

<style lang="scss" scoped>
.modern-invoices-page {
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
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
    color: var(--primary-600);
    border: 1.5px solid var(--primary-600);
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;

    &:hover:not(:disabled) {
        background: rgba(2, 132, 199, 0.08);
    }

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}

.btn-add-invoice {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
}

.btn-add-invoice:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4);
}

.btn-add-invoice svg {
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
    background: #e0e7ff;
    color: var(--primary-800);
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.client-name {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #374151;
    font-size: 14px;
}

.client-icon {
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

.due-date-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
    font-size: 14px;
}

.due-date-cell svg {
    width: 16px;
    height: 16px;
    color: #f59e0b;
    stroke-width: 2;
}

.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-paid {
    background: #d1fae5;
    color: #065f46;
}

.status-overdue {
    background: #fee2e2;
    color: #991b1b;
}

.btn-items {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-items:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
}

.btn-items svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
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
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
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
    background: linear-gradient(135deg, #ef4444, #dc2626);
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
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-delete svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.modern-modal .modal-header {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
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
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4);
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-add-invoice {
        justify-content: center;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }
}
</style>