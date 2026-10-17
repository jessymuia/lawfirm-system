<template>
    <div class="elevated-card">
        <div class="card-header">
            <h2 class="card-title">Activity</h2>
        </div>

        <div class="card-body">
            <SkeletonLoader v-if="loading && items.length === 0" :rows="5" />

            <div v-else-if="!items.length" class="empty-state">
                No activity recorded for this case yet.
            </div>

            <ol v-else class="activity-timeline">
                <li v-for="(item, index) in items" :key="index" class="activity-item">
                    <span class="activity-dot" :class="item.type"></span>
                    <div class="activity-content">
                        <p class="activity-description">{{ item.description }}</p>
                        <span class="activity-time">{{ timeAgo(item.occurred_at) }}</span>
                    </div>
                </li>
            </ol>

            <div v-if="lastPage > 1" class="table-pagination">
                <button type="button" :disabled="page <= 1" @click="fetchPage(page - 1)">Prev</button>
                <span>Page {{ page }} of {{ lastPage }}</span>
                <button type="button" :disabled="page >= lastPage" @click="fetchPage(page + 1)">Next</button>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * UI-11: Activity tab — a chronological feed of everything that has
 * happened on this case (audit trail for the case and every child
 * record — hearings/documents/expenses/time entries — merged with
 * document view/download records). See CasesController::activity() for
 * where these two different data sources get combined server-side.
 *
 * Refetches whenever this tab regains focus (the accept criterion is
 * "no refresh weirdness" — a create/update elsewhere shouldn't require
 * a manual page reload to show up here) rather than polling.
 */
import { ref, onMounted, onActivated } from 'vue';
import axios from '@/api';
import SkeletonLoader from '@/components/ui/skeleton-loader.vue';

const props = defineProps({
    caseId: { type: [Number, String], required: true },
});

const items = ref([]);
const loading = ref(true);
const page = ref(1);
const lastPage = ref(1);

const fetchPage = (targetPage = 1) => {
    loading.value = true;
    axios.get(`/api/v1/cases/${props.caseId}/activity`, { params: { page: targetPage, per_page: 20 } })
        .then((response) => {
            const payload = response.data.data;
            items.value = payload.data ?? [];
            page.value = payload.current_page ?? 1;
            lastPage.value = payload.last_page ?? 1;
        })
        .catch(() => {
            items.value = [];
        })
        .finally(() => {
            loading.value = false;
        });
};

const timeAgo = (isoDate) => {
    if (!isoDate) return '';
    const then = new Date(isoDate).getTime();
    const now = Date.now();
    const diffMinutes = Math.round((now - then) / 60000);

    if (diffMinutes < 1) return 'just now';
    if (diffMinutes < 60) return `${diffMinutes}m ago`;
    const diffHours = Math.round(diffMinutes / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.round(diffHours / 24);
    return `${diffDays}d ago`;
};

onMounted(() => fetchPage(1));
// If this component is ever kept alive inside a <keep-alive> (e.g. a
// future switch away from v-if tab switching), refetch on re-activation
// too so activity from other tabs shows up without a full page reload.
onActivated(() => fetchPage(page.value));
</script>

<style scoped>
.card-header {
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

.empty-state {
    color: var(--text-tertiary, #94a3b8);
    font-size: 13px;
    padding: 20px 0;
}

.activity-timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    border-left: 2px solid var(--border-color, #e2e8f0);
}

.activity-item {
    position: relative;
    padding: 0 0 20px 20px;
}

.activity-dot {
    position: absolute;
    left: -7px;
    top: 2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--primary-600);
    border: 2px solid #fff;

    &.document_access {
        background: #8b5cf6;
    }
}

.activity-description {
    margin: 0 0 2px;
    font-size: 13px;
    color: var(--text-primary, #0f172a);
}

.activity-time {
    font-size: 11px;
    color: var(--text-tertiary, #94a3b8);
}

.table-pagination {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 16px;

    button {
        padding: 6px 14px;
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 6px;
        background: var(--surface, #f1f5f9);
        font-size: 13px;
        cursor: pointer;

        &:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
    }
}
</style>
