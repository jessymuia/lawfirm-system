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
                <InvoiceStatus v-if="invoice.workflow_status" :payment-status="invoice.payment_status" :status="invoice.workflow_status" :due-date="invoice.invoice_due_date" />
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
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Rate</th>
                                <th>Tax</th>
                                <th>Total</th>
                                <th class="actions-col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in itemsI" :key="row.id">
                                <td data-label="Description"><div class="description-cell">{{ row.description }}</div></td>
                                <td data-label="Qty"><div class="quantity-cell">{{ row.quantity }}</div></td>
                                <td data-label="Rate"><div class="amount-cell">{{ currency }} {{ row.rate }}</div></td>
                                <td data-label="Tax"><div class="tax-cell">{{ currency }} {{ row.tax }}</div></td>
                                <td data-label="Total"><div class="total-cell">{{ currency }} {{ row.total_amount }}</div></td>
                                <td class="actions-col" data-label="Actions">
                                    <div class="actions text-center">
                                        <a href="javascript:;" class="cancel me-1" @click="update_rowI(row)">
                                            <button type="button" class="btn-action-table btn-edit" data-bs-toggle="modal" data-bs-target="#clientI_modal">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2"/>
                                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2"/>
                                                </svg>
                                                Edit
                                            </button>
                                        </a>
                                        <a href="javascript:" class="cancel" @click="delete_rowI(row)">
                                            <button type="button" class="btn-action-table btn-delete">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke-width="2"/>
                                                </svg>
                                                Delete
                                            </button>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="itemsI.length === 0">
                                <td colspan="6" class="no-items-row">No items on this invoice yet — add the first one above.</td>
                            </tr>
                        </tbody>
                    </table>
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
        <CrudModal ref="crudModalRef" modal-id="clientI_modal" :title="modal.title" :submit-label="modal.button" size="lg" :form-snapshot="() => ({ ...client })" @submit="submit_form4">
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
                    <label class="form-label">Rate ({{ currency }}) *</label>
                    <input type="number" step="0.001" v-model="client.rate" class="modern-input"
                           :class="[is_submit_form4 ? (client.rate ? 'is-valid' : 'is-invalid') : '']"
                           placeholder="0.00" @input="computeTotal" />
                    <div class="invalid-feedback">Please fill the rate</div>
                </div>
            </div>

            <!-- FUN-13: UTBMS codes for e-billed (LEDES) clients. A line with an expense code is an expense; otherwise a fee. -->
            <div class="row">
                <div class="col-md-4 form-group">
                    <label class="form-label" for="item-task">UTBMS task</label>
                    <select id="item-task" v-model="client.utbms_task_code" class="modern-input">
                        <option value="">—</option>
                        <option v-for="t in utbmsTasks" :key="t.code" :value="t.code">{{ t.code }} {{ t.name }}</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label" for="item-activity">UTBMS activity</label>
                    <select id="item-activity" v-model="client.utbms_activity_code" class="modern-input">
                        <option value="">—</option>
                        <option v-for="a in utbmsActivities" :key="a.code" :value="a.code">{{ a.code }} {{ a.name }}</option>
                    </select>
                </div>
                <div class="col-md-4 form-group">
                    <label class="form-label" for="item-expense">UTBMS expense</label>
                    <select id="item-expense" v-model="client.utbms_expense_code" class="modern-input">
                        <option value="">—</option>
                        <option v-for="e in utbmsExpenses" :key="e.code" :value="e.code">{{ e.code }} {{ e.name }}</option>
                    </select>
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
                    <!-- DATA-14: the tax comes from one of the firm's tax rates (Settings → Currencies and tax) -->
                    <div class="col-md-3 form-group">
                        <label class="form-label" for="item-tax-rate">Tax rate</label>
                        <select id="item-tax-rate" v-model="client.tax_rate_id" class="modern-input" @change="computeTotal">
                            <option :value="null">No tax</option>
                            <option v-for="t in taxRates" :key="t.id" :value="t.id">{{ t.name }} ({{ t.rate }}%)</option>
                        </select>
                    </div>
                    <div class="col-md-3 form-group">
                        <label class="form-label" for="item-tax">Tax</label>
                        <input id="item-tax" type="number" step="0.001" v-model="client.tax" class="modern-input calculated"
                               placeholder="0.00" readonly />
                    </div>

                    <div class="col-md-6 form-group">
                        <label class="form-label" for="item-total">Total Amount</label>
                        <input id="item-total" type="number" step="0.001" v-model="client.total_amount" class="modern-input calculated"
                               placeholder="0.00" readonly />
                    </div>
                </div>
            </div>
        </CrudModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from "vue-router";
import axios from "../../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import CrudModal from '@/components/ui/crud-modal.vue';
import InvoiceStatus from '@/components/ui/invoice-status.vue';
import { useUtbms } from '@/composables/use-utbms';
useMeta({ title: 'Invoice Items' });

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();

const itemsI = ref([]);

const modal = ref({title: "Add Item", button: "Add"});
const invoice = ref({});
const is_submit_form4 = ref(false);
const router = useRouter();

const { tasks: utbmsTasks, activities: utbmsActivities, expenses: utbmsExpenses } = useUtbms();
const client = ref({id: "",invoice_id:"",description : "", quantity:0,rate:0, tax:0, tax_rate_id: null, total_amount:0, utbms_task_code: "", utbms_activity_code: "", utbms_expense_code: ""});
// DATA-14: the firm's tax rates, and the currency this invoice is in
const taxRates = ref([]);
const defaultTaxRate = () => (taxRates.value.find(t => t.is_default) || {}).id ?? null;
const currency = computed(() => invoice.value.currency || 'KES');
const crudModalRef = ref(null);

