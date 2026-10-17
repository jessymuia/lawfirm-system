<template>
    <div class="reports-page">
        <PageHeader title="Partner reports" subtitle="Revenue, utilisation and profitability across the firm." icon="M4 20V10M10 20V4M16 20v-7M22 20H2" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item active" aria-current="page"><span>Partner Reports</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <form class="filters" @submit.prevent="load">
            <label>From <input v-model="q.from" type="date" class="form-control" required /></label>
            <label>To <input v-model="q.to" type="date" class="form-control" required /></label>
            <label v-if="offices.length">Office
                <select v-model="q.office_id" class="form-control"><option value="">All</option><option v-for="o in offices" :key="o.id" :value="o.id">{{ o.name }}</option></select>
            </label>
            <label v-if="practiceGroups.length">Practice group
                <select v-model="q.practice_group_id" class="form-control"><option value="">All</option><option v-for="g in practiceGroups" :key="g.id" :value="g.id">{{ g.name }}</option></select>
            </label>
            <div class="quick">
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset('month')">This month</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset('last')">Last month</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset('ytd')">Year to date</button>
            </div>
            <button type="submit" class="btn btn-primary" :disabled="loading">{{ loading ? 'Loading…' : 'Run' }}</button>
        </form>

        <p v-if="summary && behind" class="lag-note" data-testid="report-lag">Figures are {{ lagText(behind) }} behind: reports read from a copy of the database that is catching up.</p>

        <div v-if="summary" class="tiles" data-testid="report-summary">
            <div class="tile"><span>Billable hours</span><b>{{ num(summary.billable_hours) }}</b></div>
            <div class="tile"><span>Utilisation</span><b>{{ pct(summary.utilisation) }}</b></div>
            <div class="tile"><span>Billing realisation</span><b>{{ pct(summary.realisation.billing_realisation) }}</b></div>
            <div class="tile"><span>Collection realisation</span><b>{{ pct(summary.realisation.collection_realisation) }}</b></div>
            <div class="tile"><span>Work in progress</span><b>{{ money(summary.wip.total) }}</b></div>
            <div class="tile"><span>Receivables</span><b>{{ money(summary.receivables.total) }}</b></div>
        </div>

        <div v-if="summary" class="grid">
            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">Aged receivables</h2><a class="csv" :href="csv('receivables')">CSV</a></div>
                <div class="card-body">
                    <div class="bars"><div v-for="(v, k) in summary.receivables.buckets" :key="k" class="bar-row"><span>{{ k === 'current' ? 'Not yet due' : k + ' days late' }}</span><b>{{ money(v) }}</b></div></div>
                </div>
            </div>
            <div class="elevated-card">
                <div class="card-header"><h2 class="card-title">WIP by age</h2><a class="csv" :href="csv('wip')">CSV</a></div>
                <div class="card-body">
                    <div class="bars"><div v-for="(v, k) in summary.wip.buckets" :key="k" class="bar-row"><span>{{ k }} days</span><b>{{ money(v) }}</b></div></div>
                </div>
            </div>
        </div>

        <div v-if="utilisation.length" class="elevated-card">
            <div class="card-header"><h2 class="card-title">Utilisation by lawyer</h2><a class="csv" :href="csv('utilisation')">CSV</a></div>
            <div class="card-body">
                <table class="table">
                    <thead><tr><th>Lawyer</th><th>Hours</th><th>Billable</th><th>Available</th><th>Utilisation</th><th>Billable value</th></tr></thead>
                    <tbody>
                        <tr v-for="u in utilisation" :key="u.user_id">
                            <td>{{ u.lawyer }}</td><td>{{ num(u.hours) }}</td><td>{{ num(u.billable_hours) }}</td><td>{{ num(u.available_hours) }}</td>
                            <td>{{ pct(u.utilisation) }}</td><td>{{ money(u.billable_value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="summary" class="elevated-card">
            <div class="card-header">
                <h2 class="card-title">Matter profitability</h2>
                <select v-model="groupBy" class="form-control group-by" aria-label="Group by" @change="loadProfitability">
                    <option value="">By matter</option>
                    <option value="lawyer">By lawyer</option>
                    <option v-if="offices.length" value="office">By office</option>
                    <option v-if="practiceGroups.length" value="practice_group">By practice group</option>
                </select>
                <a class="csv" :href="csv('profitability', groupBy ? { group_by: groupBy } : {})">CSV</a>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead><tr><th>{{ groupBy ? 'Group' : 'Matter' }}</th><th v-if="!groupBy">Client</th><th v-if="groupBy">Matters</th><th>Hours</th><th>Invoiced</th><th>Collected</th><th>Expenses</th><th>WIP</th><th>Contribution</th></tr></thead>
                    <tbody>
                        <tr v-for="(p, i) in profitability" :key="i">
                            <td>{{ groupBy ? p.group : p.case_number }}</td><td v-if="!groupBy">{{ p.client }}</td><td v-if="groupBy">{{ p.matters }}</td>
                            <td>{{ num(p.hours) }}</td><td>{{ money(p.invoiced) }}</td><td>{{ money(p.collected) }}</td><td>{{ money(p.expenses) }}</td>
                            <td>{{ money(p.wip) }}</td><td :class="p.contribution < 0 ? 'neg' : ''"><b>{{ money(p.contribution) }}</b></td>
                        </tr>
                        <tr v-if="!profitability.length"><td colspan="9" class="muted">No activity in this period.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import PageHeader from '@/components/ui/page-header.vue';
/** FUN-17: partner reports. Definitions: docs/PARTNER_REPORTS.md. */
import { onMounted, ref } from 'vue';
import axios, { dataLag, lagText } from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useOrganisation } from '@/composables/use-organisation';

useMeta({ title: 'Partner Reports' });
const { showMessage } = useToast();
const { offices, practiceGroups } = useOrganisation();

const iso = (d) => d.toISOString().slice(0, 10);
const q = ref({ from: '', to: '', office_id: '', practice_group_id: '' });
const preset = (which) => {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    const ranges = {
        month: [new Date(Date.UTC(y, m, 1)), now],
        last: [new Date(Date.UTC(y, m - 1, 1)), new Date(Date.UTC(y, m, 0))],
        ytd: [new Date(Date.UTC(y, 0, 1)), now],
    };
    [q.value.from, q.value.to] = ranges[which].map(iso);
};
preset('month');

const loading = ref(false);
const summary = ref(null);
const utilisation = ref([]);
const profitability = ref([]);
const groupBy = ref('');
const behind = ref(null);   // ENG-23: seconds the figures are behind, when worth saying

const params = (extra = {}) => Object.fromEntries(Object.entries({ ...q.value, ...extra }).filter(([, v]) => v !== '' && v !== null));
const csv = (report, extra = {}) => `/api/v1/reports/${report}?${new URLSearchParams(params({ ...extra, format: 'csv' }))}`;

const loadProfitability = () => axios.get('/api/v1/reports/profitability', { params: params(groupBy.value ? { group_by: groupBy.value } : {}) })
    .then(({ data }) => { profitability.value = data.data; });

const load = async () => {
    loading.value = true;
    try {
        const [s, u] = await Promise.all([
            axios.get('/api/v1/reports/summary', { params: params() }),
            axios.get('/api/v1/reports/utilisation', { params: params() }),
        ]);
        summary.value = s.data.data;
        utilisation.value = u.data.data;
        behind.value = dataLag(s);
        await loadProfitability();
    } catch (e) {
        showMessage(e.response?.data?.message || 'The report could not be run.', 'error');
    } finally {
        loading.value = false;
    }
};

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v) => Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);

onMounted(load);
</script>

<style lang="scss" scoped>
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 16px;
    label { display: flex; flex-direction: column; font-size: 12px; gap: 4px; } .quick { display: flex; gap: 6px; } }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px;
    .tile { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 10px; padding: 12px;
        span { display: block; font-size: 12px; color: var(--text-secondary, #64748b); } b { font-size: 20px; } } }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.bar-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border-color, #f1f5f9); font-size: 14px; }
.card-header { display: flex; align-items: center; gap: 10px; .csv { margin-left: auto; font-size: 13px; } .group-by { max-width: 200px; } }
.neg { color: #b42318; }
.lag-note { font-size: 13px; color: var(--text-secondary, #64748b); margin: -4px 0 12px; }
.muted { color: var(--text-secondary, #64748b); }
</style>
