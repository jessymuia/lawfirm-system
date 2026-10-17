<template>
    <div class="modern-invoice-preview">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Invoices</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Preview</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="invoice-layout">
            <div class="invoice-content">
                <!-- Main Invoice Card with Elevation -->
                <div class="elevated-card invoice-document">
                    <div class="invoice-header">
                        <div class="company-info">
                            <div class="company-branding">
                                <img v-if="branding.logo_url" class="company-logo" :src="branding.logo_url" alt="" />
                                <h3 class="company-name">{{ branding.name }}</h3>
                            </div>
                        </div>
                        <div class="invoice-number-section">
                            <p class="invoice-label">Invoice</p>
                            <p class="invoice-number">#{{ invoice.number }}</p>
                            <InvoiceStatus v-if="invoice.workflow_status" class="invoice-state" :payment-status="invoice.payment_status" :status="invoice.workflow_status" :due-date="invoice.due_date" />
                        </div>
                    </div>

                    <div class="invoice-details-row">
                        <div class="company-address">
                            <p>XYZ Delta Street</p>
                            <p>info@company.com</p>
                            <p>(120) 456 789</p>
                        </div>
                        <div class="invoice-dates">
                            <p><span class="date-label">Invoice Date:</span> {{ invoice.date }}</p>
                            <p><span class="date-label">Due Date:</span> {{ invoice.due_date }}</p>
                        </div>
                    </div>

                    <!-- Customer & Payment Info Card -->
                    <div class="info-cards-container">
                        <div class="info-card customer-card">
                            <h6 class="section-title">Invoice To</h6>
                            <p class="customer-name">{{ invoice.client }}</p>
                            <p class="customer-address">{{ invoice.client_address }}</p>
                        </div>
                        <div class="info-card payment-card">
                            <h6 class="section-title">Payment Info</h6>
                            <p><span class="info-label">Bank Name:</span> KCB</p>
                            <p><span class="info-label">Account Number:</span> 1234567890</p>
                            <p><span class="info-label">SWIFT Code:</span> VS70134</p>
                            <p><span class="info-label">Country:</span> KENYA</p>
                        </div>
                    </div>

                    <div class="invoice-table-section">
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th>S.NO</th>
                                    <th>ITEMS</th>
                                    <th>QTY</th>
                                    <th class="text-right">PRICE ({{ currency }})</th>
                                    <th class="text-right">AMOUNT ({{ currency }})</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, index) in items" :key="item.id">
                                    <td>{{ index + 1 }}</td>
                                    <td>{{ item.item }}</td>
                                    <td>{{ item.quantity }}</td>
                                    <td class="text-right">{{ item.price }}</td>
                                    <td class="text-right">{{ item.amount }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="totals-section">
                        <div class="totals-content">
                            <div class="total-row">
                                <span class="total-label">Sub Total:</span>
                                <span class="total-value">{{ currency }} {{ (summary.totals - summary.total_tax) }}</span>
                            </div>
                            <div class="total-row">
                                <span class="total-label">Tax Amount:</span>
                                <span class="total-value">{{ currency }} {{ summary.total_tax }}</span>
                            </div>
                            <div class="total-row grand-total">
                                <span class="total-label">Grand Total:</span>
                                <span class="total-value">{{ currency }} {{ summary.totals }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="invoice-note">
                        <p><strong>Note:</strong> Thank you for doing business with us.</p>
                    </div>
                </div>

                <!-- Payments Panel (FUN-2) -->
                <PaymentsPanel
                    v-if="invoiceId"
                    :invoice-id="invoiceId"
                    :total-amount="invoice.total_amount"
                    :amount-paid="invoice.amount_paid"
                    :workflow-status="invoice.workflow_status"
                    @updated="fetch_data"
                />

                <!-- FUN-11: online payment by the client -->
                <OnlinePaymentPanel
                    v-if="invoiceId && invoice.workflow_status === 'submitted' && canTakePayments"
                    :invoice-id="invoiceId"
                    @paid="fetch_data"
                />
            </div>

            <!-- Action Sidebar with Elevation -->
            <div class="invoice-actions">
                <div class="elevated-card action-buttons">
                    <router-link to="/view-invoice" class="btn-action btn-back">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M19 12H5M12 19l-7-7 7-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Back
                    </router-link>
                    
                    <button class="btn-action btn-print" @click="print()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M6 14h12v8H6z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Print
                    </button>

                    <button class="btn-action btn-pdf" @click="exportPdf" :disabled="exportingPdf">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="14 2 14 8 20 8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        {{ exportingPdf ? 'Generating...' : 'Download PDF' }}
                    </button>
                    
                    <button v-if="invoice.workflow_status === 'submitted'" class="btn-action btn-pdf" :disabled="exportingLedes" @click="exportLedes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M8 13h8M8 17h5" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        {{ exportingLedes ? 'Checking...' : 'E-bill (LEDES)' }}
                    </button>
                    <div v-if="ledes && (ledes.errors.length || ledes.warnings.length)" class="ledes-check" role="status">
                        <strong v-if="ledes.errors.length">Not ready to e-bill:</strong>
                        <ul v-if="ledes.errors.length" class="text-danger"><li v-for="e in ledes.errors" :key="e">{{ e }}</li></ul>
                        <strong v-if="ledes.warnings.length">Worth fixing:</strong>
                        <ul v-if="ledes.warnings.length"><li v-for="w in ledes.warnings" :key="w">{{ w }}</li></ul>
                    </div>

                    <button v-if="invoice.postShow" class="btn-action btn-post" @click="submit_form6()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Post Invoice
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
import { branding } from '@/branding';   // UI-13
import { computed, onMounted, ref } from 'vue';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
import PaymentsPanel from './payments-panel.vue';
import OnlinePaymentPanel from './online-payment-panel.vue';
import InvoiceStatus from '@/components/ui/invoice-status.vue';
import { useFileDownload } from '@/composables/use-file-download';
useMeta({ title: 'Invoice Preview' });

// FUN-11
const canTakePayments = JSON.parse(localStorage.getItem('permissions') || '[]').includes('create-payments');

const invoice = ref({});
const summary = ref({});
const currency = computed(() => invoice.value.currency || 'KES');   // DATA-14: the invoice's own currency
const items = ref([]);
const invoiceId = ref(localStorage.getItem('invoiceId'));
const { downloadFile } = useFileDownload();
const exportingPdf = ref(false);

// FUN-13: the LEDES 1998B e-bill. The server checks it first and says what an e-billing system would reject.
const ledes = ref(null);
const exportingLedes = ref(false);
const exportLedes = () => {
    if (!invoiceId.value) return;
    exportingLedes.value = true;
    axios.get(`/api/v1/invoices/${invoiceId.value}/ledes/check`)
        .then(({ data }) => {
            ledes.value = data.data;
            if (!data.data.errors.length) {
                return downloadFile(`/api/v1/invoices/${invoiceId.value}/ledes`, `LEDES1998B-${invoice.value.number || invoiceId.value}.txt`);
            }
            return null;
        })
        .catch(() => { ledes.value = { errors: ['The e-bill could not be checked. Please try again.'], warnings: [] }; })
        .finally(() => { exportingLedes.value = false; });
};

const exportPdf = () => {
    if (!invoiceId.value) return;
    exportingPdf.value = true;
    downloadFile(`/api/v1/export/invoices/${invoiceId.value}/pdf`, `${invoice.value.number || 'invoice'}.pdf`)
        .finally(() => { exportingPdf.value = false; });
};

onMounted(() => {
    fetch_data();
});

const print = () => {
    window.print();
};

const submit_form6 = async () => {
    let invoiceId = localStorage.getItem('invoiceId');
    if (await confirmAction({ title: 'Post this invoice?', text: 'It will be issued to the client and becomes a financial record. Mistakes after this are corrected with a credit note, not by editing.', confirmText: 'Post invoice' })) {
        if (invoiceId) {
            axios.put(`/api/v1/send-to-admin/${invoiceId}`)
                .then(response => {
                    showMessage('Invoice sent successfully.');
                })
                .catch(error => {
                    showMessage('Error posting the invoice. Please try again.');
                    console.error(error);
                });
        }
    }
};

const fetch_data = () => {
    let invoiceId = localStorage.getItem('invoiceId');
    axios.get(`/api/v1/preview-invoice/${invoiceId}`)
        .then(response => {
            items.value = response.data.data[0];
            invoice.value = response.data.data[1];
            summary.value = response.data.data[2];
        })
        .catch(error => {
            console.error('Error fetching preview:', error);
        });
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
.invoice-state { margin-top: 8px; font-size: 13px; }
.modern-invoice-preview {
    padding: 24px;
    min-height: 100vh;
    background: #f8f9fa;
}

.invoice-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 28px;
    align-items: start;
}

.invoice-content {
    width: 100%;
}

/* Elevated Card Base Styles */
.elevated-card {
    background: white;
    border-radius: 14px;
    box-shadow: 6px 6px 20px rgba(0, 0, 0, 0.08), 
                3px 3px 10px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    transition: transform 0.3s, box-shadow 0.3s;
}

.elevated-card:hover {
    transform: translateY(-4px) translateX(-2px);
    box-shadow: 8px 8px 24px rgba(0, 0, 0, 0.12), 
                4px 4px 12px rgba(0, 0, 0, 0.06);
}

.invoice-document {
    padding: 48px;
}

.invoice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 40px;
    padding-bottom: 28px;
    border-bottom: 3px solid #e5e7eb;
}

.company-branding {
    display: flex;
    align-items: center;
    gap: 18px;
}

.company-logo {
    width: 65px;
    height: 65px;
    object-fit: contain;
    filter: drop-shadow(2px 2px 4px rgba(0, 0, 0, 0.1));
}

.company-name {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
    letter-spacing: -0.5px;
}

.invoice-number-section {
    text-align: right;
    padding: 16px 24px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 12px;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.06);
}

