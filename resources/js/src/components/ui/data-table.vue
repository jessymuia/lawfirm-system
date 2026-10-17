<template>
    <div class="app-data-table">
        <!-- Search (server-driven, UI-5/journey 8) -->
        <div class="table-toolbar">
            <input
                v-if="searchableColumns.length"
                type="text"
                class="table-search-input"
                data-testid="table-search-input"
                placeholder="Search..."
                v-model="searchTerm"
                @input="onSearchInput"
            />

            <div class="toolbar-spacer"></div>

            <!-- UI-21: saved filter presets — v1 is localStorage-only,
                 keyed by tableId, storing whatever's already in the URL
                 query string (page/sort/search/filterParams) under a
                 name the person chooses. -->
            <div v-if="tableId" class="preset-controls">
                <select
                    v-if="presets.length"
                    class="preset-select"
                    data-testid="preset-select"
                    @change="onPresetSelected($event.target.value); $event.target.value = ''"
                >
                    <option value="">Load view...</option>
                    <option v-for="preset in presets" :key="preset.name" :value="preset.name">{{ preset.name }}</option>
                </select>
                <button type="button" class="btn-toolbar" data-testid="save-preset-btn" @click="promptSavePreset">Save current view…</button>
            </div>

            <!-- UI-19: column visibility toggle — persisted alongside
                 presets under the same tableId. -->
            <div v-if="tableId" class="column-toggle-wrapper">
                <button type="button" class="btn-toolbar" @click="showColumnMenu = !showColumnMenu" data-testid="column-toggle-btn">
                    Columns
                </button>
                <div v-if="showColumnMenu" class="column-menu" role="menu">
                    <label v-for="col in columns" :key="col.key" class="column-menu-item">
                        <input type="checkbox" :checked="isColumnVisible(col.key)" @change="toggleColumn(col.key)" />
                        {{ col.label }}
                    </label>
                </div>
            </div>
        </div>

        <!-- UI-20: bulk action bar — appears once at least one row is
             selected. The actual bulk operations (delete/export/etc.)
             are supplied by the caller via this slot, since what "bulk
             delete" or "bulk export" means is module-specific; this
             component only owns the selection state and UI chrome. -->
        <div v-if="selectable && selectedIds.size > 0" class="bulk-action-bar" data-testid="bulk-action-bar">
            <span class="bulk-count">{{ selectedIds.size }} selected</span>
            <slot name="bulk-actions" :selected-ids="Array.from(selectedIds)" :clear-selection="clearSelection"></slot>
            <button type="button" class="btn-toolbar" @click="clearSelection">Clear</button>
        </div>

        <!-- Loading (UI-3) -->
        <SkeletonLoader v-if="loading && rows.length === 0" :rows="4" />

        <!-- Error (UI-3) -->
        <div v-else-if="error" class="table-error">
            <p>Couldn't load this data.</p>
            <button type="button" class="btn btn-primary" @click="fetchPage(currentPage)">Retry</button>
        </div>

        <!-- Empty (UI-3) -->
        <EmptyState
            v-else-if="rows.length === 0"
            :title="emptyTitle"
            :description="emptyDescription"
            :action-label="emptyActionLabel"
            @action="$emit('empty-action')"
        />

        <template v-else>
            <div class="table-wrapper">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th v-if="selectable" class="select-col">
                                <input
                                    type="checkbox"
                                    aria-label="Select all rows on this page"
                                    :checked="allOnPageSelected"
                                    @change="toggleSelectAllOnPage"
                                />
                            </th>
                            <!-- UI-15: the header keeps its columnheader role, so it can carry aria-sort; sorting is a real button inside it -->
                            <th
                                v-for="col in visibleColumns"
                                :key="col.key"
                                :class="{ sortable: sortableColumns.includes(col.key) }"
                                :data-testid="`column-header-${col.key}`"
                                :aria-sort="sortColumn === col.key ? (sortDirection === 'asc' ? 'ascending' : 'descending') : (sortableColumns.includes(col.key) ? 'none' : undefined)"
                            >
                                <button v-if="sortableColumns.includes(col.key)" type="button" class="sort-button" @click="toggleSort(col.key)">
                                    {{ col.label }}
                                    <span class="sort-indicator" aria-hidden="true">
                                        {{ sortColumn === col.key ? (sortDirection === 'asc' ? '▲' : '▼') : '⇅' }}
                                    </span>
                                </button>
                                <template v-else>{{ col.label }}</template>
                            </th>
                            <th v-if="hasActions" class="actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row[rowKey]">
                            <td v-if="selectable" class="select-col">
                                <input
                                    type="checkbox"
                                    :aria-label="`Select row ${row[rowKey]}`"
                                    :checked="selectedIds.has(row[rowKey])"
                                    @change="toggleRowSelected(row[rowKey])"
                                />
                            </td>
                            <td v-for="col in visibleColumns" :key="col.key" :data-label="col.label">
                                <slot :name="`cell-${col.key}`" :row="row">
                                    {{ row[col.key] }}
                                </slot>
                            </td>
                            <td v-if="hasActions" class="actions-col" data-label="Actions">
                                <div class="row-actions">
                                    <button v-if="viewable" type="button" class="row-view-btn" :aria-label="`View ${row[rowKey]}`" @click="detailRow = row">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        View
                                    </button>
                                    <slot name="actions" :row="row"></slot>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Server-driven pagination (UI-5) -->
            <div v-if="lastPage > 1" class="table-pagination">
                <span class="pagination-summary" data-testid="pagination-summary">
                    Showing {{ from }}–{{ to }} of {{ total }}
                </span>
                <div class="pagination-controls">
                    <button type="button" data-testid="pagination-prev" :disabled="currentPage <= 1" @click="fetchPage(currentPage - 1)">Prev</button>
                    <span class="pagination-page" data-testid="pagination-page-label">Page {{ currentPage }} of {{ lastPage }}</span>
                    <button type="button" data-testid="pagination-next" :disabled="currentPage >= lastPage" @click="fetchPage(currentPage + 1)">Next</button>
                </div>
            </div>
        </template>
    </div>

    <teleport to="body">
        <div v-if="detailRow" class="record-overlay" @keydown.esc="detailRow = null">
            <div class="record-dialog" role="dialog" aria-modal="true" aria-label="Record details" tabindex="-1" ref="detailDialog">
                <div class="record-head">
                    <h3>Details</h3>
                    <button type="button" class="record-x" aria-label="Close" @click="detailRow = null">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <dl class="record-body">
                    <template v-for="item in detailItems" :key="item.key">
                        <dt>{{ item.label }}</dt>
                        <dd>{{ item.value }}</dd>
                    </template>
                </dl>
                <div class="record-foot"><button type="button" class="record-close" @click="detailRow = null">Close</button></div>
            </div>
        </div>
    </teleport>
