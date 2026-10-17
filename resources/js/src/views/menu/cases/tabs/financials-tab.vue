<template>
    <div>
        <!-- Case Expenses -->
        <div class="elevated-card">
            <div class="card-header">
                <h2 class="card-title">Case Expenses</h2>
                <span class="expenses-total-badge" data-testid="case-expenses-total">
                    Total: {{ formatMoney(totalExpenses) }}
                </span>
            </div>

            <div class="card-body">
                <DataTable
                    ref="expensesTableRef"
                    fetch-url="/api/v1/expenses"
                    :extra-params="{ case_id: caseId }"
                    :columns="[
                        { key: 'expense_date', label: 'Date' },
                        { key: 'amount', label: 'Amount' },
                        { key: 'category_name', label: 'Category' },
                        { key: 'description', label: 'Description' },
                        { key: 'vendor', label: 'Vendor' },
                        { key: 'payment_method', label: 'Payment Method' },
                        { key: 'invoice_number', label: 'Invoice #' },
                        { key: 'user', label: 'Logged By' },
                    ]"
                    :sortable-columns="['amount']"
                    :searchable-columns="['description', 'vendor']"
                    empty-title="No expenses yet"
                    empty-description="Expenses logged against this case will show up here."
                >
                    <template #actions="{ row }">
                        <div class="actions text-center">
                            <button type="button" class="btn-action btn-delete" @click="deleteExpense(row)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                </svg>
                                Delete
                            </button>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Time Entries (FUN-2) -->
        <TimeEntriesPanel :case-id="caseId" :client-id="clientId" />

        <!-- Invoices for this case -->
        <div class="elevated-card">
            <div class="card-header">
                <h2 class="card-title">Invoices</h2>
                <router-link :to="{ name: 'invoices' }" class="btn-add">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    New Invoice
                </router-link>
            </div>

            <div class="card-body">
                <DataTable
                    :viewable="false"
                    ref="invoicesTableRef"
                    fetch-url="/api/v1/invoices"
                    :extra-params="{ case_id: caseId }"
                    :columns="[
                        { key: 'invoice_number', label: 'Invoice #' },
                        { key: 'invoice_date', label: 'Date' },
                        { key: 'invoice_due_date', label: 'Due' },
                        { key: 'total_amount', label: 'Total' },
                        { key: 'payment_status', label: 'Status' },
                    ]"
                    :sortable-columns="['invoice_date', 'invoice_due_date']"
                    empty-title="No invoices yet"
                    empty-description="Invoices raised for this case will show up here."
                >
                    <template #cell-invoice_number="{ row }">
                        <a href="javascript:;" class="cross-link" @click="goToInvoice(row.id)">{{ row.invoice_number }}</a>
                    </template>
                    <template #cell-payment_status="{ row }">
                        <InvoiceStatus :payment-status="row.payment_status" :status="row.status" :due-date="row.invoice_due_date" />
                    </template>
                </DataTable>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * UI-10: Financials tab — split out of the former view-case.vue as part
 * of the tabbed redesign. Groups everything money-related for a case:
 * expenses, time entries (existing TimeEntriesPanel, unchanged), and —
 * new in this pass — an invoices-for-case panel (InvoiceController::
 * index gained a case_id filter alongside this).
 */
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import InvoiceStatus from '@/components/ui/invoice-status.vue';
import axios, { fetchAllPages } from '@/api';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import DataTable from '@/components/ui/data-table.vue';
import TimeEntriesPanel from '../time-entries-panel.vue';

const props = defineProps({
    caseId: { type: [Number, String], required: true },
    clientId: { type: [Number, String], default: null },
});

const emit = defineEmits(['changed']);

const router = useRouter();
const { confirmDelete } = useConfirmDelete();

const expensesTableRef = ref(null);
const invoicesTableRef = ref(null);

// UI-35: expenses/index.vue-style running total — this tab's own
// uncapped fetch, independent of whatever page the expenses DataTable
// itself is currently showing.
const allExpenses = ref([]);
const totalExpenses = computed(() =>
    allExpenses.value.reduce((sum, expense) => sum + Number(expense.amount || 0), 0)
);

const fetchExpensesTotal = () => {
    // ENG-16: every expense of this case (for the total), walking the pages.
    fetchAllPages('/api/v1/expenses', { case_id: props.caseId })
        .then(({ rows }) => {
            allExpenses.value = rows;
        })
        .catch(() => {});
};

const formatMoney = (value) => {
    const n = Number(value) || 0;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const deleteExpense = (item) => {
    confirmDelete({
        url: `/api/v1/expenses/${item.id}`,
        itemLabel: item.description || 'this expense',
        onSuccess: () => { expensesTableRef.value?.refresh(); fetchExpensesTotal(); emit('changed'); },
    });
};

// UI-9: the invoice-details route has no id URL param — it reads
// localStorage, same pattern as the case/hearing cross-links elsewhere.
const goToInvoice = (invoiceId) => {
    localStorage.setItem('invoiceId', invoiceId);
    router.push('/view-invoice');
};

onMounted(() => {
    fetchExpensesTotal();
});
</script>

<style scoped>
.elevated-card {
    background: #fff;
    border-radius: 12px;
    margin-bottom: 24px;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 28px 32px;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
}

.card-title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

.card-body {
    padding: 20px 32px 32px;
}

.expenses-total-badge {
    font-size: 13px;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 999px;
    background: var(--surface, #f1f5f9);
    color: var(--text-secondary, #64748b);
}

.btn-add {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    border: none;
    border-radius: var(--radius-md, 8px);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
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
    text-transform: capitalize;
    background: var(--surface, #f1f5f9);
    color: var(--text-secondary, #64748b);

    &.paid {
        background: rgba(16, 185, 129, 0.12);
        color: var(--primary-600);
    }

    &.unpaid, &.partially_paid {
        background: rgba(245, 158, 11, 0.12);
        color: #b45309;
    }
}
</style>