const submit_form4 = () => {
    is_submit_form4.value = true;
    client.value.invoice_id = localStorage.getItem('invoiceId');
    if (client.value.invoice_id && client.value.description && client.value.quantity && client.value.rate) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/invoiceItems/${client.value.id}`, client.value)
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Item updated successfully.');
                    crudModalRef.value?.clearDirtyGuard();
                    document.getElementById('clientI_modal-closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating item. Please try again.', 'error');
                    console.error(error);
                });
        } else {
            axios.post('/api/v1/invoiceItems', client.value)
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Item added successfully.');
                    crudModalRef.value?.clearDirtyGuard();
                    document.getElementById('clientI_modal-closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding item. Please try again.', 'error');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.description = "";
        client.value.quantity = 0;
        client.value.tax = 0;
        client.value.tax_rate_id = defaultTaxRate();
        client.value.rate = 0;
        client.value.total_amount = 0;
        client.value.utbms_task_code = "";
        client.value.utbms_activity_code = "";
        client.value.utbms_expense_code = "";
        modal.value.title = "Add Item";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const delete_rowI = (item) => {
    confirmDelete({
        url: `/api/v1/invoiceItems/${item.id}`,
        itemLabel: item.description,
        onSuccess: () => { fetchData(); },
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
    client.value.tax_rate_id = item.tax_rate_id ?? null;
    client.value.rate = item.rate;
    client.value.total_amount = item.total_amount;
    client.value.utbms_task_code = item.utbms_task_code || "";
    client.value.utbms_activity_code = item.utbms_activity_code || "";
    client.value.utbms_expense_code = item.utbms_expense_code || "";

    modal.value.title = "Update Item";
    modal.value.button = "Update";
};

const fetchData = () => {
    let invoiceId = localStorage.getItem('invoiceId');
    axios.get(`/api/v1/invoiceItems/${invoiceId}`)
        .then(response => {
            itemsI.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching items:', error);
            showMessage('Could not load invoice items. Please try refreshing the page.', 'error');
        });

    axios.get(`/api/v1/invoices/${invoiceId}`)
        .then(response => {
            invoice.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching invoice:', error);
            showMessage('Could not load the invoice. Please try refreshing the page.', 'error');
        });
};

onMounted(() => {
    fetchData();
    axios.get('/api/v1/finance/settings').then(({ data }) => {
        taxRates.value = data.data.tax_rates;
        if (!client.value.id) client.value.tax_rate_id = defaultTaxRate();
    }).catch(() => {});
});

const computeTotal = () => {
    // Bug fix: this previously set total_amount to quantity*rate only,
    // excluding tax — and since the backend used to trust this value
    // verbatim (see InvoiceItemController), every invoice's grand total
    // silently excluded VAT. The backend now always recomputes this
    // authoritatively regardless of what's sent, but the displayed
    // (readonly) preview should still show the real total the item will
    // actually be billed at, not a pre-tax figure.
    let subtotal = client.value.quantity * client.value.rate;
    const rate = taxRates.value.find(t => t.id === client.value.tax_rate_id);
    client.value.tax = rate ? Math.round(subtotal * rate.rate) / 100 : 0;   // DATA-14: the server works it out the same way
    client.value.total_amount = subtotal + client.value.tax;
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border-radius: 12px;
    stroke-width: 2;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
}

.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4);
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
    border-left: 4px solid var(--primary-600);
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

.items-table {
    width: 100%;
    border-collapse: collapse;

    th, td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
    }

    th {
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.05em;
    }

    tbody tr:hover {
        background: rgba(2, 132, 199, 0.03);
    }
}

.no-items-row {
    text-align: center;
    color: #6b7280;
    padding: 32px 16px !important;
    font-style: italic;
}

.actions-col {
    text-align: center;
    white-space: nowrap;
}

/* UI-9: stacked-card layout on small screens, consistent with the
   shared DataTable component's own mobile behaviour. */
@media (max-width: 768px) {
    .items-table {
        thead {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        tbody, tr, td {
            display: block;
            width: 100%;
        }

        tr {
            margin-bottom: 12px;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            padding: 4px 0;
        }

        td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            text-align: right;

            &::before {
                content: attr(data-label);
                font-weight: 700;
                font-size: 11px;
                text-transform: uppercase;
                color: #6b7280;
                text-align: left;
                flex-shrink: 0;
            }
        }

        .actions-col {
            justify-content: flex-end;

            &::before {
                display: none;
            }
        }
    }
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
    color: var(--primary-600);
    font-weight: 700;
    font-size: 15px;
}

.tax-cell {
    color: #f59e0b;
    font-weight: 600;
    font-size: 15px;
}

.total-cell {
    color: var(--primary-700);
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
    background: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-700) 100%);
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(2, 132, 199, 0.3);
}

.btn-view-full:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(2, 132, 199, 0.45);
}

.btn-view-full svg {
    width: 22px;
    height: 22px;
    stroke-width: 2;
}

/* Modal Styles */
.modern-modal .modal-header {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
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
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
}

.modern-input.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid {
    border-color: var(--primary-600);
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
    color: var(--primary-600);
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 16px rgba(2, 132, 199, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(2, 132, 199, 0.45);
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
