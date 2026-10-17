<template>
    <div class="modern-time-entries-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Dashboard</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Time Entries</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-actions">
            <div class="header-content">
                <h2 class="page-title">Time Entries</h2>
                <p class="page-subtitle">Billable and non-billable time logged across your cases</p>
            </div>
        </div>

        <div class="time-entries-table-card">
            <div class="table-wrapper">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/time-entries"
                    :columns="columns"
                    :sortable-columns="sortable"
                    :searchable-columns="searchable"
                    :filter-params="filters"
                    empty-title="No time entries"
                    empty-description="Nothing matches this view yet."
                >
                    <template #cell-case="{ row }">
                        <a href="javascript:;" class="cross-link" @click="goToCase(entry(row).case_id)">{{ entry(row).case }}</a>
                    </template>
                    <template #cell-billed="{ row }">
                        <span class="status-badge" :class="entry(row).billed ? 'billed' : 'unbilled'">
                            {{ status(entry(row)) }}
                        </span>
                    </template>
                </DataTable>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * UI-28: standalone target for the dashboard's "Unbilled Work" KPI —
 * there was previously no module-list page for time entries at all
 * (they only existed nested inside a case's own panel), so that KPI
 * had nowhere to link to. Read-only: creating/editing a time entry
 * still happens from the case it belongs to (see
 * cases/time-entries-panel.vue), this page is for reviewing across
 * cases, not for editing any one entry.
 */
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import type { TimeEntry } from '@/api';
import { useMeta } from '@/composables/use-meta';
import DataTable from '@/components/ui/data-table.vue';

// ENG-15: the columns are checked against the API's TimeEntry shape (generated from the server's TimeEntryResource),
// so a field renamed on the server fails `npm run type-check` here instead of showing an empty column.
type Field = keyof TimeEntry;
const columns: { key: Field; label: string }[] = [
    { key: 'date', label: 'Date' },
    { key: 'case', label: 'Case' },
    { key: 'user', label: 'Logged By' },
    { key: 'description', label: 'Description' },
    { key: 'hours', label: 'Hours' },
    { key: 'amount', label: 'Amount' },
    { key: 'billed', label: 'Status' },
];
const sortable: Field[] = ['date', 'hours', 'amount'];
const searchable: Field[] = ['description'];
const filters: Field[] = ['billable', 'billed'];

/** The table's slots pass rows untyped; each row is a TimeEntry. */
const entry = (row: unknown) => row as TimeEntry;
const status = (e: TimeEntry) => (e.billed ? 'Billed' : e.billable ? 'Unbilled' : 'Non-billable');

useMeta({ title: 'Time Entries' });

const router = useRouter();
const tableRef = ref<InstanceType<typeof DataTable> | null>(null);

// UI-9: the case-details route has no id URL param — it reads
// localStorage (see cases/list-cases.vue's view_row) — so a cross-link
// to a case follows that same pattern rather than a plain router-link.
const goToCase = (caseId: number | null) => {
    if (!caseId) return;
    localStorage.setItem('caseId', String(caseId));
    router.push('/view-case');
};
</script>

<style scoped>
.modern-time-entries-page {
    padding: 24px;
    background: #f9fafb;
    min-height: 100vh;
}

.page-header-actions {
    margin-bottom: 20px;
}

.page-title {
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 4px;
}

.page-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin: 0;
}

.time-entries-table-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    padding: 8px;
}

.cross-link {
    color: inherit;
    text-decoration: none;
    cursor: pointer;

    &:hover {
        color: var(--primary-600);
        text-decoration: underline;
    }
}

.status-badge {
    font-size: 12px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 999px;

    &.billed {
        background: rgba(16, 185, 129, 0.12);
        color: var(--primary-600);
    }

    &.unbilled {
        background: rgba(245, 158, 11, 0.12);
        color: #b45309;
    }
}
</style>
