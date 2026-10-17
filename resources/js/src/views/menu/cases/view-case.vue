<template>
    <div class="modern-view-page">
        <!-- UI-36: print-only case summary — hidden on screen, the only
             thing left visible (see @media print rules below) when the
             person hits "Print Case Summary". Uses its own uncapped
             fetch (summaryHearings/summaryExpenses), independent of
             whichever tab/page the interactive tables are currently on. -->
        <div class="print-only-summary">
            <div class="print-header">
                <h1>Case Summary</h1>
                <p class="print-generated">Generated {{ new Date().toLocaleDateString() }}</p>
            </div>

            <section class="print-section">
                <h2>Case Information</h2>
                <dl>
                    <div><dt>Case Number</dt><dd>{{ caseD.case_number || 'N/A' }}</dd></div>
                    <div><dt>Client</dt><dd>{{ caseD.client || 'N/A' }}</dd></div>
                    <div><dt>Assigned Attorney</dt><dd>{{ caseD.assigned || 'N/A' }}</dd></div>
                    <div><dt>Case Type</dt><dd>{{ caseD.type || 'N/A' }}</dd></div>
                    <div><dt>Start Date</dt><dd>{{ caseD.start_date || 'N/A' }}</dd></div>
                    <div><dt>Court</dt><dd>{{ caseD.court || 'N/A' }}</dd></div>
                    <div><dt>Status</dt><dd>{{ caseD.lifecycle_status || 'N/A' }}</dd></div>
                </dl>
            </section>

            <section class="print-section">
                <h2>Hearing History</h2>
                <table v-if="summaryHearings.length">
                    <thead>
                        <tr><th>Date</th><th>Court</th><th>Type</th><th>Outcome</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in summaryHearings" :key="h.id">
                            <td>{{ h.hearing_date }}</td>
                            <td>{{ h.court }}</td>
                            <td>{{ h.hearing_type }}</td>
                            <td>{{ h.outcome || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else>No hearings recorded.</p>
            </section>

            <section class="print-section">
                <h2>Expenses</h2>
                <table v-if="summaryExpenses.length">
                    <thead>
                        <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in summaryExpenses" :key="e.id">
                            <td>{{ e.expense_date }}</td>
                            <td>{{ e.category_name }}</td>
                            <td>{{ e.description }}</td>
                            <td>{{ e.amount }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else>No expenses recorded.</p>
            </section>
        </div>

        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><router-link :to="{ name: 'cases' }">Cases</router-link></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Case Details</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <!-- UI-10: tab nav — active tab lives in the URL hash (e.g.
             #documents) so a deep link lands on the right tab and the
             browser back button moves between tabs sensibly. -->
        <div class="case-tabs" role="tablist">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                role="tab"
                class="case-tab"
                :class="{ active: activeTab === tab.id }"
                :aria-selected="activeTab === tab.id"
                @click="setActiveTab(tab.id)"
            >
                {{ tab.label }}
            </button>
        </div>

        <OverviewTab
            v-if="activeTab === 'overview' && caseD.id"
            :case-data="caseD"
            :transitions="caseD.lifecycle_transitions"
            @print="printCaseSummary"
            @updated="onCaseUpdated"
        />
        <HearingsTab v-else-if="activeTab === 'hearings' && caseD.id" :case-id="caseD.id" @changed="fetchSummaryData" />
        <DocumentsTab v-else-if="activeTab === 'documents' && caseD.id" :case-id="caseD.id" @changed="fetchSummaryData" />
        <FinancialsTab v-else-if="activeTab === 'financials' && caseD.id" :case-id="caseD.id" :client-id="caseD.client_id" @changed="fetchSummaryData" />
        <PartiesTab v-else-if="activeTab === 'parties' && caseD.id" :case-id="caseD.id" />
        <EmailsTab v-else-if="activeTab === 'emails' && caseD.id" :case-id="caseD.id" />
        <PortalTab v-else-if="activeTab === 'portal' && caseD.id" :case-id="caseD.id" />
        <AccessTab v-else-if="activeTab === 'access' && caseD.id" :case-id="caseD.id" />
        <ActivityTab v-else-if="activeTab === 'activity' && caseD.id" :case-id="caseD.id" />
    </div>
</template>

<script setup>
/**
 * UI-10/UI-35: case detail shell — restructured from a single ~1000-line
 * file (case info + three v-client-tables + all their modals/logic all
 * inline) into this thin shell plus one component per tab (see ./tabs/).
 * The shell owns only what's genuinely cross-tab: the case record
 * itself, the print-only summary (which spans hearings+expenses+case
 * info together), and which tab is active.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios, { fetchAllPages } from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useRecentlyViewed } from '@/composables/use-recently-viewed';
import OverviewTab from './tabs/overview-tab.vue';
import HearingsTab from './tabs/hearings-tab.vue';
import DocumentsTab from './tabs/documents-tab.vue';
import FinancialsTab from './tabs/financials-tab.vue';
import ActivityTab from './tabs/activity-tab.vue';
import PartiesTab from './tabs/parties-tab.vue';
import PortalTab from './tabs/portal-tab.vue';
import EmailsTab from './tabs/emails-tab.vue';
import AccessTab from './tabs/access-tab.vue';

useMeta({ title: 'View Case' });

const { showMessage } = useToast();
const route = useRoute();
const router = useRouter();

const tabs = [
    { id: 'overview', label: 'Overview' },
    { id: 'hearings', label: 'Hearings' },
    { id: 'documents', label: 'Documents' },
    { id: 'emails', label: 'Emails' },   // FUN-15
    { id: 'financials', label: 'Financials' },
    { id: 'parties', label: 'Parties' },
    { id: 'activity', label: 'Activity' },
];
// FUN-10: the client portal tab, for people who manage it
if (JSON.parse(localStorage.getItem('permissions') || '[]').includes('manage-client-portal')) {
    tabs.splice(6, 0, { id: 'portal', label: 'Client portal' });
}

// SEC-10: who can see this matter, for people who manage matter access
if (JSON.parse(localStorage.getItem('permissions') || '[]').includes('manage-matter-access')) {
    tabs.push({ id: 'access', label: 'Access' });
}

const validTabIds = tabs.map((t) => t.id);
const activeTab = computed(() => {
    const fromHash = route.hash?.replace('#', '');
    return validTabIds.includes(fromHash) ? fromHash : 'overview';
});

const setActiveTab = (tabId) => {
    router.replace({ hash: `#${tabId}` });
};

const caseD = ref({});
const summaryHearings = ref([]);
const summaryExpenses = ref([]);

const onCaseUpdated = (updatedCase) => {
    caseD.value = updatedCase;
};

const fetchSummaryData = () => {
    const caseId = localStorage.getItem('caseId');
    if (!caseId) return;

    // Bug fix (found via a real E2E run): no `page` param is sent here
    // (deliberately, to get every hearing/expense for the print summary,
    // not one page) — paginatedOrFull() only nests results under another
    // `.data` when `page` is present; without it the response is already
    // the flat array. This previously always read `response.data.data?.data`,
    // silently producing an empty array regardless of how much data
    // actually existed, so the printed case summary always showed "no
    // hearings/expenses recorded" even when there were plenty.
    fetchAllPages('/api/v1/hearings', { case_id: caseId })
        .then(({ rows }) => {
            summaryHearings.value = rows;
        })
        .catch(() => {});

    fetchAllPages('/api/v1/expenses', { case_id: caseId })
        .then(({ rows }) => {
            summaryExpenses.value = rows;
        })
        .catch(() => {});
};

const printCaseSummary = () => {
    window.print();
};

const { addRecentItem } = useRecentlyViewed();

const fetchCase = () => {
    const caseId = localStorage.getItem('caseId');
    axios.get(`/api/v1/cases/${caseId}`)
        .then((response) => {
            caseD.value = response.data.data;
            addRecentItem({ type: 'case', id: caseId, title: caseD.value.case_number, subtitle: caseD.value.client });
        })
        .catch((error) => {
            console.error('Error fetching case:', error);
            showMessage('Could not load this case. Please try refreshing the page.', 'error');
        });
};

onMounted(() => {
    // FUN-16: links from calendar events carry the matter (?case=ID); the page otherwise remembers the last one opened
    if (/^\d+$/.test(String(route.query.case || ''))) {
        localStorage.setItem('caseId', String(route.query.case));
    }
    fetchCase();
    fetchSummaryData();
});
</script>

<style lang="scss" scoped>
.modern-view-page {
    padding: 24px;
    background: #f8f9fa;
    min-height: 100vh;
}

.case-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
}

.case-tab {
    padding: 10px 18px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    cursor: pointer;

    &:hover {
        color: var(--primary-600);
    }

    &.active {
        color: var(--primary-600);
        border-bottom-color: var(--primary-600);
    }
}

/* UI-36: case-summary print view */
.print-only-summary {
    display: none;
}

@media print {
    .modern-view-page > *:not(.print-only-summary) {
        display: none !important;
    }

    .print-only-summary {
        display: block;
        color: #000;
        background: #fff;
    }

    .print-header {
        margin-bottom: 20px;
        border-bottom: 2px solid #000;
        padding-bottom: 10px;

        h1 {
            font-size: 20px;
            margin: 0 0 4px;
        }

        .print-generated {
            font-size: 11px;
            color: #444;
            margin: 0;
        }
    }

    .print-section {
        margin-bottom: 24px;
        break-inside: avoid;
        page-break-inside: avoid;

        h2 {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #000;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }
    }

    .print-section dl {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 24px;
        margin: 0;

        div {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px dotted #999;
            padding-bottom: 2px;
        }

        dt {
            font-weight: 600;
        }

        dd {
            margin: 0;
        }
    }

    .print-section table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;

        th, td {
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: left;
        }

        thead {
            display: table-header-group;
        }

        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }
    }
}
</style>
