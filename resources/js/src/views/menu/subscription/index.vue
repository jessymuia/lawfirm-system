<template>
    <div class="subscription-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Subscription</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="elevated-card">
            <div class="card-header">
                <h2 class="card-title">Your Plan</h2>
            </div>
            <div class="card-body">
                <p v-if="unavailable" class="hint">Subscriptions apply to the hosted service. This installation is licensed instead.</p>
                <p v-else-if="!sub" class="hint">Loading…</p>
                <template v-else>
                    <p class="current" data-testid="current-plan">
                        <strong>{{ label(sub.plan) }}</strong>
                        <span v-if="sub.plan === 'trial' && !sub.trial_expired"> · {{ sub.trial_days_left }} day{{ sub.trial_days_left === 1 ? '' : 's' }} of the free trial left</span>
                        <span v-else-if="sub.trial_expired" class="expired"> · the free trial has ended; the firm is read-only until you choose a plan</span>
                    </p>
                    <p class="hint">
                        Choosing a plan during the trial takes effect now and is first invoiced when the trial ends. Invoices
                        are sent to the billing email and can be paid by M-Pesa or card.
                    </p>
                    <div v-if="sub.period_end && sub.status !== 'canceled'" class="period-summary">
                        <span>Current period ends {{ date(sub.period_end) }}</span>
                        <span v-if="sub.cancel_at_period_end" class="status status-warning">Cancellation scheduled</span>
                        <button
                            v-else
                            type="button"
                            class="btn btn-outline-danger"
                            :disabled="busy"
                            data-testid="cancel-subscription"
                            @click="cancel"
                        >
                            Cancel subscription
                        </button>
                    </div>
                    <p v-if="sub.cancel_at_period_end" class="notice" data-testid="cancellation-notice">
                        Your subscription is set to end on {{ date(sub.period_end) }}. Your firm keeps its current plan until then.
                    </p>
                    <p v-else-if="sub.status === 'canceled'" class="notice">
                        This subscription has ended. Contact your firm administrator for help restarting service.
                    </p>
                </template>
            </div>
        </div>

        <div v-if="sub" class="plans">
            <div v-for="p in sub.plans" :key="p.plan" class="elevated-card plan" :class="{ 'is-current': p.plan === sub.plan }" :data-testid="'plan-' + p.plan">
                <div class="card-body">
                    <h3>{{ label(p.plan) }}</h3>
                    <p class="price">{{ money(p.price, p.currency) }} <span>/ month</span></p>
                    <ul>
                        <li>{{ p.limits.users === null ? 'Unlimited' : p.limits.users }} users</li>
                        <li>{{ storage(p.limits.storage_bytes) }} of documents</li>
                        <li v-for="f in p.features" :key="f">{{ featureLabel(f) }}</li>
                    </ul>
                    <button
                        type="button"
                        class="btn"
                        :class="p.plan === sub.plan ? 'btn-outline-primary' : 'btn-primary'"
                        :disabled="p.plan === sub.plan || busy || sub.status === 'canceled' || sub.cancel_at_period_end"
                        @click="choose(p.plan)"
                    >
                        {{ p.plan === sub.plan ? 'Current plan' : sub.status === 'canceled' ? 'Unavailable on an ended subscription' : sub.cancel_at_period_end ? 'Unavailable while cancellation is scheduled' : 'Choose ' + label(p.plan) }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="sub" class="elevated-card invoices-card" data-testid="billing-invoices">
            <div class="card-header">
                <h2 class="card-title">Invoices and payments</h2>
            </div>
            <div class="card-body">
                <p v-if="!sub.invoices?.length" class="hint">Your invoices will appear here when billing begins.</p>
                <template v-else>
                    <p v-if="sub.mpesa_paybill" class="hint payment-help">
                        Pay a KES invoice by M-Pesa paybill <strong>{{ sub.mpesa_paybill }}</strong>; use the invoice number as the account number.
                    </p>
                    <div class="invoice-list">
                        <article v-for="invoice in sub.invoices" :key="invoice.id" class="invoice-row" :data-testid="'invoice-' + invoice.number">
                            <div class="invoice-main">
                                <strong>{{ invoice.number }}</strong>
                                <span class="invoice-meta">{{ invoice.kind === 'adjustment' ? 'Plan adjustment' : 'Subscription' }} · issued {{ date(invoice.issued_at) }}</span>
                                <span v-if="invoice.status === 'open'" class="invoice-meta">Due {{ date(invoice.due_at) }}</span>
                            </div>
                            <div class="invoice-amounts">
                                <strong>{{ money(invoice.total, invoice.currency) }}</strong>
                                <span class="status" :class="invoice.status === 'paid' ? 'status-paid' : invoice.status === 'open' ? 'status-open' : 'status-void'">
                                    {{ invoice.status === 'open' && invoice.amount_due < invoice.total ? 'Partially paid' : invoice.status }}
                                </span>
                                <span v-if="invoice.status === 'open'" class="invoice-meta">
                                    Balance {{ money(invoice.amount_due, invoice.currency) }}
                                </span>
                            </div>
                            <a
                                v-if="invoice.pay_url"
                                class="btn btn-primary pay-button"
                                :href="invoice.pay_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                :aria-label="'Pay invoice ' + invoice.number + ' by card'"
                            >
                                Pay by card
                            </a>
                            <span v-else-if="invoice.status === 'open' && invoice.amount_due > 0 && invoice.currency === 'KES' && sub.mpesa_paybill" class="invoice-meta pay-instructions">
                                Paybill {{ sub.mpesa_paybill }} · account {{ invoice.number }}
                            </span>
                        </article>
                    </div>
                    <p class="hint invoices-footnote">Showing your 50 most recent invoices. Payments may take a short time to appear here after confirmation.</p>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
/** PLAT-13: the firm's plan, trial, invoices, plan changes and cancellation (manage-subscription). */
import { onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';

useMeta({ title: 'Subscription' });
const { showMessage } = useToast();

const sub = ref(null);
const busy = ref(false);

const label = (plan) => (plan ? plan.charAt(0).toUpperCase() + plan.slice(1) : '');
const money = (minor, currency) => new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: currency === 'KES' ? 0 : 2 }).format(minor / 100);
const date = (value) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : '—';
const storage = (bytes) => (bytes === null ? 'Unlimited' : `${Math.round(bytes / 1024 ** 3)} GB`);
const featureLabel = (f) => ({
    time_tracking: 'Time tracking', payments: 'Payments', exports: 'Exports', document_versioning: 'Document versions',
    trust_accounting: 'Trust accounting', firm_data_export: 'Full firm data export', api_access: 'API and webhooks', sso: 'Single sign-on', client_portal: 'Client portal',
}[f] || f.replace(/_/g, ' '));

