<template>
    <teleport to="body">
        <div v-if="isOpen" class="search-palette-overlay" @click.self="close">
            <div class="search-palette" role="dialog" aria-modal="true" aria-label="Global search">
                <div class="search-input-row">
                    <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input
                        ref="inputRef"
                        v-model="query"
                        type="text"
                        placeholder="Search cases, clients, documents..."
                        class="search-input"
                        aria-label="Search cases, clients, documents"
                        @keydown.esc="close"
                        @keydown.down.prevent="moveSelection(1)"
                        @keydown.up.prevent="moveSelection(-1)"
                        @keydown.enter.prevent="openSelected"
                    />
                    <kbd class="esc-hint">Esc</kbd>
                </div>

                <div class="search-results" v-if="query.length >= 2">
                    <div v-if="loading" class="search-state">Searching…</div>
                    <div v-else-if="!hasResults" class="search-state">No results for "{{ query }}"</div>
                    <template v-else>
                        <div v-for="group in resultGroups" :key="group.type" class="search-group">
                            <div class="search-group-label">{{ group.label }}</div>
                            <button
                                v-for="(item, idx) in group.items"
                                :key="`${group.type}-${item.id}`"
                                type="button"
                                class="search-result"
                                :class="{ active: isActive(group.type, idx) }"
                                @mouseenter="setActive(group.type, idx)"
                                @click="openResult(item)"
                            >
                                <span class="result-title">{{ item.title }}</span>
                                <span v-if="item.subtitle" class="result-subtitle">{{ item.subtitle }}</span>
                            </button>
                        </div>
                    </template>
                </div>
                <!-- UI-8: recently viewed cases/clients, shown above the
                     "type to search" hint once the palette is opened but
                     before the person has typed anything. -->
                <div v-else-if="recentItems.length" class="search-results">
                    <div class="search-group">
                        <div class="search-group-label">Recently Viewed</div>
                        <button
                            v-for="(item, idx) in recentItems"
                            :key="`recent-${item.type}-${item.id}`"
                            type="button"
                            class="search-result"
                            :class="{ active: activeFlatIndex === idx && !query.length }"
                            @mouseenter="activeFlatIndex = idx"
                            @click="openResult(item)"
                        >
                            <span class="result-title">{{ item.title }}</span>
                            <span v-if="item.subtitle" class="result-subtitle">{{ item.subtitle }}</span>
                        </button>
                    </div>
                </div>
                <div v-else class="search-state search-hint">Type at least 2 characters to search</div>
            </div>
        </div>
    </teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import axios from '@/api';
import { useRecentlyViewed } from '@/composables/use-recently-viewed';

// UI-9: global search, Ctrl/Cmd-K palette, across cases/clients/documents.
// The backend (SearchController, see FUN-7) already scopes results to
// what the current user's permissions allow, so this component doesn't
// need its own visibility logic — it just renders whatever comes back.

const router = useRouter();
const { getAll: getRecentItems } = useRecentlyViewed();
const isOpen = ref(false);
const query = ref('');
const loading = ref(false);
const results = ref({ cases: [], clients: [], documents: [] });
const inputRef = ref(null);
const recentItems = ref([]);

let debounceTimer = null;

const resultGroups = computed(() => {
    const groups = [];
    if (results.value.cases?.length) groups.push({ type: 'cases', label: 'Cases', items: results.value.cases });
    if (results.value.clients?.length) groups.push({ type: 'clients', label: 'Clients', items: results.value.clients });
    if (results.value.documents?.length) groups.push({ type: 'documents', label: 'Documents', items: results.value.documents });
    return groups;
});

const hasResults = computed(() => resultGroups.value.some((g) => g.items.length > 0));

const flatResults = computed(() =>
    resultGroups.value.flatMap((g) => g.items.map((item) => ({ type: g.type, item })))
);

const activeFlatIndex = ref(0);

const isActive = (type, idx) => {
    const flat = flatResults.value;
    const target = flat[activeFlatIndex.value];
    return target && target.type === type && flat.filter((f) => f.type === type).indexOf(target) === idx;
};

const setActiveByGroupIndex = (type, idx) => {
    const flat = flatResults.value;
    const pos = flat.findIndex((f, i) => {
        const sameTypeBefore = flat.slice(0, i).filter((x) => x.type === type).length;
        return f.type === type && sameTypeBefore === idx;
    });
    if (pos !== -1) activeFlatIndex.value = pos;
};
const setActive = setActiveByGroupIndex;

const moveSelection = (direction) => {
    const flat = flatResults.value;
    if (!flat.length) return;
    activeFlatIndex.value = (activeFlatIndex.value + direction + flat.length) % flat.length;
};