</template>

<script setup>
import { ref, computed, onMounted, useSlots } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from '@/api';
import SkeletonLoader from './skeleton-loader.vue';
import EmptyState from './empty-state.vue';

/**
 * UI-4/UI-5: the one shared, server-driven table component. Replaces the
 * v-tables-3 pattern (load every row into the browser, paginate
 * client-side — see FUN-1) with real server-side pagination, sorting,
 * and searching, using the `?page=`/`?per_page=`/`?sort=`/`?direction=`/
 * `?search=` params the backend's paginatedOrFull() helper understands
 * (see app/Http/Controllers/Controller.php) — every one of these is a
 * real request to the server, not a client-side operation over an
 * already-fully-loaded dataset.
 *
 * Per-column custom rendering: pass a `#cell-<columnKey>` slot, e.g.
 *   <template #cell-status="{ row }"><Badge :status="row.status" /></template>
 * Row actions: pass an `#actions` slot, e.g.
 *   <template #actions="{ row }"><button @click="edit(row)">Edit</button></template>
 *
 * sortableColumns / searchableColumns must match the whitelist the
 * backend controller declared in its own paginatedOrFull() call, or the
 * server will simply ignore the param (see Controller.php's own
 * whitelisting comment for why).
 *
 * UI-5: table state (page/sort/direction/search) is mirrored to the
 * URL's query string via router.replace() (not push — every keystroke
 * or page click shouldn't add a back-button stop), so a filtered/sorted
 * view is a real, shareable/bookmarkable/refreshable link, not just
 * in-memory component state that resets on reload. On mount, any of
 * those params already present in the URL (e.g. from a shared link)
 * seed the table's initial state instead of always starting at page 1.
 * Only one DataTable is ever rendered per route in this app, so these
 * query keys aren't namespaced per-instance — add a `queryPrefix` prop
 * before ever putting two DataTables on the same page.
 */
