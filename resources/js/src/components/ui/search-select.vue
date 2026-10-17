<template>
    <div class="search-select" :class="{ 'is-invalid': invalid }">
        <input
            :id="inputId"
            ref="inputEl"
            type="text"
            class="form-control search-select-input"
            :class="inputClass"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="listboxId"
            :aria-activedescendant="highlightedIndex > -1 ? `${listboxId}-opt-${highlightedIndex}` : undefined"
            autocomplete="off"
            :placeholder="placeholder"
            :disabled="disabled"
            v-model="query"
            @focus="onFocus"
            @input="onInput"
            @keydown.down.prevent="moveHighlight(1)"
            @keydown.up.prevent="moveHighlight(-1)"
            @keydown.enter.prevent="selectHighlighted"
            @keydown.esc="closeAndRevert"
            @blur="onBlur"
        />

        <ul v-if="open" :id="listboxId" class="search-select-listbox" role="listbox">
            <li v-if="loading" class="search-select-status">Searching…</li>
            <li v-else-if="!options.length" class="search-select-status">No matches</li>
            <li
                v-for="(option, index) in options"
                :key="option[valueKey]"
                :id="`${listboxId}-opt-${index}`"
                role="option"
                :aria-selected="index === highlightedIndex"
                class="search-select-option"
                :class="{ highlighted: index === highlightedIndex }"
                @mousedown.prevent="select(option)"
                @mouseenter="highlightedIndex = index"
            >
                {{ option[labelKey] }}
            </li>
        </ul>
    </div>
</template>

<script setup>
/**
 * UI-16: shared typeahead combobox — debounced server-side search against
 * the existing dropdown endpoints (now search+limit aware, see
 * ClientController::clientsDropDown / CasesController::dropDown /
 * AttorneyController::getAdvocateUsers / CourtController::index) instead
 * of loading every client/case/advocate/court into the browser on modal
 * open. Follows the ARIA combobox pattern: role="combobox" input +
 * role="listbox" popup, arrow-key navigation, Enter to select, Esc to
 * revert.
 *
 * Usage:
 *   <SearchSelect
 *     fetch-url="/api/v1/clientsDropDown"
 *     v-model="client.client_id"
 *     :initial-label="client.client_name"
 *     placeholder="Search clients..."
 *   />
 *
 * For an endpoint that only exposes search via the paginatedOrFull
 * paginator shape (courts today), pass :paginated="true" — this sends
 * page=1 alongside search/per_page and reads options from
 * response.data.data.data instead of response.data.data.
 */
import { ref, computed, watch, onMounted } from 'vue';
import axios from '@/api';

const props = defineProps({
    fetchUrl: { type: String, required: true },
    modelValue: { type: [Number, String, null], default: null },
    initialLabel: { type: String, default: '' },
    labelKey: { type: String, default: 'name' },
    valueKey: { type: String, default: 'id' },
    searchParam: { type: String, default: 'search' },
    perPage: { type: Number, default: 20 },
    paginated: { type: Boolean, default: false },
    extraParams: { type: Object, default: () => ({}) },
    placeholder: { type: String, default: 'Search...' },
    invalid: { type: Boolean, default: false },
    inputClass: { type: [String, Array, Object], default: '' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const inputId = `search-select-${Math.random().toString(36).slice(2, 9)}`;
const listboxId = `${inputId}-listbox`;

const inputEl = ref(null);
const query = ref(props.initialLabel || '');
const selectedLabel = ref(props.initialLabel || '');
const options = ref([]);
const open = ref(false);
const loading = ref(false);
const highlightedIndex = ref(-1);

let debounceTimer = null;
let requestSeq = 0;

const runSearch = (term) => {
    loading.value = true;
    const seq = ++requestSeq;

    const params = { ...props.extraParams, [props.searchParam]: term, per_page: props.perPage };
    if (props.paginated) params.page = 1;

    axios.get(props.fetchUrl, { params })
        .then((response) => {
            if (seq !== requestSeq) return; // a newer keystroke's request already landed
            const payload = response.data.data;
            options.value = props.paginated ? (payload?.data ?? []) : (payload ?? []);
        })
        .catch(() => {
            if (seq === requestSeq) options.value = [];
        })
        .finally(() => {
            if (seq === requestSeq) loading.value = false;
        });
};

const onFocus = () => {
    open.value = true;
    highlightedIndex.value = -1;
    runSearch(query.value === selectedLabel.value ? '' : query.value);
};

const onInput = () => {
    open.value = true;
    highlightedIndex.value = -1;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => runSearch(query.value), 300);
};

const moveHighlight = (delta) => {
    if (!open.value) {
        open.value = true;
        runSearch(query.value);
        return;
    }
    if (!options.value.length) return;
    const next = highlightedIndex.value + delta;
    highlightedIndex.value = Math.max(0, Math.min(options.value.length - 1, next));
};

const select = (option) => {
    selectedLabel.value = option[props.labelKey];
    query.value = option[props.labelKey];
    open.value = false;
    emit('update:modelValue', option[props.valueKey]);
    emit('select', option);
};

const selectHighlighted = () => {
    if (open.value && highlightedIndex.value > -1 && options.value[highlightedIndex.value]) {
        select(options.value[highlightedIndex.value]);
    }
};

const closeAndRevert = () => {
    open.value = false;
    query.value = selectedLabel.value;
    inputEl.value?.blur();
};

const onBlur = () => {
    // Give a click on an option (mousedown) a chance to fire first.
    setTimeout(() => {
        open.value = false;
        if (!props.modelValue) {
            query.value = '';
            selectedLabel.value = '';
        } else {
            query.value = selectedLabel.value;
        }
    }, 120);
};

watch(() => props.initialLabel, (label) => {
    if (label && label !== selectedLabel.value) {
        selectedLabel.value = label;
        query.value = label;
    }
});

watch(() => props.modelValue, (val) => {
    if (!val) {
        selectedLabel.value = '';
        query.value = '';
    }
});
</script>

<style lang="scss" scoped>
.search-select {
    position: relative;
}

.search-select-input {
    width: 100%;
}

.search-select.is-invalid .search-select-input {
    border-color: #e7515a;
}

.search-select-listbox {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 240px;
    overflow-y: auto;
    margin: 0;
    padding: 4px;
    list-style: none;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
}

.search-select-option {
    padding: 8px 10px;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;

    &.highlighted {
        background: var(--primary-100);
        color: var(--primary-700);
    }
}

.search-select-status {
    padding: 8px 10px;
    font-size: 12px;
    color: var(--text-tertiary, #94a3b8);
}
</style>
