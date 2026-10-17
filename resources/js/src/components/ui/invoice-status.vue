<template>
    <span class="inv-status" :class="`is-${state.key}`" :title="state.title">
        <span class="dot" aria-hidden="true"></span>{{ state.label }}
    </span>
</template>

<script setup>
/**
 * One badge for an invoice everywhere it is listed. Payment status wins once an invoice is posted:
 * Paid, Part paid, Unpaid; an unpaid or part-paid invoice past its due date reads Overdue.
 * Drafts and voided invoices keep their own label.
 */
import { computed } from 'vue';

const props = defineProps({
    paymentStatus: { type: String, default: '' },
    status: { type: String, default: '' },        // workflow status, or 'void'
    dueDate: { type: String, default: '' },       // 'd/m/Y' or 'Y-m-d'
});

const parseDue = (value) => {
    const text = String(value || '');
    let m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text);
    if (m) return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]), 23, 59, 59);
    m = /^(\d{4})-(\d{2})-(\d{2})/.exec(text);
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 23, 59, 59) : null;
};

const state = computed(() => {
    const status = String(props.status || '').toLowerCase();
    const pay = String(props.paymentStatus || '').toLowerCase();
    if (status === 'void') return { key: 'void', label: 'Void', title: 'This invoice was voided' };
    if (status === 'draft') return { key: 'draft', label: 'Draft', title: 'Not yet posted to the client' };
    if (pay === 'paid') return { key: 'paid', label: 'Paid', title: 'Paid in full' };
    const due = parseDue(props.dueDate);
    if (due && due.getTime() < Date.now()) return { key: 'overdue', label: 'Overdue', title: 'Past its due date and not fully paid' };
    if (pay === 'partially_paid') return { key: 'partial', label: 'Part paid', title: 'Some of this invoice has been paid' };
    return { key: 'unpaid', label: 'Unpaid', title: 'Waiting for payment' };
});
</script>

<style scoped>
.inv-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 12.5px; font-weight: 700; line-height: 1.3; white-space: nowrap; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.is-paid { background: var(--success-100); color: var(--success-700); }
.is-partial { background: var(--warning-100); color: var(--text-primary); }
.is-unpaid { background: var(--primary-100); color: var(--primary-800); }
.is-overdue { background: var(--error-100); color: var(--error-700); }
.is-draft { background: var(--neutral-100); color: var(--neutral-700); }
.is-void { background: var(--neutral-100); color: var(--neutral-500); text-decoration: line-through; }
</style>