const props = defineProps({
    fetchUrl: { type: String, required: true },
    columns: { type: Array, required: true }, // [{ key, label }]
    rowKey: { type: String, default: 'id' },
    perPage: { type: Number, default: 10 },
    emptyTitle: { type: String, default: 'Nothing here yet' },
    emptyDescription: { type: String, default: '' },
    emptyActionLabel: { type: String, default: '' },
    sortableColumns: { type: Array, default: () => [] },
    searchableColumns: { type: Array, default: () => [] },
    // UI-28: extra query-string keys (e.g. ['lifecycle_status']) this
    // table should read from the URL on mount and forward as plain GET
    // params on every fetch — this is what lets a dashboard KPI link to
    // "/cases?lifecycle_status=open" and have the list actually come up
    // pre-filtered, not just loaded. The backend controller is
    // responsible for actually applying each key (a fixed, known column
    // name — never interpolated dynamically), same trust boundary as
    // sortableColumns/searchableColumns above.
    filterParams: { type: Array, default: () => [] },
    // UI-35/UI-28: static key-values always merged into every fetch —
    // unlike filterParams (user-toggleable, URL-synced), this is for a
    // permanent scope the table's own page defines (e.g. a case's own
    // Hearings/Documents/Expenses tables, always scoped to that one
    // case_id) and should never appear in or be driven by the URL.
    extraParams: { type: Object, default: () => ({}) },
    // UI-20/UI-21/UI-19: opt-in row selection (bulk actions), and a
    // stable identifier for this table used to key localStorage-backed
    // saved presets and column-visibility prefs. Omit tableId and both
    // features simply don't render — safe default for existing usages.
    selectable: { type: Boolean, default: false },
    // every row gets a built-in View button that opens a read-only details dialog (set false where a page has its own view)
    viewable: { type: Boolean, default: true },
    tableId: { type: String, default: null },
});

// ---- built-in "View" details --------------------------------------------------------------------------------------
const slots = useSlots();
const hasActions = computed(() => props.viewable || !!slots.actions);
const detailRow = ref(null);
const humanize = (key) => key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
const show = (v) => {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? 'Yes' : 'No';
    if (Array.isArray(v)) return v.map((x) => (typeof x === 'object' ? (x?.name || x?.title || JSON.stringify(x)) : x)).join(', ') || '—';
    if (typeof v === 'object') return v.name || v.title || '—';
    return String(v);
};
const detailItems = computed(() => {
    const row = detailRow.value;
    if (!row) return [];
    const shown = new Set(props.columns.map((c) => c.key));
    const main = props.columns.map((c) => ({ key: c.key, label: c.label, value: show(row[c.key]) }));
    const more = Object.keys(row)
        .filter((k) => !shown.has(k) && k !== 'id' && !/(_id|_hash|password|token|secret)$/i.test(k) && (row[k] === null || typeof row[k] !== 'object' || Array.isArray(row[k])))
        .map((k) => ({ key: k, label: humanize(k), value: show(row[k]) }))
        .filter((i) => i.value !== '—');
    return [...main, ...more];
});

