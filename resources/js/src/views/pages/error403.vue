<template>
    <div class="layout-px-spacing">
        <div class="error404 text-center">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-4 me-auto mt-5 text-md-start text-center">
                        <router-link to="/" class="ms-md-5">
                            <img alt="image-403" src="@/assets/images/logo2.svg" class="theme-logo" />
                        </router-link>
                    </div>
                </div>
            </div>

            <div class="container-fluid error-content">
                <div class="">
                    <h1 class="error-number">403</h1>
                    <p class="mini-text">Access denied</p>
                    <p class="error-text">You don't have permission to view this page.</p>
                    <p class="mt-2 text-secondary">
                        If you believe you should have access, contact an administrator to request the right permission.
                    </p>
                    <router-link to="/" class="btn btn-secondary mt-5">Go Back</router-link>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
    import '@/assets/sass/pages/error/style-400.scss';

    import { useMeta } from '@/composables/use-meta';
    useMeta({ title: 'Error 403' });
</script>
