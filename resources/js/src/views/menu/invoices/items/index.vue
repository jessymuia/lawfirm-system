<template>
    <div class="modern-invoice-items-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Invoices</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Invoice Items</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <!-- Invoice Information Card - Elevated -->
        <div class="elevated-card">
            <div class="card-header">
                <div class="header-content">
                    <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2"/>
                        <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke-width="2"/>
                    </svg>
                    <h2 class="card-title">Invoice Information</h2>
                </div>
            </div>
            
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <label class="info-label">Case Number</label>
                        <div class="info-value">{{ invoice.case || 'N/A' }}</div>
                    </div>
                    <div class="info-item">
                        <label class="info-label">Client</label>
                        <div class="info-value">{{ invoice.client || 'N/A' }}</div>
                    </div>
                    <div class="info-item">
                        <label class="info-label">Invoice Date</label>
                        <div class="info-value">{{ invoice.invoice_date || 'N/A' }}</div>
                    </div>
                    <div class="info-item">
                        <label class="info-label">Due Date</label>
                        <div class="info-value">{{ invoice.invoice_due_date || 'N/A' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Items Card - Elevated -->
        <div class="elevated-card">
            <div class="card-header">
                <div class="header-content">
                    <svg class="section-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" stroke-width="2"/>
                        <path d="M9 12h6M9 16h6" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h2 class="card-title">Invoice Items</h2>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn-action btn-add" data-bs-toggle="modal" data-bs-target="#clientI_modal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Add Item
                    </button>
                    <button type="button" class="btn-action btn-preview" @click="preview">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke-width="2"/>
                            <circle cx="12" cy="12" r="3" stroke-width="2"/>
                        </svg>
                        Preview Invoice
                    </button>
                </div>
            </div>

            <div class="card-body">
                <div class="table-container">
                    <v-client-table :data="itemsI" :columns="columnsI" :options="table_optionI">
                        <template #description="props">
                            <div class="description-cell">{{ props.row.description }}</div>
                        </template>
                        <template #quantity="props">
                            <div class="quantity-cell">{{ props.row.quantity }}</div>
                        </template>
                        <template #rate="props">
                            <div class="amount-cell">KES {{ props.row.rate }}</div>
                        </template>
                        <template #tax="props">
                            <div class="tax-cell">KES {{ props.row.tax }}</div>
                        </template>
                        <template #total_amount="props">
                            <div class="total-cell">KES {{ props.row.total_amount }}</div>
                        </template>
                        <template #actions="props">
                            <div class="actions text-center">
                                <a href="javascript:;" class="cancel me-1" @click="update_rowI(props.row)">
                                    <button type="button" class="btn-action-table btn-edit" data-bs-toggle="modal" data-bs-target="#clientI_modal">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2"/>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2"/>
                                        </svg>
                                        Edit
                                    </button>
                                </a>
                                <a href="javascript:" class="cancel" @click="submit_form6(props.row)">
                                    <button type="button" class="btn-action-table btn-delete">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke-width="2"/>
                                        </svg>
                                        Delete
                                    </button>
                                </a>
                            </div>
                        </template>
                    </v-client-table>
                </div>
            </div>
        </div>

        <!-- View Full Invoice Button -->
        <div class="footer-actions">
            <button type="button" class="btn-view-full" @click="preview()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2"/>
                    <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke-width="2"/>
                </svg>
                View Full Invoice
            </button>
        </div>

        <!-- Add/Edit Item Modal -->
        <div class="modal fade" id="clientI_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="modern-form" novalidate @submit.stop.prevent="submit_form4">
                            <div class="form-group">
                                <label class="form-label">Description *</label>
                                <textarea v-model="client.description" rows="3" class="modern-input"
                                          :class="[is_submit_form4 ? (client.description ? 'is-valid' : 'is-invalid') : '']"
                                          placeholder="Enter item description or service details"></textarea>
                                <div class="invalid-feedback">Please fill the description</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="form-label">Quantity *</label>
                                    <input type="number" step="0.001" v-model="client.quantity" class="modern-input"
                                           :class="[is_submit_form4 ? (client.quantity ? 'is-valid' : 'is-invalid') : '']"
                                           placeholder="0.00" @input="computeTotal" />
                                    <div class="invalid-feedback">Please fill the quantity</div>
                                </div>

                                <div class="col-md-6 form-group">
                                    <label class="form-label">Rate (KES) *</label>
                                    <input type="number" step="0.001" v-model="client.rate" class="modern-input"
                                           :class="[is_submit_form4 ? (client.rate ? 'is-valid' : 'is-invalid') : '']"
                                           placeholder="0.00" @input="computeTotal" />
                                    <div class="invalid-feedback">Please fill the rate</div>
                                </div>
                            </div>

                            <div class="calculated-section">
                                <div class="calculated-header">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <span>Calculated Values</span>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Tax (16%)</label>
                                        <input type="number" step="0.001" v-model="client.tax" class="modern-input calculated"
                                               placeholder="0.00" readonly />
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Total Amount</label>
                                        <input type="number" step="0.001" v-model="client.total_amount" class="modern-input calculated"
                                               placeholder="0.00" readonly />
                                    </div>
                                </div>
                            </div>

                            <button class="btn-submit" type="submit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M5 13l4 4L19 7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                {{ modal.button }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from "vue-router";
import axios from "../../../../api";
import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Invoice Items' });

const columnsI = ref(['description', 'quantity', 'rate', 'tax', 'total_amount', 'actions']);
const itemsI = ref([]);
const table_optionI = ref({
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
    sortable: ['description', 'quantity', 'rate', 'tax', 'total_amount'],
    sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc',
    },
    resizableColumns: false,
});

const modal = ref({title: "", button: ""});
const invoice = ref({});
const is_submit_form4 = ref(false);
const router = useRouter();

const client = ref({id: "",invoice_id:"",description : "", quantity:0,rate:0, tax:0, total_amount:0});

const submit_form4 = () => {
    is_submit_form4.value = true;
    client.value.invoice_id = localStorage.getItem('invoiceId');
    if (client.value.invoice_id && client.value.description && client.value.quantity && client.value.rate) {
        if(client.value.id > 0) {
            axios.put(`/api/invoiceItems/${client.value.id}`, client.value)
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Item updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating item. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/invoiceItems', client.value)
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Item added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding item. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.description = "";
        client.value.quantity = 0;
        client.value.tax = 0;
        client.value.rate = 0;
        client.value.total_amount = 0;
        modal.value.title = "Add Item";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const submit_form6 = (item) => {
    if (confirm('Are you sure you want to delete this item?')) {
        if (item) {
            axios.delete(`/api/invoiceItems/${item.id}`)
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Invoice item deleted successfully.');
                })
                .catch(error => {
                    showMessage('Error deleting the invoice item. Please try again.');
                    console.error(error);
                });
        }
    }
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

const preview = () => {
    router.push("/invoice-preview");
};

const update_rowI = (item) => {
    client.value.id = item.id;
    client.value.description = item.description;
    client.value.quantity = item.quantity;
    client.value.tax = item.tax;
    client.value.rate = item.rate;
    client.value.total_amount = item.total_amount;

    modal.value.title = "Update Item";
    modal.value.button = "Update";
};

const fetchData = () => {
    modal.value.title = "Add Item";
    modal.value.button = "Add";

    let invoiceId = localStorage.getItem('invoiceId');
    axios.get(`/api/invoiceItems/${invoiceId}`)
        .then(response => {
            itemsI.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching items:', error);
        });

    axios.get(`/api/invoices/${invoiceId}`)
        .then(response => {
            invoice.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching invoice:', error);
        });
};

onMounted(() => {
    fetchData();
});

const computeTotal = () => {
    let totals = client.value.quantity * client.value.rate;
    client.value.tax = (0.16 * totals);
    client.value.total_amount = totals;
};
</script>

<style lang="scss" scoped>
.modern-invoice-items-page {
    padding: 24px;
    background: #f8f9fa;
    min-height: 100vh;
}

/* Elevated Card Styles */
.elevated-card {
    background: white;
    border-radius: 12px;
    box-shadow: 6px 6px 20px rgba(0, 0, 0, 0.08), 
                3px 3px 10px rgba(0, 0, 0, 0.04);
    margin-bottom: 28px;
    overflow: hidden;
    transition: transform 0.3s, box-shadow 0.3s;
}

.elevated-card:hover {
    transform: translateY(-4px) translateX(-2px);
    box-shadow: 8px 8px 24px rgba(0, 0, 0, 0.12), 
                4px 4px 12px rgba(0, 0, 0, 0.06);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 28px 32px;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
}

.header-icon,
.section-icon {
    width: 48px;
    height: 48px;
    padding: 10px;
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    border-radius: 12px;
    stroke-width: 2;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
}

.card-title {
    font-size: 22px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    letter-spacing: -0.5px;
}

.header-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-action {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    color: white;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.btn-action svg {
    width: 20px;
    height: 20px;
    stroke-width: 2.5;
}

.btn-add {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
}

.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

.btn-preview {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
}

.btn-preview:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(107, 114, 128, 0.4);
}

.card-body {
    padding: 32px;
}

/* Info Grid Styles */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 24px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.info-label {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0;
}

.info-value {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    padding: 14px 18px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 10px;
    border-left: 4px solid #6366f1;
    min-height: 52px;
    display: flex;
    align-items: center;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.04);
    transition: all 0.2s;
}

.info-value:hover {
    box-shadow: 3px 3px 12px rgba(0, 0, 0, 0.08);
    transform: translateX(2px);
}

/* Table Styles */
.table-container {
    margin-top: 16px;
    overflow-x: auto;
}

.description-cell {
    color: #374151;
    font-size: 14px;
    font-weight: 500;
}

.quantity-cell {
    color: #6b7280;
    font-weight: 600;
    font-size: 15px;
}

.amount-cell {
    color: #6366f1;
    font-weight: 700;
    font-size: 15px;
}

.tax-cell {
    color: #f59e0b;
    font-weight: 600;
    font-size: 15px;
}

.total-cell {
    color: #059669;
    font-weight: 700;
    font-size: 16px;
}

/* Action Buttons in Table */
.btn-action-table {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 13px;
    font-weight: 600;
    margin: 0 4px;
}

.btn-action-table svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.btn-edit {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.btn-edit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-delete {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* Footer Actions */
.footer-actions {
    display: flex;
    justify-content: center;
    padding: 20px 0;
}

.btn-view-full {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 36px;
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.3);
}

.btn-view-full:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.45);
}

.btn-view-full svg {
    width: 22px;
    height: 22px;
    stroke-width: 2;
}

/* Modal Styles */
.modern-modal .modal-header {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    border-radius: 12px 12px 0 0;
    padding: 24px 28px;
    border: none;
}

.modern-modal .modal-title {
    font-size: 20px;
    font-weight: 700;
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
    padding: 36px;
}

.modern-form {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.form-group {
    margin-bottom: 0;
}

.form-label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.modern-input {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 15px;
    transition: all 0.3s ease;
    background: white;
    font-family: inherit;
}

textarea.modern-input {
    resize: vertical;
    min-height: 100px;
}

.modern-input:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.modern-input.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid {
    border-color: #10b981;
}

.invalid-feedback {
    display: none;
    font-size: 13px;
    color: #ef4444;
    margin-top: 8px;
    font-weight: 500;
}

.is-invalid ~ .invalid-feedback {
    display: block;
}

.calculated-section {
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 12px;
    padding: 24px;
    border: 2px dashed #d1d5db;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.04);
}

.calculated-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e5e7eb;
}

.calculated-header svg {
    width: 24px;
    height: 24px;
    stroke-width: 2;
    color: #6366f1;
}

.calculated-header span {
    font-size: 15px;
    font-weight: 700;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.modern-input.calculated {
    background: #f3f4f6;
    font-weight: 700;
    color: #1f2937;
    border-color: #d1d5db;
    cursor: not-allowed;
}

.btn-submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 36px;
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(99, 102, 241, 0.45);
}

.btn-submit svg {
    width: 20px;
    height: 20px;
    stroke-width: 2.5;
}

/* Responsive Design */
@media (max-width: 768px) {
    .modern-invoice-items-page {
        padding: 16px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .card-header {
        flex-direction: column;
        gap: 16px;
        align-items: flex-start;
    }

    .header-actions {
        flex-direction: column;
        width: 100%;
    }

    .btn-action,
    .btn-view-full {
        width: 100%;
        justify-content: center;
    }

    .card-body {
        padding: 20px;
    }

    .btn-submit {
        width: 100%;
    }
}
</style>
