<template>
    <!-- TEN-11: marks a platform-owned reference row; shows the official name when the firm uses an alias. -->
    <span v-if="row && row.is_system" class="catalog-badge-wrap">
        <span class="catalog-badge" title="Maintained by the platform. You can set a local name, but not rename or delete it.">System</span>
        <span v-if="row.alias" class="catalog-official">{{ row.official_name }}</span>
    </span>
</template>

<script setup>
defineProps({ row: { type: Object, required: true } });
</script>

<style scoped>
.catalog-badge-wrap {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: 8px;
}

.catalog-badge {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: var(--font-size-xs, 11px);
    font-weight: var(--font-weight-semibold, 600);
    background: var(--neutral-100, #f3f4f6);
    color: var(--text-secondary, #4b5563);
    border: 1px solid var(--border-color, #e5e7eb);
}

.catalog-official {
    font-size: var(--font-size-xs, 11px);
    color: var(--text-tertiary, #6b7280);
}
</style>
