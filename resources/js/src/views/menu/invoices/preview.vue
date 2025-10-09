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
                <div class="invoice-document">
                    <div class="invoice-header">
                        <div class="company-info">
                            <div class="company-branding">
                                <img class="company-logo" src="@/assets/images/cork-logo.png" alt="company" />
                                <h3 class="company-name">Ouma Njoga & Co. Advocates</h3>
                            </div>
                        </div>
                        <div class="invoice-number-section">
                            <p class="invoice-label">Invoice</p>
                            <p class="invoice-number">#{{ invoice.number }}</p>
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

                    <div class="customer-payment-section">
                        <div class="customer-details">
                            <h6 class="section-title">Invoice To</h6>
                            <p class="customer-name">{{ invoice.client }}</p>
                            <p class="customer-address">{{ invoice.client_address }}</p>
                        </div>
                        <div class="payment-info">
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
                                    <th class="text-right">PRICE (KES)</th>
                                    <th class="text-right">AMOUNT (KES)</th>
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
                                <span class="total-value">KES {{ (summary.totals - summary.total_tax) }}</span>
                            </div>
                            <div class="total-row">
                                <span class="total-label">Tax Amount:</span>
                                <span class="total-value">KES {{ summary.total_tax }}</span>
                            </div>
                            <div class="total-row grand-total">
                                <span class="total-label">Grand Total:</span>
                                <span class="total-value">KES {{ summary.totals }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="invoice-note">
                        <p><strong>Note:</strong> Thank you for doing business with us.</p>
                    </div>
                </div>
            </div>

            <div class="invoice-actions">
                <div class="action-buttons">
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
import { onMounted, ref } from 'vue';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Invoice Preview' });

const invoice = ref({});
const summary = ref({});
const items = ref([]);

onMounted(() => {
    fetch_data();
});

const print = () => {
    window.print();
};

const submit_form6 = () => {
    let invoiceId = localStorage.getItem('invoiceId');
    if (confirm('Are you sure you want to post this invoice?')) {
        if (invoiceId) {
            axios.put(`/api/send-to-admin/${invoiceId}`)
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
    axios.get(`/api/preview-invoice/${invoiceId}`)
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
.modern-invoice-preview {
    padding: 20px;
    min-height: 100vh;
}

.invoice-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 24px;
    align-items: start;
}

.invoice-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.invoice-document {
    padding: 48px;
}

.invoice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 40px;
    padding-bottom: 24px;
    border-bottom: 2px solid #e5e7eb;
}

.company-branding {
    display: flex;
    align-items: center;
    gap: 16px;
}

.company-logo {
    width: 60px;
    height: 60px;
    object-fit: contain;
}

.company-name {
    margin: 0;
    font-size: 22px;
    font-weight: 600;
    color: #1f2937;
}

.invoice-number-section {
    text-align: right;
}

.invoice-label {
    margin: 0 0 8px 0;
    font-size: 14px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.invoice-number {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.invoice-details-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 40px;
}

.company-address p,
.invoice-dates p {
    margin: 6px 0;
    color: #6b7280;
    font-size: 14px;
}

.date-label {
    font-weight: 600;
    color: #374151;
    margin-right: 8px;
}

.customer-payment-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
    padding: 24px;
    background: #f9fafb;
    border-radius: 8px;
}

.section-title {
    margin: 0 0 16px 0;
    font-size: 14px;
    font-weight: 700;
    color: #1f2937;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.customer-name {
    margin: 8px 0;
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
}

.customer-address {
    margin: 4px 0;
    color: #6b7280;
    font-size: 14px;
}

.payment-info p {
    margin: 8px 0;
    color: #6b7280;
    font-size: 14px;
}

.info-label {
    font-weight: 500;
    color: #374151;
    margin-right: 8px;
}

.invoice-table-section {
    margin-bottom: 32px;
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
}

.invoice-table thead {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
}

.invoice-table th {
    padding: 14px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.invoice-table th.text-right {
    text-align: right;
}

.invoice-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
}

.invoice-table tbody tr:last-child {
    border-bottom: none;
}

.invoice-table td {
    padding: 16px;
    color: #374151;
    font-size: 14px;
}

.invoice-table td.text-right {
    text-align: right;
}

.totals-section {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 32px;
}

.totals-content {
    width: 400px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #e5e7eb;
}

.total-label {
    color: #6b7280;
    font-size: 14px;
}

.total-value {
    color: #374151;
    font-weight: 500;
    font-size: 14px;
}

.total-row.grand-total {
    border-bottom: none;
    border-top: 2px solid #1f2937;
    padding: 16px 0;
    margin-top: 8px;
}

.grand-total .total-label {
    color: #1f2937;
    font-size: 18px;
    font-weight: 700;
}

.grand-total .total-value {
    color: #1f2937;
    font-size: 18px;
    font-weight: 700;
}

.invoice-note {
    padding: 20px;
    background: #eff6ff;
    border-left: 4px solid #6366f1;
    border-radius: 6px;
}

.invoice-note p {
    margin: 0;
    color: #374151;
    font-size: 14px;
}

.invoice-actions {
    position: sticky;
    top: 20px;
}

.action-buttons {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.btn-action {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-action svg {
    width: 20px;
    height: 20px;
    stroke-width: 2;
}

.btn-back {
    background: #374151;
    color: white;
}

.btn-back:hover {
    background: #1f2937;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(55, 65, 81, 0.3);
}

.btn-print {
    background: #6b7280;
    color: white;
}

.btn-print:hover {
    background: #4b5563;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
}

.btn-post {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
}

.btn-post:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

@media print {
    .modern-invoice-preview {
        padding: 0;
    }

    .invoice-layout {
        grid-template-columns: 1fr;
    }

    .invoice-actions {
        display: none;
    }

    .invoice-document {
        padding: 20px;
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
        min-width: 140px;
    }
}

@media (max-width: 768px) {
    .invoice-document {
        padding: 24px;
    }

    .invoice-header {
        flex-direction: column;
        gap: 20px;
    }

    .invoice-number-section {
        text-align: left;
    }

    .invoice-details-row {
        flex-direction: column;
        gap: 20px;
    }

    .customer-payment-section {
        grid-template-columns: 1fr;
        gap: 24px;
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
</style>