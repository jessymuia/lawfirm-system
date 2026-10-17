<template>
    <div class="trust-page">
        <PageHeader title="Trust accounting" subtitle="Reconcile the bank statement to the trust ledger and keep client retainers topped up." icon="M3 10l9-6 9 6M5 10v8M9 10v8M15 10v8M19 10v8M3 20h18" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item active" aria-current="page"><span>Trust Accounting</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="tabs" role="tablist">
            <button v-if="canReconcile" type="button" role="tab" :class="{ active: tab === 'rec' }" @click="tab = 'rec'">Reconciliations</button>
            <button type="button" role="tab" :class="{ active: tab === 'ret' }" @click="tab = 'ret'">Retainers</button>
        </div>

        <!-- ---- reconciliations ------------------------------------------------------------------------------- -->
        <template v-if="tab === 'rec' && canReconcile">
            <div v-if="!current" class="elevated-card">
                <div class="card-header"><h2 class="card-title">Three-way reconciliations</h2></div>
                <div class="card-body">
                    <p class="hint">
                        Each month, match the bank statement to the trust ledger. The bank (adjusted for items not yet
                        cleared), the ledger and the sum of every client's balance must agree.
                    </p>
                    <form class="inline-form" @submit.prevent="start">
                        <label>Statement date <input v-model="draft.period_end" type="date" class="form-control" required /></label>
                        <label>Statement balance <input v-model="draft.bank_balance" type="number" step="0.01" class="form-control" required /></label>
                        <label>Reference <input v-model="draft.statement_reference" class="form-control" placeholder="e.g. May statement" /></label>
                        <button type="submit" class="btn btn-primary">Start reconciliation</button>
                    </form>
                    <table v-if="list.length" class="table">
                        <thead><tr><th>Period end</th><th>Bank (adj.)</th><th>Ledger</th><th>Clients</th><th>Result</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="r in list" :key="r.id">
                                <td>{{ r.period_end }}</td>
                                <td>{{ money(r.adjusted_bank_balance) }}</td>
                                <td>{{ money(r.ledger_balance) }}</td>
                                <td>{{ money(r.client_ledgers_total) }}</td>
                                <td>
                                    <span v-if="r.state === 'draft'" class="muted">draft</span>
                                    <span v-else-if="r.has_exceptions" class="warn">signed off with exceptions</span>
                                    <span v-else class="ok">balanced, signed off</span>
                                </td>
                                <td><button type="button" class="btn btn-sm btn-outline-primary" @click="open(r.id)">Open</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else class="elevated-card" data-testid="reconciliation">
                <div class="card-header">
                    <h2 class="card-title">Reconciliation at {{ current.reconciliation.period_end }}</h2>
                    <button type="button" class="btn btn-sm btn-outline-secondary back" @click="current = null; loadList()">Back</button>
                </div>
                <div class="card-body">
                    <div class="three-way">
                        <div class="figure"><span>Adjusted bank</span><b>{{ money(current.figures.bank) }}</b>
                            <small>statement {{ money(current.reconciliation.bank_balance) }} + in transit {{ money(current.figures.deposits_in_transit) }}
                                − outstanding {{ money(current.figures.outstanding_disbursements) }} ± adjustments {{ money(current.figures.adjustments) }}</small></div>
                        <div class="figure"><span>Trust ledger</span><b>{{ money(current.figures.ledger) }}</b></div>
                        <div class="figure"><span>Sum of client ledgers</span><b>{{ money(current.figures.clients) }}</b></div>
                        <div class="figure verdict" :class="current.figures.balanced ? 'ok' : 'warn'">
                            {{ current.figures.balanced ? 'The three balances agree' : `Difference: bank vs ledger ${money(current.figures.bank_vs_ledger)}, ledger vs clients ${money(current.figures.ledger_vs_clients)}` }}
                        </div>
                    </div>

                    <h3>Outstanding at {{ current.reconciliation.period_end }}</h3>
                    <p v-if="isDraft" class="hint">Tick the entries that appear on the bank statement. What stays unticked is in transit or outstanding.</p>
                    <table class="table">
                        <thead><tr><th v-if="isDraft"></th><th>Date</th><th>Client</th><th>Type</th><th>Amount</th><th>Reference</th></tr></thead>
                        <tbody>
                            <tr v-for="t in current.figures.uncleared" :key="t.id">
                                <td v-if="isDraft"><input type="checkbox" :aria-label="'Cleared ' + t.id" @change="tick(t.id, true)" /></td>
                                <td>{{ (t.created_at || '').slice(0, 10) }}</td><td>{{ t.client }}</td><td>{{ t.type }}</td>
                                <td>{{ money(t.amount) }}</td><td>{{ t.reference_number }}</td>
                            </tr>
                            <tr v-if="!current.figures.uncleared.length"><td colspan="6" class="muted">Nothing outstanding.</td></tr>
                        </tbody>
                    </table>
                    <p v-if="isDraft && current.cleared_here.length" class="hint">
                        {{ current.cleared_here.length }} entr{{ current.cleared_here.length === 1 ? 'y' : 'ies' }} ticked as cleared in this reconciliation.
                        <button type="button" class="link" @click="untickAll">Untick all</button>
                    </p>

                    <h3>Bank items not yet in the ledger</h3>
                    <ul class="items">
                        <li v-for="i in current.items" :key="i.id">{{ money(i.amount) }}: {{ i.description }}
                            <button v-if="isDraft" type="button" class="link" @click="removeItem(i.id)">remove</button></li>
                        <li v-if="!current.items.length" class="muted">None.</li>
                    </ul>
                    <form v-if="isDraft" class="inline-form" @submit.prevent="addItem">
                        <input v-model="item.amount" type="number" step="0.01" class="form-control" placeholder="Amount (− for charges)" aria-label="Amount" required />
                        <input v-model="item.description" class="form-control" placeholder="What it is (bank charges, interest…)" aria-label="Description" required />
                        <button type="submit" class="btn btn-outline-primary">Add</button>
                    </form>

                    <div class="actions">
                        <a :href="`/api/v1/trust/reconciliations/${current.reconciliation.id}/export`" class="btn btn-outline-primary">Download report (CSV)</a>
                        <template v-if="isDraft">
                            <button type="button" class="btn btn-primary" @click="signOff">Sign off</button>
                            <button type="button" class="btn btn-outline-danger" @click="discard">Discard draft</button>
                        </template>
                        <span v-else class="muted">Signed off {{ current.reconciliation.signed_off_at }}<template v-if="current.reconciliation.exception_note">: {{ current.reconciliation.exception_note }}</template></span>
                    </div>
                </div>
            </div>
        </template>

        <!-- ---- retainers ---------------------------------------------------------------------------------------- -->
        <div v-if="tab === 'ret'" class="elevated-card" data-testid="retainers">
            <div class="card-header"><h2 class="card-title">Retainers below their minimum</h2></div>
            <div class="card-body">
                <table v-if="due.length" class="table">
                    <thead><tr><th>Client</th><th>Balance</th><th>Minimum</th><th>To reach target</th><th>Last asked</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="d in due" :key="d.client_id">
                            <td>{{ d.client }}</td><td>{{ money(d.balance) }}</td><td>{{ money(d.minimum_balance) }}</td>
                            <td><b>{{ money(d.amount_needed) }}</b></td><td>{{ d.last_requested_at ? d.last_requested_at.slice(0, 10) : '—' }}</td>
                            <td><button v-if="canReconcile" type="button" class="btn btn-sm btn-primary" @click="requestTopUp(d)">Request by SMS</button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="hint">Every client with a retainer policy is at or above their minimum.</p>

                <template v-if="canReconcile">
                    <h3>Retainer policies</h3>
                    <table v-if="policies.length" class="table">
                        <thead><tr><th>Client</th><th>Minimum</th><th>Target</th><th>Balance</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="p in policies" :key="p.client_id">
                                <td>{{ p.client }}</td><td>{{ money(p.minimum_balance) }}</td><td>{{ money(p.target_balance) }}</td><td>{{ money(p.balance) }}</td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" @click="removePolicy(p.client_id)">Remove</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <form class="inline-form" @submit.prevent="savePolicy">
                        <div class="client-pick"><SearchSelect v-model="policy.client_id" fetch-url="/api/v1/clients" :paginated="true" placeholder="Client" /></div>
                        <input v-model="policy.minimum_balance" type="number" step="0.01" min="0" class="form-control" placeholder="Minimum" aria-label="Minimum balance" required />
                        <input v-model="policy.target_balance" type="number" step="0.01" min="0" class="form-control" placeholder="Top up to" aria-label="Target balance" required />
                        <button type="submit" class="btn btn-primary">Save policy</button>
                    </form>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