.invoice-label {
    margin: 0 0 8px 0;
    font-size: 13px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
}

.invoice-number {
    margin: 0;
    font-size: 32px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: -1px;
}

.invoice-details-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 40px;
}

.company-address p,
.invoice-dates p {
    margin: 8px 0;
    color: #6b7280;
    font-size: 14px;
    line-height: 1.6;
}

.date-label {
    font-weight: 700;
    color: #374151;
    margin-right: 8px;
}

/* Info Cards with Elevation */
.info-cards-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 44px;
}

.info-card {
    padding: 28px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 12px;
    box-shadow: 3px 3px 12px rgba(0, 0, 0, 0.06);
    border-left: 4px solid var(--primary-600);
    transition: all 0.3s;
}

.info-card:hover {
    box-shadow: 4px 4px 16px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.section-title {
    margin: 0 0 18px 0;
    font-size: 13px;
    font-weight: 800;
    color: #1f2937;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.customer-name {
    margin: 10px 0;
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
}

.customer-address {
    margin: 6px 0;
    color: #6b7280;
    font-size: 14px;
    line-height: 1.6;
}

.info-card p {
    margin: 10px 0;
    color: #6b7280;
    font-size: 14px;
    line-height: 1.6;
}

.info-label {
    font-weight: 700;
    color: #374151;
    margin-right: 8px;
}

.invoice-table-section {
    margin-bottom: 36px;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.06);
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
}

