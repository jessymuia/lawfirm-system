<template>
    <div class="empty-state">
        <div class="empty-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="12" y1="18" x2="12" y2="12"></line>
                <line x1="9" y1="15" x2="15" y2="15"></line>
            </svg>
        </div>
        <p class="empty-title">{{ title }}</p>
        <p v-if="description" class="empty-description">{{ description }}</p>
        <button v-if="actionLabel" type="button" class="empty-action" @click="$emit('action')">
            {{ actionLabel }}
        </button>
    </div>
</template>

<script setup>
// UI-3/UI-4: "create your first..." empty-state pattern instead of a
// module silently rendering an empty table with no explanation.
defineProps({
    title: { type: String, default: 'Nothing here yet' },
    description: { type: String, default: '' },
    actionLabel: { type: String, default: '' },
});

defineEmits(['action']);
</script>

<style lang="scss" scoped>
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--text-tertiary);
}

.empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: var(--primary-100);
    color: var(--primary-600);
    margin-bottom: 16px;
}

.empty-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 4px;
}

.empty-description {
    font-size: 13px;
    margin: 0 0 16px;
}

.empty-action {
    padding: 8px 20px;
    background: var(--primary-600);
    color: var(--text-inverse);
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;

    &:hover {
        background: var(--primary-700);
    }
}
</style>