const unavailable = ref(false);
const load = () => axios.get('/api/v1/subscription')
    .then(({ data }) => {
        sub.value = data.data;
    })
    .catch(() => {
        unavailable.value = true;   // self-hosted installs have a license, not a subscription
    });

const choose = async (plan) => {
    const ok = await window.Swal.fire({
        title: `Switch to ${label(plan)}?`,
        text: sub.value.plan === 'trial' ? 'Your plan changes now; the first invoice is issued when the trial ends.' : 'Your plan changes now; the difference for this period is invoiced or credited.',
        showCancelButton: true,
        confirmButtonText: 'Switch',
    });
    if (!ok.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await axios.post('/api/v1/subscription/plan', { plan });
        sub.value = data.data;
        showMessage(data.message);
    } catch (e) {
        showMessage(e.response?.data?.message || 'Could not change the plan.', 'error');
    } finally {
        busy.value = false;
    }
};

const cancel = async () => {
    const result = await window.Swal.fire({
        title: 'Cancel this subscription?',
        text: `Your current plan stays active until ${date(sub.value.period_end)}. No new billing period will begin after that date.`,
        showCancelButton: true,
        confirmButtonText: 'Schedule cancellation',
        confirmButtonColor: '#b42318',
    });
    if (!result.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await axios.post('/api/v1/subscription/cancel');
        sub.value = data.data;
        showMessage(data.message);
    } catch (e) {
        showMessage(e.response?.data?.message || 'Could not cancel the subscription.', 'error');
    } finally {
        busy.value = false;
    }
};

onMounted(load);
</script>

<style lang="scss" scoped>
.hint {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.expired {
    color: #b42318;
}

.period-summary,
.invoice-row,
.invoice-main,
.invoice-amounts {
    display: flex;
    align-items: center;
}

.period-summary {
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
    font-size: 14px;
}

.notice {
    margin: 14px 0 0;
    padding: 12px 14px;
    border-radius: 8px;
    background: #fff8e6;
    color: #744a00;
    font-size: 13px;
}

.invoices-card {
    margin-top: 20px;
}

.payment-help {
    margin-bottom: 16px;
}

.invoice-list {
    display: grid;
}

.invoice-row {
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
}

.invoice-row:last-child {
    border-bottom: 0;
}

.invoice-main,
.invoice-amounts {
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
}

.invoice-meta {
    color: var(--text-secondary, #64748b);
    font-size: 12px;
}

.status {
    display: inline-block;
    width: fit-content;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
}

.status-paid {
    color: #067647;
    background: #ecfdf3;
}

.status-open,
.status-warning {
    color: #9a6700;
    background: #fff8e6;
}

.status-void {
    color: #667085;
    background: #f2f4f7;
}

.pay-button {
    white-space: nowrap;
}

.invoices-footnote {
    margin: 12px 0 0;
}

.plans {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}

.plan {
    &.is-current {
        outline: 2px solid var(--primary-600);
    }

    h3 {
        font-size: 18px;
        margin: 0 0 4px;
    }

    .price {
        font-size: 22px;
        font-weight: 700;

        span {
            font-size: 13px;
            font-weight: 400;
            color: var(--text-secondary, #64748b);
        }
    }

    ul {
        padding-left: 18px;
        font-size: 13px;
        min-height: 120px;
    }
}

@media (max-width: 650px) {
    .invoice-row {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }

    .period-summary {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