defineEmits(['empty-action']);

const route = useRoute();
const router = useRouter();

const rows = ref([]);
const loading = ref(true);
const error = ref(false);
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const from = ref(0);
const to = ref(0);
const sortColumn = ref(null);
const sortDirection = ref('asc');
const searchTerm = ref('');
const activeFilters = ref({});

// UI-20: bulk selection. Cleared on every fetch (page/sort/search/filter
// change) since "selected" refers to rows currently on screen — keeping
// a selection across an unrelated re-fetch would silently apply a bulk
// action to rows the person can no longer see.
const selectedIds = ref(new Set());

const allOnPageSelected = computed(() =>
    rows.value.length > 0 && rows.value.every((row) => selectedIds.value.has(row[props.rowKey]))
);

const toggleRowSelected = (id) => {
    const next = new Set(selectedIds.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    selectedIds.value = next;
};

const toggleSelectAllOnPage = () => {
    if (allOnPageSelected.value) {
        selectedIds.value = new Set();
    } else {
        selectedIds.value = new Set(rows.value.map((row) => row[props.rowKey]));
    }
};

const clearSelection = () => {
    selectedIds.value = new Set();
};

// UI-19: column visibility, persisted per tableId.
const columnPrefsStorageKey = () => `datatable:${props.tableId}:hidden-columns`;
const hiddenColumns = ref(new Set());
const showColumnMenu = ref(false);

const loadColumnPrefs = () => {
    if (!props.tableId) return;
    try {
        const stored = JSON.parse(localStorage.getItem(columnPrefsStorageKey()) || '[]');
        hiddenColumns.value = new Set(stored);
    } catch {
        hiddenColumns.value = new Set();
    }
};

const isColumnVisible = (key) => !hiddenColumns.value.has(key);

const toggleColumn = (key) => {
    const next = new Set(hiddenColumns.value);
    if (next.has(key)) next.delete(key); else next.add(key);
    hiddenColumns.value = next;
    if (props.tableId) {
        localStorage.setItem(columnPrefsStorageKey(), JSON.stringify(Array.from(next)));
    }
};

const visibleColumns = computed(() => props.columns.filter((col) => isColumnVisible(col.key)));

// UI-21: saved filter presets, v1 localStorage-only — stores whatever
// state is already mirrored to the URL (see syncUrl below), so "loading"
// a preset is just replacing the current query string with the saved
// one and re-fetching.
const presetsStorageKey = () => `datatable:${props.tableId}:presets`;
const presets = ref([]);

const loadPresets = () => {
    if (!props.tableId) return;
    try {
        presets.value = JSON.parse(localStorage.getItem(presetsStorageKey()) || '[]');
    } catch {
        presets.value = [];
    }
};

const promptSavePreset = () => {
    const name = window.prompt('Name this view (e.g. "My open cases"):');
    if (!name) return;

    const query = { ...route.query };
    delete query.page; // a saved view starts fresh, not mid-pagination

    const next = presets.value.filter((p) => p.name !== name);
    next.push({ name, query });
    presets.value = next;
    localStorage.setItem(presetsStorageKey(), JSON.stringify(next));
};

const onPresetSelected = (name) => {
    const preset = presets.value.find((p) => p.name === name);
    if (!preset) return;

    searchTerm.value = preset.query.search || '';
    sortColumn.value = preset.query.sort || null;
    sortDirection.value = preset.query.direction || 'asc';
    activeFilters.value = {};
    props.filterParams.forEach((key) => {
        if (preset.query[key]) activeFilters.value[key] = preset.query[key];
    });

    router.replace({ query: preset.query, hash: route.hash });
    fetchPage(1);
};

let searchDebounceTimer = null;

const isSortColumnAllowed = (column) => props.sortableColumns.includes(column);

const syncUrl = (page) => {
    const query = { ...route.query };

    if (page && page > 1) {
        query.page = String(page);
    } else {
        delete query.page;
    }

    if (sortColumn.value && isSortColumnAllowed(sortColumn.value)) {
        query.sort = sortColumn.value;
        query.direction = sortDirection.value;
    } else {
        delete query.sort;
        delete query.direction;
    }

    if (searchTerm.value) {
        query.search = searchTerm.value;
    } else {
        delete query.search;
    }

    props.filterParams.forEach((key) => {
        if (activeFilters.value[key]) {
            query[key] = activeFilters.value[key];
        } else {
            delete query[key];
        }
    });

    router.replace({ query, hash: route.hash });
};

const fetchPage = (page = 1, options = {}) => {
    loading.value = true;
    error.value = false;
    clearSelection();

    const params = { page, per_page: props.perPage, ...props.extraParams };
    if (sortColumn.value) {
        params.sort = sortColumn.value;
        params.direction = sortDirection.value;
    }
    if (searchTerm.value) {
        params.search = searchTerm.value;
    }

    props.filterParams.forEach((key) => {
        if (activeFilters.value[key]) {
            params[key] = activeFilters.value[key];
        }
    });

    axios.get(props.fetchUrl, { params })
        .then((response) => {
            const paginator = response.data.data;
            rows.value = paginator.data ?? [];
            currentPage.value = paginator.current_page ?? 1;
            lastPage.value = paginator.last_page ?? 1;
            total.value = paginator.total ?? rows.value.length;
            from.value = paginator.from ?? 0;
            to.value = paginator.to ?? rows.value.length;

            if (!options.skipUrlSync) {
                syncUrl(currentPage.value);
            }
        })
        .catch(() => {
            error.value = true;
        })
        .finally(() => {
            loading.value = false;
        });
};

const toggleSort = (column) => {
    if (sortColumn.value === column) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = column;
        sortDirection.value = 'asc';
    }
    fetchPage(1); // a new sort always starts back at page 1
};

