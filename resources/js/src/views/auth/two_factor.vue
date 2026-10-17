<template>
    <div class="login-page">
        <div class="login-card">
            <h1 class="login-title">Set up two-factor authentication</h1>
            <p class="login-subtitle">Your firm requires a second step at sign-in. This takes about a minute.</p>

            <TwoFactorEnrol @done="finish" />

            <button type="button" class="link-button" @click="signOut">Sign out instead</button>
        </div>
    </div>
</template>

<script setup>
/**
 * SEC-12: where the firm's two-factor policy sends a signed-in person who has not enrolled (the API answers every
 * other request with 403 mfa_enrollment_required until they do). Signing out stays available.
 */
import { useMeta } from '@/composables/use-meta';
import axios from '@/api';
import TwoFactorEnrol from '@/components/two-factor-enrol.vue';

useMeta({ title: 'Two-factor authentication' });

const finish = () => {
    const stored = JSON.parse(localStorage.getItem('user') || 'null');
    window.location.href = stored && stored.isAllowed === false ? '/tasks' : '/';
};

const signOut = () => {
    axios.post('/api/v1/logout').finally(() => {
        navigator.serviceWorker?.controller?.postMessage('signed-out');   // FUN-19
        localStorage.removeItem('user');
        localStorage.removeItem('permissions');
        window.location.href = '/login';
    });
};
</script>

<style lang="scss" scoped>
.login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--primary-800), var(--primary-600));
    padding: 24px;
}

.login-card {
    width: 100%;
    max-width: 460px;
    background: var(--card-bg, #fff);
    border-radius: var(--radius-lg, 16px);
    padding: 36px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
}

.login-title {
    font-size: 19px;
    font-weight: 700;
    margin: 0 0 4px;
}

.login-subtitle {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    margin: 0 0 20px;
}

.link-button {
    margin-top: 20px;
    background: none;
    border: none;
    padding: 0;
    color: var(--primary-600);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
}
</style>
