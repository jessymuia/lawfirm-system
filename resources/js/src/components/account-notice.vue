<template>
    <div v-if="notice" class="account-notice" :class="{ 'is-read-only': notice.readOnly }" role="status" data-testid="account-notice">
        <span class="message">{{ notice.message }}</span>
        <router-link v-if="canChoosePlan" to="/settings/subscription" class="action">Choose a plan</router-link>
        <button v-if="!notice.readOnly" type="button" class="close" aria-label="Dismiss" @click="dismissed = notice.message">×</button>
    </div>
</template>

<script setup>
/**
 * PLAT-2 / PLAT-13: one banner for "your trial ends soon", "your trial ended" and an expired license. It appears from
 * the X-License-Notice header any API response carries, or from a write the API refused as read-only. A read-only
 * notice cannot be dismissed; a warning can, until its text changes.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ACCOUNT_NOTICE_EVENT } from '@/api';

const current = ref(null);
const dismissed = ref(null);

const permissions = JSON.parse(localStorage.getItem('permissions') || '[]');
const firmBilling = document.getElementById('app')?.dataset.firmBilling === '1';
const canChoosePlan = computed(() => firmBilling && permissions.includes('manage-subscription') && /trial/i.test(current.value?.message || ''));
const notice = computed(() => (current.value && current.value.message !== dismissed.value ? current.value : null));

const onNotice = (e) => {
    // a read-only answer outranks a warning until the page reloads
    if (current.value?.readOnly && !e.detail.readOnly) return;
    current.value = e.detail;
};

onMounted(() => window.addEventListener(ACCOUNT_NOTICE_EVENT, onNotice));
onBeforeUnmount(() => window.removeEventListener(ACCOUNT_NOTICE_EVENT, onNotice));
</script>

<style lang="scss" scoped>
.account-notice {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    margin-bottom: 12px;
    border-radius: var(--radius-md, 8px);
    background: #fef9c3;
    color: #713f12;
    font-size: 14px;

    &.is-read-only {
        background: #fee2e2;
        color: #7f1d1d;
    }
}

.message {
    flex: 1;
}

.action {
    font-weight: 600;
    color: inherit;
    text-decoration: underline;
    white-space: nowrap;
}

.close {
    background: none;
    border: none;
    font-size: 18px;
    line-height: 1;
    color: inherit;
    cursor: pointer;
}
</style>
