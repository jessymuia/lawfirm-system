<template>
    <flat-pickr
        v-model="internalDate"
        :config="config"
        class="modern-input app-date-picker"
        :class="{ 'is-invalid': invalid }"
        :placeholder="placeholder"
    />
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import FlatPickr from 'vue-flatpickr-component';
import 'flatpickr/dist/flatpickr.css';
import { parseWireDate, formatWireDate } from '@/utils/date';

// UI-7: the one date-picker component used everywhere. Emits/accepts the
// same 'd/m/Y' wire string every form and API endpoint already expects,
// so it's a drop-in replacement for the old maska-masked text input with
// no backend changes required.

const props = defineProps({
    modelValue: { type: String, default: '' }, // 'd/m/Y' string
    placeholder: { type: String, default: 'dd/mm/yyyy' },
    invalid: { type: Boolean, default: false },
    minDate: { type: String, default: null }, // 'd/m/Y'
    maxDate: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

// computed (not a plain object) so minDate/maxDate changing after mount
// — e.g. an end-date picker whose minDate follows a start-date field —
// actually updates flatpickr's constraints, not just the initial render.
const config = computed(() => ({
    dateFormat: 'd/m/Y',
    allowInput: true,
    minDate: props.minDate ? parseWireDate(props.minDate) : null,
    maxDate: props.maxDate ? parseWireDate(props.maxDate) : null,
}));

const internalDate = ref(props.modelValue);

watch(() => props.modelValue, (val) => {
    if (val !== internalDate.value) internalDate.value = val;
});

watch(internalDate, (val) => {
    // flatpickr emits either a formatted string or a Date depending on
    // interaction path; normalize to the wire string either way.
    const wireValue = typeof val === 'string' ? val : formatWireDate(val);
    // Bug fix (found via a real E2E run — a form field elsewhere on the
    // same page appeared to cause an unrelated date picker to flicker
    // continuously): without this guard, a Date object emitted by
    // flatpickr that doesn't formatWireDate() back to byte-identical
    // wire string as what modelValue already held could round-trip
    // forever — this watcher emits, the parent updates modelValue, the
    // watcher above sees a "different" value (comparing a Date to a
    // string, or two Dates by reference, is never equal) and pushes it
    // back into internalDate, re-triggering this watcher, indefinitely.
    // Comparing against the normalized wire value (not the raw internal
    // one) closes that loop the same way the watcher above already
    // guards its own direction.
    if (wireValue !== props.modelValue) {
        emit('update:modelValue', wireValue);
    }
});
</script>

<style scoped>
.app-date-picker.is-invalid {
    border-color: #e7515a;
}
</style>