.invoice-table thead {
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
}

.invoice-table th {
    padding: 16px 18px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.invoice-table th.text-right {
    text-align: right;
}

.invoice-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.2s;
}

.invoice-table tbody tr:hover {
    background: #f8fafc;
}

.invoice-table tbody tr:last-child {
    border-bottom: none;
}

.invoice-table td {
    padding: 18px;
    color: #374151;
    font-size: 15px;
    font-weight: 500;
}

.invoice-table td.text-right {
    text-align: right;
    font-weight: 600;
}

.totals-section {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 36px;
}

.totals-content {
    width: 420px;
    padding: 24px;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 12px;
    box-shadow: 3px 3px 12px rgba(0, 0, 0, 0.06);
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid #e5e7eb;
}

.total-label {
    color: #6b7280;
    font-size: 15px;
    font-weight: 600;
}

.total-value {
    color: #374151;
    font-weight: 700;
    font-size: 15px;
}

.total-row.grand-total {
    border-bottom: none;
    border-top: 3px solid #1f2937;
    padding: 20px 0;
    margin-top: 10px;
}

.grand-total .total-label {
    color: #1f2937;
    font-size: 20px;
    font-weight: 800;
}

.grand-total .total-value {
    color: var(--primary-600);
    font-size: 20px;
    font-weight: 800;
}

