<template>
    <div class="firm-dashboard">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Dashboard</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Overview</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <!-- PLAT-13: a new firm's first steps -->
        <OnboardingChecklist />

        <!-- Loading state (UI-3) -->
        <div v-if="loading" class="dashboard-loading">
            <div class="skeleton-row" v-for="n in 4" :key="n"></div>
        </div>

        <!-- Error state (UI-3) -->
        <div v-else-if="loadError" class="dashboard-error">
            <p>Couldn't load your dashboard.</p>
            <button type="button" class="btn btn-primary" @click="fetchDashboard">Retry</button>
        </div>

        <template v-else>
            <!-- ENG-23: the dashboard may read from a replica that is catching up -->
            <p v-if="behind" class="lag-note" data-testid="dashboard-lag">Figures are {{ lagText(behind) }} behind.</p>

            <!-- Top-line stats -->
            <div class="stat-cards">
                <router-link :to="{ name: 'clients' }" class="stat-card cross-link">
                    <div class="stat-value">{{ data.stats.total_clients }}</div>
                    <div class="stat-label">Clients</div>
                </router-link>
                <router-link :to="{ name: 'cases', query: { lifecycle_status: 'open' } }" class="stat-card cross-link">
                    <div class="stat-value">{{ data.stats.open_cases }}</div>
                    <div class="stat-label">Open Cases</div>
                </router-link>
                <router-link
                    :to="{ name: 'time-entries', query: { billable: '1', billed: '0' } }"
                    class="stat-card cross-link"
                >
                    <div class="stat-value">{{ formatMoney(data.unbilled_total) }}</div>
                    <div class="stat-label">Unbilled Work</div>
                </router-link>
                <router-link
                    :to="{ name: 'invoices', query: { workflow_status: 'submitted', payment_status: 'unpaid,partially_paid' } }"
                    class="stat-card cross-link"
                    :class="{ warn: data.outstanding_total > 0 }"
                >
                    <div class="stat-value">{{ formatMoney(data.outstanding_total) }}</div>
                    <div class="stat-label">Outstanding Invoices</div>
                </router-link>
            </div>

            <!-- Firm-wide export (FUN-4, admin-only) -->
            <div v-if="isAdmin" class="admin-export-bar">
                <div class="admin-export-text">
                    <strong>Firm-wide data export</strong>
                    <span>Download every module's data as a single zip of CSV files — clients, cases, hearings, invoices, payments, expenses, time entries, and tasks.</span>
                </div>
                <button type="button" class="btn-firm-export" @click="exportFirmData" :disabled="exportingFirm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    {{ exportingFirm ? 'Preparing export...' : 'Export All Firm Data' }}
                </button>
            </div>

            <div class="dashboard-grid">
                <!-- Upcoming hearings -->
                <div class="dashboard-panel">
                    <div class="panel-header">
                        <h3>{{ isAdmin ? 'Upcoming Hearings' : 'My Hearings This Week' }} <span class="muted">(next 7 days)</span></h3>
                    </div>
                    <div v-if="!data.upcoming_hearings.length" class="empty-state">
                        No hearings scheduled in the next 7 days.
                    </div>
                    <ul v-else class="panel-list">
                        <li v-for="h in data.upcoming_hearings" :key="h.id">
                            <a href="javascript:;" class="list-main cross-link" @click="goToCase(h.case_id)">
                                <span class="list-title">{{ h.case }}</span>
                                <span class="list-sub">{{ h.court }}</span>
                            </a>
                            <span class="list-badge" :class="h.days_away <= 1 ? 'urgent' : 'default'">{{ h.date }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Tasks due -->
                <div class="dashboard-panel">
                    <div class="panel-header">
                        <h3>{{ isAdmin ? 'Tasks Due' : 'My Tasks' }}</h3>
                    </div>
                    <div v-if="!data.tasks_due.length" class="empty-state">
                        No open tasks. Nice work.
                    </div>
                    <ul v-else class="panel-list">
                        <li v-for="t in data.tasks_due" :key="t.id">
                            <router-link :to="{ name: 'tasks', query: { search: t.title } }" class="list-main cross-link">
                                <span class="list-title">{{ t.title }}</span>
                                <span class="list-sub">{{ t.assignee }}</span>
                            </router-link>
                            <span class="list-badge" :class="t.overdue ? 'urgent' : 'default'">{{ t.due_date }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Unbilled work by case -->
                <div class="dashboard-panel">
                    <div class="panel-header">
                        <h3>{{ isAdmin ? 'Unbilled Work by Case' : 'My Unbilled Time' }}</h3>
                    </div>
                    <div v-if="!data.unbilled_by_case.length" class="empty-state">
                        Everything billable has been billed.
                    </div>
                    <ul v-else class="panel-list">
                        <li v-for="u in data.unbilled_by_case" :key="u.case_id">
                            <a href="javascript:;" class="list-main cross-link" @click="goToCase(u.case_id)">
                                <span class="list-title">{{ u.case }}</span>
                                <span class="list-sub">{{ u.hours }} hrs</span>
                            </a>
                            <span class="list-badge default">{{ formatMoney(u.amount) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Outstanding invoices -->
                <div class="dashboard-panel">
                    <div class="panel-header">
                        <h3>Outstanding Invoices</h3>
                    </div>
                    <div v-if="!data.outstanding_invoices.length" class="empty-state">
                        No outstanding balances.
                    </div>
                    <ul v-else class="panel-list">
                        <li v-for="inv in data.outstanding_invoices" :key="inv.id">
                            <router-link :to="{ name: 'client-details', params: { id: inv.client_id } }" class="list-main cross-link">
                                <span class="list-title">{{ inv.invoice_number }} — {{ inv.client }}</span>
                                <span class="list-sub">Due {{ inv.due_date }}</span>
                            </router-link>
                            <span class="list-badge" :class="inv.overdue ? 'urgent' : 'default'">{{ formatMoney(inv.outstanding) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Recent case activity -->
                <div class="dashboard-panel wide">
                    <div class="panel-header">
                        <h3>Recent Case Activity</h3>
                    </div>
                    <div v-if="!data.recent_cases.length" class="empty-state">
                        No case activity yet.
                    </div>
                    <ul v-else class="panel-list">
                        <li v-for="c in data.recent_cases" :key="c.id">
                            <a href="javascript:;" class="list-main cross-link" @click="goToCase(c.id)">
                                <span class="list-title">{{ c.case_number }} — {{ c.client }}</span>
                                <span class="list-sub">{{ c.status }}</span>
                            </a>
                            <span class="list-badge default">{{ c.updated_at }}</span>
                        </li>
                    </ul>
                </div>

                <!-- UI-29/UI-30: server-aggregated trend charts -->
                <div class="dashboard-panel wide">
                    <div class="panel-header">
                        <h3>Cases Opened vs Closed <span class="muted">(last 6 months)</span></h3>
                    </div>
                    <SimpleBarChart
                        :points="data.cases_trend.map(t => ({ label: t.month, a: t.opened, b: t.closed }))"
                        series-a-label="Opened"
                        series-b-label="Closed"
                        aria-label="Cases opened versus closed by month"
                    />
                </div>

                <div class="dashboard-panel wide">
                    <div class="panel-header">
                        <h3>Billed vs Collected <span class="muted">(last 6 months)</span></h3>
                    </div>
                    <SimpleBarChart
                        :points="data.billing_trend.map(t => ({ label: t.month, a: t.billed, b: t.collected }))"
                        series-a-label="Billed"
                        series-b-label="Collected"
                        aria-label="Amount billed versus collected by month"
                    />
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import SimpleBarChart from '@/components/ui/simple-bar-chart.vue';
import OnboardingChecklist from '@/components/onboarding-checklist.vue';
import axios, { dataLag, lagText } from '../api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useFileDownload } from '@/composables/use-file-download';

useMeta({ title: 'Dashboard' });

const router = useRouter();

// UI-9: the case-details route has no id URL param — it reads
// localStorage (see cases/list-cases.vue's view_row) — so a cross-link
// to a case follows that same pattern rather than a plain router-link.
const goToCase = (caseId) => {
    if (!caseId) return;
    localStorage.setItem('caseId', caseId);
    router.push('/view-case');
};

const { showMessage } = useToast();
const { downloadFile } = useFileDownload();

// Client-side check only, for showing/hiding the export button — the
// real enforcement is server-side via the EXPORT_FIRM_DATA permission
// (see ExportController::requestFirmExport). Mirrors the same pattern used
// for other role-based UI throughout the app: a UX convenience, not a
// security boundary.
const isAdmin = ref(false);
try {
    const storedUser = JSON.parse(localStorage.getItem('user') || 'null');
    isAdmin.value = Array.isArray(storedUser?.roles) && storedUser.roles.includes('admin');
} catch (e) {
    isAdmin.value = false;
}

const exportingFirm = ref(false);

// ENG-21: the export is built in the background. Ask for it, then poll its status; when it is ready, download it
// through the short-lived signed link. The requester is also emailed, so leaving the page loses nothing.
const EXPORT_POLL_MS = 3000;
const EXPORT_POLL_LIMIT = 100; // ~5 minutes, then rely on the email
const exportFirmData = async () => {
    if (!(await confirmAction({ title: 'Export all firm data?', text: 'This prepares a full copy of your firm\'s records. It can take a few minutes, and a download link is emailed to you when it is ready.', confirmText: 'Start export' }))) return;
    exportingFirm.value = true;
    axios.post('/api/v1/export/firm-data')
        .then((response) => pollFirmExport(response.data.data.id, 0))
        .catch(() => {
            showMessage('Could not start the export. Please try again.', 'error');
            exportingFirm.value = false;
        });
};
const pollFirmExport = (id, attempt) => {
    axios.get(`/api/v1/export/firm-data/${id}`)
        .then((response) => {
            const exp = response.data.data;
            if (exp.status === 'ready' && exp.download_url) {
                return downloadFile(exp.download_url, `firm-export-${new Date().toISOString().slice(0, 10)}.zip`)
                    .finally(() => { exportingFirm.value = false; });
            }
            if (exp.status === 'failed' || exp.status === 'expired') {
                showMessage(exp.error || 'The export could not be built.', 'error');
                exportingFirm.value = false;
                return;
            }
            if (attempt >= EXPORT_POLL_LIMIT) {
                showMessage('The export is still being prepared. We will email you when it is ready.');
                exportingFirm.value = false;
                return;
            }
            setTimeout(() => pollFirmExport(id, attempt + 1), EXPORT_POLL_MS);
        })
        .catch(() => {
            showMessage('Lost track of the export. We will email you when it is ready.', 'error');
            exportingFirm.value = false;
        });
};

const loading = ref(true);
const loadError = ref(false);
const behind = ref(null);
const data = ref({
    stats: { total_clients: 0, total_cases: 0, open_cases: 0 },
    upcoming_hearings: [],
    tasks_due: [],
    unbilled_total: 0,
    unbilled_by_case: [],
    outstanding_invoices: [],
    outstanding_total: 0,
    recent_cases: [],
    cases_trend: [],
    billing_trend: [],
});

const formatMoney = (value) => {
    const n = Number(value) || 0;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const fetchDashboard = () => {
    loading.value = true;
    loadError.value = false;

    axios.get('/api/v1/dashboard')
        .then((response) => {
            data.value = response.data.data;
            behind.value = dataLag(response);
        })
        .catch((error) => {
            loadError.value = true;
            showMessage('Could not load dashboard data.', 'error');
            console.error(error);
        })
        .finally(() => {
            loading.value = false;
        });
};

onMounted(() => {
    fetchDashboard();
});
</script>

<style lang="scss" scoped>
.firm-dashboard {
    padding: 20px;
}

.lag-note {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    margin-bottom: 12px;
}

// Loading skeleton (UI-3)
.dashboard-loading {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.skeleton-row {
    height: 100px;
    border-radius: 12px;
    background: linear-gradient(90deg, #eee 25%, #f5f5f5 37%, #eee 63%);
    background-size: 400% 100%;
    animation: skeleton-loading 1.4s ease infinite;
}

@keyframes skeleton-loading {
    0% { background-position: 100% 50%; }
    100% { background-position: 0 50%; }
}

.dashboard-error {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-tertiary);
}

.stat-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.admin-export-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: rgba(2, 132, 199, 0.06);
    border: 1px solid rgba(2, 132, 199, 0.15);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
}

.admin-export-text {
    display: flex;
    flex-direction: column;
    gap: 2px;

    strong {
        font-size: 14px;
    }

    span {
        font-size: 12px;
        color: var(--text-muted);
    }
}

.btn-firm-export {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--primary-600);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;

    &:hover:not(:disabled) {
        background: var(--primary-800);
    }

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}

.stat-card {
    background: var(--background, #fff);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
    padding: 20px;
    display: block;
    text-decoration: none;
    color: inherit;

    &.warn .stat-value {
        color: #e7515a;
    }
}

a.stat-card.cross-link {
    transition: border-color var(--transition-fast, 0.15s ease), transform var(--transition-fast, 0.15s ease);

    &:hover {
        border-color: var(--primary-600);
        transform: translateY(-1px);
    }
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
}

.stat-label {
    font-size: 13px;
    color: var(--text-tertiary);
    margin-top: 4px;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 16px;
}

.dashboard-panel {
    background: var(--background, #fff);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
    padding: 16px 20px;

    &.wide {
        grid-column: 1 / -1;
    }
}

.panel-header h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 12px;

    .muted {
        font-weight: 400;
        color: var(--text-tertiary);
        font-size: 12px;
    }
}

.empty-state {
    padding: 20px 0;
    text-align: center;
    color: var(--text-tertiary);
    font-size: 13px;
}

.panel-list {
    list-style: none;
    margin: 0;
    padding: 0;

    li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid rgba(0, 0, 0, 0.06);

        &:last-child {
            border-bottom: none;
        }
    }
}

.list-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    text-decoration: none;
    color: inherit;
}

a.list-main.cross-link {
    cursor: pointer;

    &:hover .list-title {
        color: var(--primary-600);
        text-decoration: underline;
    }
}

.list-title {
    font-size: 13px;
    font-weight: 500;
}

.list-sub {
    font-size: 12px;
    color: var(--text-tertiary);
}

.list-badge {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    white-space: nowrap;

    &.default {
        background: rgba(2, 132, 199, 0.1);
        color: var(--primary-600);
    }

    &.urgent {
        background: rgba(231, 81, 90, 0.1);
        color: #b91c1c;
    }
}
</style>
