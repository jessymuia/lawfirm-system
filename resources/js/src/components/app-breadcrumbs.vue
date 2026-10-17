<template>
    <nav v-if="crumbs.length" class="app-breadcrumbs" aria-label="Breadcrumb">
        <ol>
            <li v-for="(crumb, index) in crumbs" :key="index">
                <router-link v-if="crumb.name && index < crumbs.length - 1" :to="{ name: crumb.name }">
                    {{ crumb.label }}
                </router-link>
                <span v-else aria-current="page">{{ crumb.label }}</span>
                <span v-if="index < crumbs.length - 1" class="breadcrumb-sep" aria-hidden="true">/</span>
            </li>
        </ol>
    </nav>
</template>

<script setup>
/**
 * UI-7: reads the `meta.breadcrumb` trail declared on the current route
 * (see router/index.js) so `case → documents → back` doesn't force the
 * browser's back button — each ancestor crumb is a real link. Routes
 * without a breadcrumb trail (dashboards, top-level list pages that
 * don't need one) simply render nothing.
 */
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();

const crumbs = computed(() => route.meta?.breadcrumb ?? []);
</script>

<style lang="scss" scoped>
.app-breadcrumbs {
    margin-bottom: 16px;

    ol {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        font-size: 13px;
    }

    li {
        display: flex;
        align-items: center;
        color: var(--text-tertiary, #94a3b8);
    }

    a {
        color: var(--text-secondary, #64748b);
        text-decoration: none;

        &:hover {
            color: var(--primary-600);
            text-decoration: underline;
        }
    }

    span[aria-current='page'] {
        color: var(--text-primary, #0f172a);
        font-weight: 600;
    }

    .breadcrumb-sep {
        margin: 0 8px;
        color: var(--text-tertiary, #94a3b8);
    }
}
</style>