const onSearchInput = () => {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => fetchPage(1), 300);
};

// UI-4/UI-5: refresh() takes an optional target page — most callers
// just want "reload whatever page I'm looking at" (after an edit or
// delete, where the affected row is presumably still on this page), but
// a freshly *created* record always sorts to page 1 under the
// id-DESC ordering every controller uses, regardless of which page the
// table happened to be on when the create happened. Without an explicit
// page-1 jump, creating a record while on page 2+ silently refreshed
// the wrong page and the new record was invisible until the user
// manually paged back — looked exactly like the create had silently
// failed even though it succeeded.
defineExpose({ refresh: (page) => fetchPage(page ?? currentPage.value, { skipUrlSync: true }) });

onMounted(() => {
    loadColumnPrefs();
    loadPresets();

    // UI-5: seed initial state from a shared/bookmarked URL, but only
    // honor sort/search values that are actually in this table's own
    // whitelist — a stale or hand-edited query string shouldn't be able
    // to request a sort column the backend would silently reject anyway
    // (see Controller.php's whitelist comment), or silently desync the
    // header's sort-indicator arrow from what's actually being sent.
    const initialPage = Number.parseInt(route.query.page, 10);
    const initialSort = typeof route.query.sort === 'string' ? route.query.sort : null;
    const initialDirection = route.query.direction === 'desc' ? 'desc' : 'asc';
    const initialSearch = typeof route.query.search === 'string' ? route.query.search : '';

    if (initialSort && props.sortableColumns.includes(initialSort)) {
        sortColumn.value = initialSort;
        sortDirection.value = initialDirection;
    }
    if (initialSearch && props.searchableColumns.length) {
        searchTerm.value = initialSearch;
    }

    props.filterParams.forEach((key) => {
        if (typeof route.query[key] === 'string') {
            activeFilters.value[key] = route.query[key];
        }
    });

    fetchPage(Number.isInteger(initialPage) && initialPage > 0 ? initialPage : 1);
});
</script>