.invoice-note {
    padding: 24px;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-left: 4px solid var(--primary-600);
    border-radius: 10px;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.04);
}

.invoice-note p {
    margin: 0;
    color: #374151;
    font-size: 14px;
    line-height: 1.6;
}

/* Action Sidebar */
.invoice-actions {
    position: sticky;
    top: 24px;
}

.action-buttons {
    padding: 28px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.btn-action {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 16px 28px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-action svg {
    width: 20px;
    height: 20px;
    stroke-width: 2.5;
}

.btn-back, .btn-print, .btn-pdf, .btn-post { transition: background 0.15s, border-color 0.15s; }
.btn-back { background: #fff; color: var(--text-secondary); border: 1px solid var(--border-color); box-shadow: none; }
.btn-print { background: #fff; color: var(--text-secondary); border: 1px solid var(--border-color); box-shadow: none; }
.btn-back:hover, .btn-print:hover { background: var(--neutral-50); border-color: var(--neutral-300); }
.btn-pdf { background: #fff; color: var(--primary-700); border: 1px solid var(--primary-300); box-shadow: none; }
.btn-pdf:hover:not(:disabled) { background: var(--primary-50); border-color: var(--primary-500); }
.btn-pdf:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-post { background: var(--primary-600); color: #fff; border: 1px solid var(--primary-600); box-shadow: none; }
.btn-post:hover { background: var(--primary-700); }

@media print {
    .modern-invoice-preview {
        padding: 0;
        background: white;
    }

    .invoice-layout {
        grid-template-columns: 1fr;
    }

    .invoice-actions {
        display: none;
    }

    .invoice-document {
        padding: 20px;
        box-shadow: none;
    }

    /* UI-36: page-break rules — keep header/customer info/totals blocks
       intact rather than letting a page break split them, keep the line
       items table's header repeating on every page it spans, and never
       split a single line item across two pages. */
    .invoice-header,
    .invoice-details-row,
    .info-cards-container,
    .totals-section,
    .invoice-note {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .invoice-table thead {
        display: table-header-group;
    }

    .invoice-table tbody tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .elevated-card {
        box-shadow: none;
    }

    .elevated-card:hover {
        transform: none;
    }
}

@media (max-width: 1024px) {
    .invoice-layout {
        grid-template-columns: 1fr;
    }

    .action-buttons {
        flex-direction: row;
        flex-wrap: wrap;
    }

    .btn-action {
        flex: 1;
        min-width: 160px;
    }
}

@media (max-width: 768px) {
    .modern-invoice-preview {
        padding: 16px;
    }

    .invoice-document {
        padding: 28px;
    }

    .invoice-header {
        flex-direction: column;
        gap: 24px;
    }

    .invoice-number-section {
        text-align: left;
        width: 100%;
    }

    .invoice-details-row {
        flex-direction: column;
        gap: 24px;
    }

    .info-cards-container {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .totals-content {
        width: 100%;
    }

    .action-buttons {
        flex-direction: column;
    }

    .btn-action {
        width: 100%;
    }
}

.ledes-check {
    font-size: 13px;
    margin-top: 8px;

    ul { padding-left: 18px; margin: 4px 0 8px; }
}
</style>

<style>
/* UI-36: unscoped on purpose — PaymentsPanel is its own component
   (payments-card), so preview.vue's scoped print rules above can't
   reach into it. A printed invoice document is the bill itself, not
   the interactive payment-recording UI underneath it. */
@media print {
    .payments-card {
        display: none !important;
    }
}
</style>
