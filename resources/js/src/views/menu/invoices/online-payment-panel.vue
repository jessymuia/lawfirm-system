<template>
    <div class="online-pay-card" data-testid="online-payment">
        <div class="head">
            <h3>Online payment</h3>
            <span class="hint">The client pays by M-Pesa or card from a secure link; the payment is recorded here automatically.</span>
        </div>
        <div class="actions">
            <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="create(true)">Send payment link by SMS</button>
            <button type="button" class="btn btn-outline-primary btn-sm" :disabled="busy" @click="create(false)">Get link</button>
        </div>
        <p v-if="url" class="link-row">
            <input :value="url" class="form-control" readonly aria-label="Payment link" @focus="$event.target.select()" />
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="copy">{{ copied ? 'Copied' : 'Copy' }}</button>
        </p>
        <p v-if="methods && !methods.mpesa && !methods.card" class="warn">
            No payment method is set up for the firm yet (ask your platform administrator to set up M-Pesa or Stripe).
        </p>
        <table v-if="attempts.length" class="table table-sm">
            <thead><tr><th>When</th><th>Method</th><th>Amount</th><th>Result</th><th>Receipt</th></tr></thead>
            <tbody>
                <tr v-for="a in attempts" :key="a.id">
                    <td>{{ (a.created_at || '').slice(0, 16) }}</td>
                    <td>{{ a.provider === 'mpesa' ? 'M-Pesa' : 'Card' }}</td>
                    <td>{{ a.currency }} {{ Number(a.amount).toLocaleString() }}</td>
                    <td :class="a.state">{{ a.state }}<small v-if="a.error"> ({{ a.error }})</small></td>
                    <td>{{ a.receipt || '' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
/** FUN-11: make / text a payment link for an issued invoice and see the online attempts. */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';

const props = defineProps({ invoiceId: { type: [Number, String], required: true } });
const emit = defineEmits(['paid']);
const { showMessage } = useToast();

const busy = ref(false);
const url = ref('');
const copied = ref(false);
const methods = ref(null);
const attempts = ref([]);

const load = () => axios.get(`/api/v1/invoices/${props.invoiceId}/payment-links`).then(({ data }) => {
    const before = attempts.value.filter((a) => a.state === 'succeeded').length;
    attempts.value = data.data.attempts;
    if (attempts.value.filter((a) => a.state === 'succeeded').length > before && before >= 0) emit('paid');
}).catch(() => {});

const create = (sms) => {
    busy.value = true;
    axios.post(`/api/v1/invoices/${props.invoiceId}/payment-links`, { send_sms: sms })
        .then(({ data }) => { url.value = data.data.url; methods.value = data.data.methods; showMessage(data.message); copied.value = false; })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not create the link.', 'error'))
        .finally(() => { busy.value = false; });
};

const copy = async () => {
    try { await navigator.clipboard.writeText(url.value); copied.value = true; } catch { copied.value = false; }
};

onMounted(load);
</script>

<style lang="scss" scoped>
.online-pay-card { margin-top: 16px; padding: 16px; border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; background: var(--card-bg, #fff); }
.head h3 { font-size: 16px; margin: 0; } .hint { font-size: 12px; color: var(--text-secondary, #64748b); }
.actions { display: flex; gap: 8px; margin: 10px 0; }
.link-row { display: flex; gap: 8px; }
.warn { color: #b45309; font-size: 13px; }
.succeeded { color: #15803d; } .failed { color: #b42318; }
@media print { .online-pay-card { display: none; } }
</style>