/** FUN-12: trust three-way reconciliation and retainer replenishment. */
import { computed, onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import SearchSelect from '@/components/ui/search-select.vue';

useMeta({ title: 'Trust Accounting' });
const { showMessage } = useToast();
const perms = JSON.parse(localStorage.getItem('permissions') || '[]');
const canReconcile = perms.includes('reconcile-trust-accounts');

const tab = ref(canReconcile ? 'rec' : 'ret');
const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fail = (e, m) => showMessage(e.response?.data?.message || m, 'error');

// reconciliations
const list = ref([]);
const current = ref(null);
const isDraft = computed(() => current.value?.reconciliation.state === 'draft');
const draft = ref({ period_end: '', bank_balance: '', statement_reference: '' });
const item = ref({ amount: '', description: '' });

const loadList = () => axios.get('/api/v1/trust/reconciliations', { params: { page: 1, per_page: 24 } }).then(({ data }) => { list.value = data.data.data; });
const open = (id) => axios.get(`/api/v1/trust/reconciliations/${id}`).then(({ data }) => { current.value = data.data; });
const start = () => axios.post('/api/v1/trust/reconciliations', draft.value)
    .then(({ data }) => { current.value = data.data; draft.value = { period_end: '', bank_balance: '', statement_reference: '' }; })
    .catch((e) => fail(e, 'Could not start the reconciliation.'));
const id = () => current.value.reconciliation.id;
const tick = (txId, cleared) => axios.put(`/api/v1/trust/reconciliations/${id()}/cleared`, { transaction_ids: [txId], cleared })
    .then(({ data }) => { current.value = data.data; }).catch((e) => fail(e, 'Could not update it.'));
const untickAll = () => axios.put(`/api/v1/trust/reconciliations/${id()}/cleared`, { transaction_ids: current.value.cleared_here, cleared: false })
    .then(({ data }) => { current.value = data.data; });
const addItem = () => axios.post(`/api/v1/trust/reconciliations/${id()}/items`, item.value)
    .then(({ data }) => { current.value = data.data; item.value = { amount: '', description: '' }; }).catch((e) => fail(e, 'Could not add it.'));
const removeItem = async (itemId) => {
    if (!(await confirmAction({ title: 'Remove this reconciling item?', text: 'The reconciliation figures will be recalculated.', confirmText: 'Remove item', danger: true }))) return;
    return axios.delete(`/api/v1/trust/reconciliations/${id()}/items/${itemId}`).then(({ data }) => { current.value = data.data; });
};
const signOff = async () => {
    let body = {};
    if (!current.value.figures.balanced) {
        const r = await window.Swal.fire({
            icon: 'warning', title: 'The balances do not agree',
            text: 'Signing off records the reconciliation with exceptions. Explain the difference for the auditor.',
            input: 'textarea', showCancelButton: true, confirmButtonText: 'Sign off with exceptions',
            inputValidator: (v) => (!v || v.trim().length < 10 ? 'Explain the difference.' : undefined),
        });
        if (!r.isConfirmed) return;
        body = { exception_note: r.value.trim() };
    }
    axios.post(`/api/v1/trust/reconciliations/${id()}/sign-off`, body)
        .then(({ data }) => { current.value = data.data; showMessage(data.message); })
        .catch((e) => fail(e, 'Could not sign it off.'));
};
const discard = async () => {
    if (!(await confirmAction({ title: 'Discard this draft reconciliation?', text: 'Everything ticked and entered so far is lost. Signed-off reconciliations are never affected.', confirmText: 'Discard draft', danger: true, irreversible: true }))) return;
    return axios.delete(`/api/v1/trust/reconciliations/${id()}`).then(() => { current.value = null; loadList(); });
};

// retainers
const due = ref([]);
const policies = ref([]);
const policy = ref({ client_id: null, minimum_balance: '', target_balance: '' });
const loadRetainers = () => {
    axios.get('/api/v1/trust/replenishment').then(({ data }) => { due.value = data.data; }).catch(() => {});
    if (canReconcile) axios.get('/api/v1/trust/retainers').then(({ data }) => { policies.value = data.data; });
};
const savePolicy = () => axios.put('/api/v1/trust/retainers', policy.value)
    .then(() => { policy.value = { client_id: null, minimum_balance: '', target_balance: '' }; loadRetainers(); })
    .catch((e) => fail(e, 'Could not save the policy.'));
const removePolicy = async (clientId) => {
    if (!(await confirmAction({ title: 'Stop monitoring this retainer?', text: 'You will no longer be alerted when the client\'s trust balance drops below its minimum.', confirmText: 'Stop monitoring', danger: true }))) return;
    return axios.delete(`/api/v1/trust/retainers/${clientId}`).then(loadRetainers);
};
const requestTopUp = async (d) => {
    if (!(await confirmAction({ title: 'Ask the client to top up?', text: 'A replenishment request will be sent to the client now.', confirmText: 'Send request' }))) return;
    return axios.post(`/api/v1/trust/replenishment/${d.client_id}/request`)
    .then(({ data }) => { showMessage(data.message); loadRetainers(); }).catch((e) => fail(e, 'Could not send the request.'));
};

onMounted(() => {
    if (canReconcile) loadList();
    loadRetainers();
});
</script>

<style lang="scss" scoped>
.hint, .muted { font-size: 13px; color: var(--text-secondary, #64748b); }
.ok { color: #15803d; }
.warn { color: #b45309; }
.tabs { display: flex; gap: 8px; margin-bottom: 12px;
    button { border: 1px solid var(--border-color, #e2e8f0); background: var(--card-bg, #fff); padding: 6px 14px; border-radius: 8px; cursor: pointer;
        &.active { background: var(--primary-600); color: #fff; border-color: transparent; } } }
.inline-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 12px 0;
    label { display: flex; flex-direction: column; font-size: 12px; gap: 4px; }
    .form-control { min-width: 140px; } .client-pick { min-width: 220px; } }
.card-header { display: flex; align-items: center; .back { margin-left: auto; } }
.three-way { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px;
    .figure { border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 12px; display: flex; flex-direction: column;
        span { font-size: 12px; color: var(--text-secondary, #64748b); } b { font-size: 20px; } small { font-size: 11px; color: var(--text-secondary, #64748b); } }
    .verdict { justify-content: center; font-weight: 600; } }
.items { padding-left: 18px; font-size: 14px; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 16px; }
.link { background: none; border: none; color: var(--primary-600); cursor: pointer; padding: 0 4px; }
h3 { font-size: 15px; margin-top: 18px; }
</style>