<style lang="scss" scoped>
.table-search-row {
    margin-bottom: 12px;
}

.table-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 12px;
}

.toolbar-spacer {
    flex: 1;
}

.btn-toolbar {
    padding: 7px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--surface, #f1f5f9);
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    cursor: pointer;
    white-space: nowrap;

    &:hover {
        background: var(--neutral-100, #e2e8f0);
    }
}

.preset-controls {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}

.preset-select {
    padding: 7px 10px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px;
    background: #fff;
    max-width: 100%;
}

.column-toggle-wrapper {
    position: relative;
}

.column-menu {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    right: 0;
    min-width: 180px;
    max-width: calc(100vw - 32px);
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
    padding: 8px;
}

@media (max-width: 480px) {
    // UI-31: at narrow widths the toggle button can end up wrapped
    // near the left edge of the toolbar — right:0 would then anchor
    // this menu's right edge to a point close to the left of the
    // screen, pushing most of a 180px-wide menu off-screen to the
    // left. Anchoring from the left instead keeps it on-screen
    // regardless of where the button lands after wrapping.
    .column-menu {
        right: auto;
        left: 0;
    }
}

.column-menu-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 4px;
    font-size: 13px;
    cursor: pointer;

    &:hover {
        background: var(--surface, #f1f5f9);
    }
}

.bulk-action-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 10px 14px;
    margin-bottom: 12px;
    background: rgba(2, 132, 199, 0.06);
    border: 1px solid rgba(2, 132, 199, 0.2);
    border-radius: 8px;
}

.bulk-count {
    font-size: 13px;
    font-weight: 600;
    color: var(--primary-700);
}

.select-col {
    width: 36px;
    text-align: center;
}

.table-search-input {
    width: 100%;
    max-width: 320px;
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;

    &:focus {
        outline: none;
        border-color: var(--primary-600);
    }
}

.table-error {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-tertiary);
}

