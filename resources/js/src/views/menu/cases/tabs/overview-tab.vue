<template>
    <div class="elevated-card">
        <div class="card-header">
            <div class="header-content">
                <h2 class="card-title">Case Information</h2>
                <span class="case-badge">{{ caseData.case_number || 'N/A' }}</span>
            </div>
            <button type="button" class="btn-print-summary" @click="$emit('print')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Print Case Summary
            </button>
        </div>

        <div class="card-body">
            <!-- UI-10/UI-12: lifecycle stepper — current status + legal
                 next states, driven by the backend's own transitions (MatterStatus)
                 (fetched once from /api/v1/cases/{id}, not hardcoded here). -->
            <LifecycleStepper
                v-if="caseData.lifecycle_status && transitions"
                :current-status="caseData.lifecycle_status"
                :transitions="transitions"
                :transitioning="transitioning"
                @transition="onTransition"
            />

            <div class="info-grid">
                <div class="info-item">
                    <label class="info-label">Case Number</label>
                    <div class="info-value">{{ caseData.case_number || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Client</label>
                    <div class="info-value">{{ caseData.client || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Assigned Attorney</label>
                    <div class="info-value">{{ caseData.assigned || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Case Type</label>
                    <div class="info-value">{{ caseData.type || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Start Date</label>
                    <div class="info-value">{{ caseData.start_date || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Police Station</label>
                    <div class="info-value">{{ caseData.police_station || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Court</label>
                    <div class="info-value">{{ caseData.court || 'N/A' }}</div>
                </div>

                <div class="info-item">
                    <label class="info-label">Opposing Party</label>
                    <div class="info-value">{{ caseData.opposing_party || 'N/A' }}</div>
                </div>

                <div class="info-item full-width">
                    <label class="info-label">Description</label>
                    <div class="info-value description-value">{{ caseData.description || 'No description provided' }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { confirmAction } from '@/composables/use-confirm';
/**
 * UI-10: Overview tab — parties/court/status. Split out of the former
 * ~1000-line view-case.vue as part of the tabbed redesign (see the
 * shell, views/menu/cases/view-case.vue).
 */
import { ref } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';
import LifecycleStepper from '@/components/ui/lifecycle-stepper.vue';

const props = defineProps({
    caseData: { type: Object, required: true },
    transitions: { type: Object, default: null },
});

const emit = defineEmits(['print', 'updated']);

const { showMessage } = useToast();
const transitioning = ref(false);

const onTransition = async (newStatus) => {
    const closing = /clos|archiv|won|lost|settled|dismiss/i.test(String(newStatus));
    if (!(await confirmAction({
        title: `Move this case to "${newStatus}"?`,
        text: closing ? 'Closing a case can stop reminders and time capture on it. You can reopen it later from the same menu.' : 'The case status is shown to everyone with access to the matter.',
        confirmText: `Move to ${newStatus}`, danger: closing,
    }))) return;
    transitioning.value = true;
    axios.put(`/api/v1/cases/${props.caseData.id}/status`, { lifecycle_status: newStatus })
        .then((response) => {
            showMessage(`Case moved to "${newStatus}".`);
            emit('updated', response.data.data);
        })
        .catch((error) => {
            const message = error.response?.data?.message || 'Could not change the case status. Please try again.';
            showMessage(message, 'error');
        })
        .finally(() => {
            transitioning.value = false;
        });
};
</script>

<style scoped>
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 28px 32px;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 12px;
}

.card-title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
}

.case-badge {
    padding: 4px 12px;
    border-radius: 999px;
    background: var(--surface, #f1f5f9);
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
}

.btn-print-summary {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--surface, #f1f5f9);
    color: var(--text-primary, #0f172a);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: var(--radius-md, 8px);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;

    &:hover {
        background: var(--neutral-100, #e2e8f0);
    }
}

.card-body {
    padding: 28px 32px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.info-item.full-width {
    grid-column: 1 / -1;
}

.info-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--text-tertiary, #94a3b8);
    font-weight: 600;
    margin-bottom: 4px;
}

.info-value {
    font-size: 14px;
    color: var(--text-primary, #0f172a);
}

.description-value {
    white-space: pre-wrap;
}
</style>
