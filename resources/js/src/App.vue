<template>
    <div :class="[app.layout_style, app.menu_style]">
        <component v-bind:is="layout"></component>
        <SessionExpiredModal />
        <PwaInstall />
    </div>
</template>
<script setup>
    import { computed } from 'vue';

    import '@/assets/sass/app.scss';

    import { useMeta } from '@/composables/use-meta';
    import { useAppStore } from '@/stores/app';
    import SessionExpiredModal from './components/session-expired-modal.vue';
    import PwaInstall from './components/pwa-install.vue';

    useMeta({ title: 'Sales Admin' });

    const app = useAppStore();

    const layout = computed(() => {
        return app.layout;
    });
</script>
<script>
    // layouts
    import appLayout from './layouts/app-layout.vue';
    import authLayout from './layouts/auth-layout.vue';

    export default {
        components: {
            app: appLayout,
            auth: authLayout,
        },
    };
</script>
