<template>
    <div class="elevated-card payments-card">
        <div class="card-header">
            <h2 class="card-title">Payments</h2>
            <div class="header-actions">
                <span class="balance-badge" :class="{ paid: outstandingBalance <= 0 }">
                    Outstanding: {{ formatMoney(outstandingBalance) }}
                </span>
                <button
                    v-if="workflowStatus === 'submitted' && outstandingBalance > 0"
                    type="button"
                    class="btn-record-payment"
                    @click="openForm"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Record Payment
                </button>
            </div>
        </div>

        <div class="card-body">
            <div v-if="workflowStatus === 'draft'" class="payments-note">
                Payments can be recorded once this invoice is submitted.
            </div>
            <div v-if="loading" class="pay-loading">Loading payments...</div>
            <div v-else-if="!payments.length" class="pay-empty">
                No payments recorded yet.
            </div>
            <ul v-else class="payments-list">
                <li v-for="p in payments" :key="p.id">
                    <div class="pay-main">
                        <span class="pay-receipt">{{ p.receipt_number }}</span>
                        <span class="pay-method">{{ formatMethod(p.method) }}</span>
                        <span class="pay-date">{{ p.payment_date }}</span>
                        <span class="pay-by">Received by {{ p.received_by }}</span>
                    </div>
                    <div class="pay-side">
                        <span class="pay-amount">{{ formatMoney(p.amount) }}</span>
                        <button type="button" class="btn-void" @click="voidPayment(p)" title="Void this payment">Void</button>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Record payment dialog (Vue-controlled; no Bootstrap modal plumbing) -->
        <teleport to="body">
            <div v-if="showModal" class="pay-overlay" @mousedown.self="closeForm">
                <div class="pay-dialog" role="dialog" aria-modal="true" aria-label="Record payment">
                    <div class="pay-dialog-head">
                        <h4>Receive payment</h4>
                        <button type="button" class="pay-x" aria-label="Close" @click="closeForm">&times;</button>
                    </div>
                    <div class="pay-tabs" role="tablist">
                        <button type="button" role="tab" :class="{ on: tab === 'manual' }" @click="tab = 'manual'">Record payment</button>
                        <button type="button" role="tab" :class="{ on: tab === 'mpesa' }" @click="tab = 'mpesa'">M-Pesa (STK push)</button>
                        <button type="button" role="tab" :class="{ on: tab === 'card' }" @click="tab = 'card'">Card</button>
                    </div>
                    <div class="pay-dialog-body">
                        <div class="pay-due">Outstanding <strong>{{ formatMoney(outstandingBalance) }}</strong></div>

                        <form v-if="tab === 'manual'" @submit.prevent="submitPayment">
                            <p class="form-hint">For money already received: cash, bank transfer, cheque or a mobile money payment you have confirmed.</p>
                            <div class="form-group">
                                <label class="form-label">Amount <span class="required">*</span></label>
                                <input type="number" step="0.01" min="0.01" :max="outstandingBalance" v-model="form.amount" class="form-control modern-input"
                                    :class="[hasError('amount') ? 'is-invalid' : '']" @input="clearErrors('amount')" />
                                <div class="invalid-feedback">{{ fieldError('amount') }}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Payment date <span class="required">*</span></label>
                                <AppDatePicker v-model="form.payment_date" :invalid="submitted && !form.payment_date || hasError('payment_date')" />
                                <div v-if="hasError('payment_date')" class="invalid-feedback d-block">{{ fieldError('payment_date') }}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Method <span class="required">*</span></label>
                                <select v-model="form.method" class="form-select modern-select" :class="[hasError('method') ? 'is-invalid' : '']" @change="clearErrors('method')">
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank transfer</option>
                                    <option value="mobile_money">Mobile money</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="card">Card</option>
                                    <option value="other">Other</option>
                                </select>
                                <div class="invalid-feedback">{{ fieldError('method') }}</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reference number</label>
                                <input type="text" v-model="form.reference_number" class="form-control modern-input" placeholder="e.g. M-Pesa code, cheque number" />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <input type="text" v-model="form.notes" class="form-control modern-input" />
                            </div>
                            <p v-if="formError" class="pay-error" role="alert">{{ formError }}</p>
                            <button type="submit" class="btn-submit" :disabled="submitting">{{ submitting ? 'Saving...' : 'Record payment' }}</button>
                        </form>

                        <form v-else-if="tab === 'mpesa'" @submit.prevent="sendStk">
                            <p v-if="methods && !methods.mpesa" class="pay-error">M-Pesa is not set up for this firm yet. Ask your platform administrator to set up the firm's M-Pesa account.</p>
                            <p class="form-hint">A payment prompt is sent to the client's phone for the outstanding amount ({{ formatMoney(outstandingBalance) }}). The payment is recorded automatically once they enter their PIN.</p>
                            <div class="form-group">
                                <label class="form-label">Client's Safaricom number <span class="required">*</span></label>
                                <input type="tel" v-model="stk.phone" class="form-control modern-input" placeholder="0712 345 678" :disabled="stk.waiting" />
                            </div>
                            <p v-if="stk.message" :class="['pay-note', stk.state]" role="status">{{ stk.message }}</p>
                            <button type="submit" class="btn-submit" :disabled="stk.waiting || !stk.phone || (methods && !methods.mpesa)">
                                {{ stk.waiting ? 'Waiting for the client...' : 'Send M-Pesa prompt' }}
                            </button>
                        </form>

                        <div v-else>
                            <p v-if="methods && !methods.card" class="pay-error">Card payment is not set up for this firm yet. Ask your platform administrator to set up the firm's Stripe account.</p>
                            <p class="form-hint">Opens a secure Stripe checkout for {{ formatMoney(outstandingBalance) }}. Take the card details yourself, or copy the link to the client. The payment is recorded automatically when the card is charged.</p>
                            <p v-if="card.url" class="pay-note"><input class="form-control" readonly :value="card.url" @focus="$event.target.select()" aria-label="Card checkout link" /></p>
                            <p v-if="card.message" :class="['pay-note', card.state]" role="status">{{ card.message }}</p>
                            <button type="button" class="btn-submit" :disabled="card.busy || (methods && !methods.card)" @click="startCard">
                                {{ card.busy ? 'Preparing...' : (card.url ? 'Open checkout again' : 'Open card checkout') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </teleport>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import axios, { fetchAllPages } from '../../../api';
import { useToast } from '@/composables/use-toast';
import { useFormErrors } from '@/composables/use-form-errors';
import AppDatePicker from '@/components/ui/app-date-picker.vue';
import { formatWireDate } from '@/utils/date';

// FUN-2 frontend: payments/receipts against an invoice. Self-contained,
// mirroring the TimeEntriesPanel pattern used on the case detail page.
const props = defineProps({
    invoiceId: { type: [String, Number], required: true },
    totalAmount: { type: [String, Number], default: 0 },
    amountPaid: { type: [String, Number], default: 0 },
    workflowStatus: { type: String, default: '' },
});

const emit = defineEmits(['updated']);

const { showMessage } = useToast();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const payments = ref([]);
const loading = ref(true);
const submitting = ref(false);
const submitted = ref(false);
const blankForm = () => ({ amount: '', payment_date: formatWireDate(new Date()), method: 'cash', reference_number: '', notes: '' });
const form = ref(blankForm());
const showModal = ref(false);
const tab = ref('manual');
const formError = ref('');
const methods = ref(null);
const stk = reactive({ phone: '', waiting: false, state: '', message: '' });
const card = reactive({ busy: false, url: '', state: '', message: '' });
let pollTimer = null;
const stopPolling = () => { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } };

const outstandingBalance = computed(() => {
    const outstanding = Number(props.totalAmount || 0) - Number(props.amountPaid || 0);
    return outstanding > 0 ? outstanding : 0;
});

const formatMoney = (value) => {
    const n = Number(value) || 0;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const formatMethod = (method) => {
    const labels = {
        cash: 'Cash',
        bank_transfer: 'Bank Transfer',
        mobile_money: 'Mobile Money',
        cheque: 'Cheque',
        card: 'Card',
        other: 'Other',
    };
    return labels[method] || method;
};

const fetchPayments = () => {
    loading.value = true;
    fetchAllPages('/api/v1/payments', { invoice_id: props.invoiceId })
        .then(({ rows }) => {
            payments.value = rows;
        })
        .catch(error => {
            console.error('Error fetching payments:', error);
            showMessage('Could not load payments. Please try refreshing the page.', 'error');
        })
        .finally(() => {
            loading.value = false;
        });
};

const loadMethods = () => axios.get(`/api/v1/invoices/${props.invoiceId}/payment-links`)
    .then(({ data }) => { methods.value = data.data?.methods || null; })
    .catch(() => { methods.value = null; });

const openForm = () => {
    clearErrors();
    submitted.value = false;
    formError.value = '';
    form.value = { ...blankForm(), amount: outstandingBalance.value ? outstandingBalance.value.toFixed(2) : '' };
    Object.assign(stk, { waiting: false, state: '', message: '' });
    Object.assign(card, { busy: false, url: '', state: '', message: '' });
    tab.value = 'manual';
    showModal.value = true;
    loadMethods();
};

const closeForm = () => { stopPolling(); showModal.value = false; };

const afterPaid = () => { fetchPayments(); emit('updated'); };

const submitPayment = () => {
    submitted.value = true;
    clearErrors();
    formError.value = '';

    if (!form.value.amount || !form.value.payment_date || !form.value.method) {
        formError.value = 'Enter the amount and the payment date.';
        return;
    }

    submitting.value = true;
    axios.post('/api/v1/payments', {
        invoice_id: props.invoiceId,
        amount: form.value.amount,
        payment_date: form.value.payment_date,
        method: form.value.method,
        reference_number: form.value.reference_number || null,
        notes: form.value.notes || null,
    })
        .then(({ data }) => {
            showMessage(`Payment recorded (${data.data?.receipt_number || 'receipt issued'}).`);
            closeForm();
            afterPaid();
        })
        .catch(error => {
            const msg = setErrorsFromResponse(error, 'The payment could not be recorded.');
            formError.value = error.response?.status === 403 ? 'You do not have permission to record payments.' : msg;
        })
        .finally(() => {
            submitting.value = false;
        });
};

// Poll an STK push until the client pays, declines or it times out (about 2 minutes).
const pollAttempt = (attemptId, tries = 0) => {
    pollTimer = setTimeout(() => {
        axios.get(`/api/v1/invoices/${props.invoiceId}/pay/attempts/${attemptId}`).then(({ data }) => {
            const a = data.data;
            if (a.state === 'succeeded') {
                stk.waiting = false; stk.state = 'ok'; stk.message = `Paid${a.receipt ? ` (M-Pesa ${a.receipt})` : ''}. The payment has been recorded.`;
                showMessage('M-Pesa payment received.');
                afterPaid();
                setTimeout(closeForm, 1800);
            } else if (a.state === 'failed') {
                stk.waiting = false; stk.state = 'bad'; stk.message = a.error || 'The client did not complete the payment.';
            } else if (tries >= 40) {
                stk.waiting = false; stk.state = 'bad'; stk.message = 'No answer yet. If the client paid, the payment will appear here shortly; otherwise send the prompt again.';
            } else {
                pollAttempt(attemptId, tries + 1);
            }
        }).catch(() => { if (tries < 40) pollAttempt(attemptId, tries + 1); });
    }, 3000);
};

const sendStk = () => {
    stopPolling();
    stk.waiting = true; stk.state = ''; stk.message = '';
    axios.post(`/api/v1/invoices/${props.invoiceId}/pay/mpesa`, { phone: stk.phone })
        .then(({ data }) => { stk.message = data.message; pollAttempt(data.data.attempt); })
        .catch((error) => {
            stk.waiting = false; stk.state = 'bad';
            stk.message = error.response?.data?.errors?.phone?.[0] || error.response?.data?.message || 'Could not send the M-Pesa prompt.';
        });
};

const startCard = () => {
    card.busy = true; card.state = ''; card.message = '';
    axios.post(`/api/v1/invoices/${props.invoiceId}/pay/card`)
        .then(({ data }) => {
            card.url = data.data.redirect;
            window.open(card.url, '_blank', 'noopener');
            card.message = 'Checkout opened in a new tab. This page updates when the card is charged.';
            watchCard(data.data.attempt);
        })
        .catch((error) => { card.state = 'bad'; card.message = error.response?.data?.message || 'Could not start the card payment.'; })
        .finally(() => { card.busy = false; });
};

const watchCard = (attemptId, tries = 0) => {
    stopPolling();
    pollTimer = setTimeout(() => {
        axios.get(`/api/v1/invoices/${props.invoiceId}/pay/attempts/${attemptId}`).then(({ data }) => {
            if (data.data.state === 'succeeded') {
                card.state = 'ok'; card.message = 'Card payment received and recorded.';
                afterPaid();
                setTimeout(closeForm, 1800);
            } else if (data.data.state === 'failed') {
                card.state = 'bad'; card.message = data.data.error || 'The card payment was not completed.';
            } else if (tries < 100) {
                watchCard(attemptId, tries + 1);
            }
        }).catch(() => { if (tries < 100) watchCard(attemptId, tries + 1); });
    }, 3000);
};

const voidPayment = (payment) => {
    window.Swal.fire({
        title: 'Void this payment?',
        text: `This will remove ${payment.receipt_number} (${formatMoney(payment.amount)}) from the invoice's paid total. This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--color-danger)',
        confirmButtonText: 'Void it',
    }).then((result) => {
        if (!result.isConfirmed) return;

        axios.delete(`/api/v1/payments/${payment.id}`)
            .then(() => {
                showMessage('Payment voided.');
                fetchPayments();
                emit('updated');
            })
            .catch(error => {
                showMessage(error.response?.data?.message || 'Error voiding payment.', 'error');
            });
    });
};

// Re-fetch if the parent swaps to a different invoice without remounting
// this component (unlikely on this page today, but keeps the panel
// correct if that ever changes).
watch(() => props.invoiceId, fetchPayments);

onMounted(() => {
    fetchPayments();
});
onBeforeUnmount(stopPolling);
</script>

<style lang="scss" scoped>
.payments-card .card-header {
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
}

.balance-badge {
    font-size: 12px;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(231, 81, 90, 0.1);
    color: #e7515a;

    &.paid {
        background: rgba(16, 185, 129, 0.1);
        color: var(--primary-600);
    }
}

.btn-record-payment {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.payments-note {
    padding: 12px 16px;
    background: rgba(107, 114, 128, 0.08);
    border-radius: 8px;
    color: #6b7280;
    font-size: 13px;
    margin-bottom: 16px;
}

.pay-loading, .pay-empty {
    padding: 24px 0;
    text-align: center;
    color: #6b7280;
    font-size: 13px;
}

.payments-list {
    list-style: none;
    margin: 0;
    padding: 0;

    li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);

        &:last-child {
            border-bottom: none;
        }
    }
}

.pay-main {
    display: flex;
    flex-direction: column;
    gap: 2px;

    .pay-receipt {
        font-weight: 700;
        font-size: 13px;
    }

    .pay-method, .pay-date, .pay-by {
        font-size: 12px;
        color: #6b7280;
    }
}

.pay-side {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.pay-amount {
    font-weight: 700;
    color: var(--primary-600);
    font-size: 14px;
}

.btn-void {
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid #e7515a;
    color: #e7515a;
    background: transparent;
    border-radius: 6px;
    cursor: pointer;

    &:hover {
        background: #e7515a;
        color: white;
    }
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

.form-hint {
    font-size: 11px;
    color: #6b7280;
    margin-top: 4px;
}

.required {
    color: #e7515a;
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

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}

.pay-overlay { position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, 0.55); display: flex; align-items: center; justify-content: center; padding: 16px; overflow-y: auto; }
.pay-dialog { width: 100%; max-width: 520px; max-height: calc(100vh - 32px); overflow-y: auto; background: var(--card-bg, #fff); color: inherit; border-radius: 14px; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3); }
.pay-dialog-head { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid rgba(0, 0, 0, 0.08); }
.pay-dialog-head h4 { margin: 0; font-size: 18px; }
.pay-x { border: 0; background: none; font-size: 28px; line-height: 1; cursor: pointer; color: #64748b; }
.pay-tabs { display: flex; gap: 4px; padding: 10px 20px 0; border-bottom: 1px solid rgba(0, 0, 0, 0.08); }
.pay-tabs button { flex: 1; padding: 10px 6px; border: 0; border-bottom: 3px solid transparent; background: none; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; }
.pay-tabs button.on { color: var(--primary-700, #1d4ed8); border-bottom-color: var(--primary-600, #2563eb); }
.pay-dialog-body { padding: 18px 20px 22px; }
.pay-due { margin-bottom: 14px; font-size: 14px; color: #475569; }
.pay-due strong { font-size: 20px; color: #0f172a; margin-left: 6px; }
.pay-error { color: #b42318; background: rgba(231, 81, 90, 0.08); border-radius: 8px; padding: 10px 12px; font-size: 13px; }
.pay-note { font-size: 13px; padding: 10px 12px; border-radius: 8px; background: rgba(37, 99, 235, 0.08); }
.pay-note.ok { background: rgba(16, 185, 129, 0.12); color: #047857; }
.pay-note.bad { background: rgba(231, 81, 90, 0.08); color: #b42318; }
.form-hint { font-size: 13px; }
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
