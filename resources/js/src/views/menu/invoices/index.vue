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
                <button type="button" class="btn-add-invoice" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Invoice
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #case="props">
                        <div class="case-badge">{{ props.row.case }}</div>
                    </template>
                    <template #client="props">
                        <div class="client-name">
                            <svg class="client-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <circle cx="12" cy="7" r="4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            {{ props.row.client }}
                        </div>
                    </template>
                    <template #invoice_date="props">
                        <div class="date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2"/>
                            </svg>
                            {{ props.row.invoice_date }}
                        </div>
                    </template>
                    <template #invoice_due_date="props">
                        <div class="due-date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="12" cy="12" r="10" stroke-width="2"/>
                                <path d="M12 6v6l4 2" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            {{ props.row.invoice_due_date }}
                        </div>
                    </template>
                    <template #status="props">
                        <span :class="['status-badge', `status-${props.row.status?.toLowerCase()}`]">
                            {{ props.row.status || 'Pending' }}
                        </span>
                    </template>
                    <template #actions="props">
                        <div class="actions text-center">
                            <a v-if="props.row.delete" href="javascript:" class="cancel me-1" @click="view_row(props.row.id)">
                                <button type="button" class="btn-items">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9l2 2 4-4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Items
                                </button>
                            </a>
                            <a v-if="props.row.delete" href="javascript:" class="cancel me-1" @click="update_row(props.row)">
                                <button type="button" class="btn-edit" data-bs-toggle="modal" data-bs-target="#client_modal">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Edit
                                </button>
                            </a>
                            <a v-if="props.row.delete" href="javascript:" class="cancel" @click="submit_form6(props.row)">
                                <button type="button" class="btn-delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Delete
                                </button>
                            </a>
                        </div>
                    </template>
                </v-client-table>
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
                                        <select v-model="client.case_id" class="modern-select" :class="[is_submit_form4 ? (client.case_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Case</option>
                                            <option v-for="data in cases" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the case</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Client *</label>
                                        <select v-model="client.client_id" class="modern-select" :class="[is_submit_form4 ? (client.client_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Client</option>
                                            <option v-for="data in clients" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the client</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Invoice Date * (dd/mm/yyyy)</label>
                                        <input v-model="client.invoice_date" type="text" class="modern-input"
                                               :class="[is_submit_form4 ? (client.invoice_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Due Date * (dd/mm/yyyy)</label>
                                        <input v-model="client.invoice_due_date" type="text" class="modern-input"
                                               :class="[is_submit_form4 ? (client.invoice_due_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
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
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Invoices' });

const columns = ref(['case', 'client','invoice_date','invoice_due_date','status','actions']);
const items = ref([]);
const cases = ref([]);
const clients = ref([]);
const modal = ref({title: "", button: ""});
const table_option = ref({
    perPage: 10,
    perPageValues: [5, 10, 20, 50],
    skin: 'table table-hover',
    columnsClasses: { actions: 'actions text-center' },
    pagination: { nav: 'scroll', chunk: 5 },
    texts: {
        count: 'Showing {from} to {to} of {count}',
        filter: '',
        filterPlaceholder: 'Search...',
        limit: 'Results:',
    },
    sortable: ['case', 'client','invoice_date','invoice_due_date'],
    sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc',
    },
    resizableColumns: false,
});

onMounted(() => {
    fetchData();
});

const router = useRouter();
const is_submit_form4 = ref(false);
const client = ref({id: "",case_id:"",client_id:"",invoice_date:"",invoice_due_date:""});

const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.case_id && client.value.invoice_date && client.value.invoice_due_date && client.value.client_id) {
        if(client.value.id > 0) {
            axios.put(`/api/invoices/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Invoice updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating invoice. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/invoices', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Invoice added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding invoice. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.client_id = "";
        client.value.case_id = "";
        client.value.invoice_date = "";
        client.value.invoice_due_date = "";
        modal.value.title = "Add Invoice";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const submit_form6 = (item) => {
    if (confirm('Are you sure you want to delete this invoice?')) {
        if (item) {
            axios.delete(`/api/invoices/${item.id}`)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Invoice deleted successfully.');
                })
                .catch(error => {
                    showMessage('Error deleting the invoice. Please try again.');
                    console.error(error);
                });
        }
    }
};

const fetchData = () => {
    modal.value.title = "Add Invoice";
    modal.value.button = "Add";
    
    axios.get('/api/invoices')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching invoices:', error);
        });

    axios.get('/api/casesDropDown')
        .then(response => {
            cases.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching cases:', error);
        });

    axios.get('/api/clientsDropDown')
        .then(response => {
            clients.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.client_id = item.client_id;
    client.value.case_id = item.case_id;
    client.value.invoice_date = item.invoice_date;
    client.value.invoice_due_date = item.invoice_due_date;

    modal.value.title = "Update Invoice";
    modal.value.button = "Update";
};

const view_row = (itemId) => {
    localStorage.setItem("invoiceId", itemId);
    router.push("/view-invoice");
};

const showMessage = (msg = '', type = 'success') => {
    const toast = window.Swal.mixin({
        toast: true,
        position: 'top',
        showConfirmButton: false,
        timer: 3000,
    });
    toast.fire({
        icon: type,
        title: msg,
        padding: '10px 20px',
    });
};
</script>

<style lang="scss" scoped>
.modern-invoices-page {
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
    background: linear-gradient(135deg, #6366f1, #4f46e5);
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

.btn-add-invoice {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.btn-add-invoice:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
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
    color: #3730a3;
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
    color: #6366f1;
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
    color: #6366f1;
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
    background: linear-gradient(135deg, #6366f1, #4f46e5);
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
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
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
    background: linear-gradient(135deg, #10b981, #059669);
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
    background: linear-gradient(135deg, #6366f1, #4f46e5);
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
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.modern-input.is-invalid,
.modern-select.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid,
.modern-select.is-valid {
    border-color: #10b981;
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
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
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