<template>
    <div class="skeleton-loader" :style="{ gap: gap + 'px' }">
        <div
            v-for="n in rows"
            :key="n"
            class="skeleton-row"
            :style="{ height: rowHeight + 'px' }"
        ></div>
    </div>
</template>

<script setup>
// UI-3/UI-4: one shared loading placeholder instead of each module either
// rendering nothing or rolling its own skeleton markup while data loads.
defineProps({
    rows: { type: Number, default: 4 },
    rowHeight: { type: Number, default: 48 },
    gap: { type: Number, default: 10 },
});
</script>

<style scoped>
.skeleton-loader {
    display: flex;
    flex-direction: column;
}

.skeleton-row {
    border-radius: var(--radius-md);
    background: linear-gradient(90deg, var(--neutral-200) 25%, var(--neutral-100) 37%, var(--neutral-200) 63%);
    background-size: 400% 100%;
    animation: skeleton-loading 1.4s ease infinite;
}

@keyframes skeleton-loading {
    0% { background-position: 100% 50%; }
    100% { background-position: 0 50%; }
}
</style>