const openSelected = () => {
    const item = flatResults.value[activeFlatIndex.value]?.item;
    if (item) openResult(item);
};

const openResult = (item) => {
    if (item.type === 'case') {
        localStorage.setItem('caseId', item.id);
        router.push('/view-case');
    } else if (item.type === 'client') {
        router.push({ name: 'client-details', params: { id: item.public_id || item.id } });
    } else if (item.type === 'document' && item.case_id) {
        localStorage.setItem('caseId', item.case_id);
        router.push('/view-case');
    }
    close();
};

const runSearch = () => {
    if (query.value.length < 2) {
        results.value = { cases: [], clients: [], documents: [] };
        return;
    }
    loading.value = true;
    axios.get('/api/v1/search', { params: { q: query.value } })
        .then((response) => {
            results.value = response.data.data;
            activeFlatIndex.value = 0;
        })
        .catch(() => {
            results.value = { cases: [], clients: [], documents: [] };
        })
        .finally(() => {
            loading.value = false;
        });
};

watch(query, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, 250);
});

const open = () => {
    isOpen.value = true;
    recentItems.value = getRecentItems();
    activeFlatIndex.value = 0;
    nextTick(() => inputRef.value?.focus());
};

const close = () => {
    isOpen.value = false;
    query.value = '';
    results.value = { cases: [], clients: [], documents: [] };
};

defineExpose({ open, close });

// UI-32: known "open the create form" button classes across modules —
// following the existing .btn-add-<module> naming convention already
// used throughout the app (see e.g. list-cases.vue's .btn-add-case),
// rather than inventing a new pattern just for this shortcut.
const ADD_BUTTON_SELECTORS = '.btn-add, .btn-add-case, .btn-add-client, .btn-add-hearing, .btn-add-expense, .btn-add-invoice, .btn-add-task, .btn-add-court, .btn-add-type, .btn-add-category, .btn-add-role, .btn-add-entry';

const isElementVisible = (el) => {
    if (!el) return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
};

const handleKeydown = (e) => {
    const isCmdK = (e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k';
    if (isCmdK) {
        e.preventDefault();
        isOpen.value ? close() : open();
        return;
    }

    // UI-32: "/" is a common "jump to search" shortcut (GitHub, Slack,
    // etc.) — guarded so it only fires when the person isn't already
    // typing somewhere (a text input, textarea, contenteditable, or a
    // select), otherwise every "/" typed into a form field anywhere in
    // the app would hijack it and open the palette instead.
    if (e.key === '/' && !isOpen.value) {
        const active = document.activeElement;
        const tag = active?.tagName;
        const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || active?.isContentEditable;
        if (!isTyping) {
            e.preventDefault();
            open();
        }
        return;
    }

    // UI-32: "n" opens whatever "create new" form is available on the
    // current page — whichever module's own .btn-add-* button is
    // visible gets clicked, same guard against interrupting normal
    // typing as "/" above. Does nothing (rather than fail loudly) on a
    // page with no create action, e.g. the dashboard.
    if (e.key.toLowerCase() === 'n' && !isOpen.value) {
        const active = document.activeElement;
        const tag = active?.tagName;
        const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || active?.isContentEditable;
        if (!isTyping) {
            const addButton = Array.from(document.querySelectorAll(ADD_BUTTON_SELECTORS)).find(isElementVisible);
            if (addButton) {
                e.preventDefault();
                addButton.click();
            }
        }
    }
};

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<style lang="scss" scoped>
.search-palette-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1200;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding-top: 12vh;
}

.search-palette {
    width: 100%;
    max-width: 560px;
    background: var(--background, #fff);
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    overflow: hidden;
    max-height: 70vh;
    display: flex;
    flex-direction: column;
}

.search-input-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 18px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
}

.search-icon {
    flex-shrink: 0;
    color: var(--text-tertiary);
}

.search-input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 16px;
    background: transparent;
    color: inherit;
}

.esc-hint {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.08);
    color: var(--text-tertiary);
}

.search-results {
    overflow-y: auto;
}

.search-state {
    padding: 24px 18px;
    text-align: center;
    color: var(--text-tertiary);
    font-size: 14px;
}

.search-group-label {
    padding: 10px 18px 4px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 600;
}

.search-result {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    padding: 10px 18px;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;

    &:hover,
    &.active {
        background: rgba(2, 132, 199, 0.08);
    }
}

.result-title {
    font-size: 14px;
    font-weight: 500;
}

.result-subtitle {
    font-size: 12px;
    color: var(--text-tertiary);
}
</style>