.table-wrapper {
    /* UI-23: horizontal scroll shadows — two edge gradients (foreground)
       plus two "cover" backgrounds that fade out via background-attachment:
       local, so the shadow only shows once there's actually more content
       to scroll to in that direction. Pure CSS, no scroll-position JS. */
    overflow-x: auto;
    background:
        linear-gradient(to right, var(--card-bg, #fff) 30%, rgba(255, 255, 255, 0)) 0 0,
        linear-gradient(to left, var(--card-bg, #fff) 30%, rgba(255, 255, 255, 0)) 100% 0,
        linear-gradient(to right, rgba(0, 0, 0, 0.08), rgba(0, 0, 0, 0)) 0 0,
        linear-gradient(to left, rgba(0, 0, 0, 0.08), rgba(0, 0, 0, 0)) 100% 0;
    background-repeat: no-repeat;
    background-color: var(--card-bg, #fff);
    background-size: 40px 100%, 40px 100%, 14px 100%, 14px 100%;
    background-attachment: local, local, scroll, scroll;
}

.app-table {
    width: 100%;
    border-collapse: collapse;

    th, td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid var(--border-light);
        font-size: 13px;
    }

    th {
        /* UI-23: sticky column headers so long lists don't scroll the
           labels out of view — sticks to the nearest scrolling ancestor
           (the page itself, in this app's layout). */
        position: sticky;
        top: 0;
        z-index: 1;
        background: var(--card-bg, #fff);
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.03em;

        .sort-button {
            font: inherit;
            color: inherit;
            text-transform: inherit;
            letter-spacing: inherit;
            text-align: inherit;
            width: 100%;   // the whole header sorts, as before
            padding: 0;
            border: 0;
            background: none;
            cursor: pointer;
            user-select: none;

            &:hover {
                color: var(--primary-600);
            }

            &:focus-visible {
                outline: 2px solid var(--primary-600);
                outline-offset: 2px;
            }
        }
    }

    tbody tr:hover {
        background: rgba(2, 132, 199, 0.03);
    }

    /* row actions are always visible: people should never have to hunt for View / Edit / Delete */
}

.sort-indicator {
    font-size: 10px;
    margin-left: 4px;
}

.actions-col {
    text-align: center;
    white-space: nowrap;
}

.table-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    flex-wrap: wrap;
    gap: 12px;
}

.pagination-summary {
    font-size: 12px;
    color: var(--text-tertiary);
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 12px;

    button {
        padding: 6px 14px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: var(--surface);
        font-size: 13px;
        cursor: pointer;

        &:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        &:not(:disabled):hover {
            background: rgba(2, 132, 199, 0.06);
        }
    }
}

.pagination-page {
    font-size: 13px;
    color: var(--text-secondary);
}

/*
 * UI-9: below 768px, the table stops being a table at all — each row
 * becomes its own card with label:value pairs, rather than a table
 * forced to horizontally scroll (which works, but is a poor mobile
 * experience for anything wider than 3-4 columns). Labels come from
 * the `data-label` attribute set on each <td> in the template above,
 * driven by the same `columns` prop that renders the desktop header,
 * so there's a single source of truth for column labels either way.
 */
@media (max-width: 768px) {
    .table-wrapper {
        overflow-x: visible;
    }

    .app-table {
        thead {
            // Not display:none — a fully hidden thead is invisible to
            // some screen readers too. Visually hidden, but still in
            // the accessibility tree.
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        tbody, tr, td {
            display: block;
            width: 100%;
        }

        tr {
            margin-bottom: 12px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 4px 0;
            background: var(--card-bg);
        }

        td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            text-align: right;
            border-bottom: 1px solid var(--border-light);

            &:last-child {
                border-bottom: none;
            }

            &::before {
                content: attr(data-label);
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
                color: var(--text-tertiary);
                text-align: left;
                flex-shrink: 0;
            }
        }

        .actions-col {
            justify-content: flex-end;

            &::before {
                display: none;
            }
        }
    }

    .table-pagination {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }

    .pagination-controls {
        justify-content: center;
    }
}

.actions-col { position: sticky; right: 0; background: var(--card-bg, #fff); box-shadow: -10px 0 10px -10px rgba(15, 23, 20, 0.18); z-index: 1; }
thead .actions-col { background: var(--neutral-50, #f8f9f7); }
.row-actions { display: inline-flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: nowrap; }
.row-view-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
    border: 1px solid var(--primary-300, #93bcb0); background: #fff; color: var(--primary-700, #0b5842); transition: background 0.15s;
    &:hover { background: var(--primary-50, #f3f8f6); }
}

.record-overlay { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 20, 0.5); }
.record-dialog { width: 100%; max-width: 560px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: var(--card-bg, #fff); border-radius: 14px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3); outline: none; }
.record-head { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border-light, #ebeee9); }
.record-head h3 { margin: 0; font-size: 1.2rem; }
.record-x { width: 38px; height: 38px; display: grid; place-items: center; border: 1px solid var(--border-color, #dfe4de); border-radius: 10px; background: #fff; color: #3c4a44; cursor: pointer; }
.record-x:hover { background: #fdecea; color: #b42318; border-color: #f3b4ae; }
.record-body { margin: 0; padding: 8px 20px; overflow-y: auto; display: grid; grid-template-columns: minmax(130px, 38%) 1fr; }
.record-body dt, .record-body dd { padding: 11px 0; margin: 0; border-bottom: 1px dashed var(--border-color, #dfe4de); }
.record-body dt { font-size: 13.5px; color: var(--text-muted, #5d6b64); font-weight: 500; padding-right: 12px; }
.record-body dd { font-weight: 600; color: var(--text-primary, #16211d); word-break: break-word; }
.record-foot { padding: 14px 20px; border-top: 1px solid var(--border-light, #ebeee9); text-align: right; }
.record-close { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--border-color, #dfe4de); background: #fff; font-weight: 600; cursor: pointer; }
</style>
