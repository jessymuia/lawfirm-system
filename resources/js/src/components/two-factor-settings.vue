<template>
    <div class="elevated-card" data-testid="two-factor-card">
        <div class="card-header">
            <h2 class="card-title">Two-Factor Authentication</h2>
            <span v-if="status" class="badge" :class="status.enabled ? 'badge-on' : 'badge-off'">{{ status.enabled ? 'On' : 'Off' }}</span>
        </div>
        <div class="card-body">
            <p v-if="!status" class="hint">Loading…</p>

            <template v-else-if="!status.enabled">
                <p v-if="status.required_by_firm" class="hint">Your firm requires two-factor authentication.</p>
                <TwoFactorEnrol @done="load" />
            </template>

            <template v-else>
                <p class="hint">
                    You are asked for a code from your authenticator app at each sign-in.
                    {{ status.recovery_codes_left }} recovery code{{ status.recovery_codes_left === 1 ? '' : 's' }} left.
                </p>

                <ul v-if="newCodes.length" class="codes" data-testid="mfa-new-codes">
                    <li v-for="c in newCodes" :key="c"><code>{{ c }}</code></li>
                </ul>

                <!-- Both actions prove the person is who the session says: their password and a current code. -->
                <form class="confirm-form" @submit.prevent>
                    <div class="form-group">
                        <label class="form-label" for="mfa-password">Password</label>
                        <input id="mfa-password" v-model="form.password" type="password" class="form-control" autocomplete="current-password" />
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mfa-code">Authenticator or recovery code</label>
                        <input id="mfa-code" v-model="form.code" type="text" class="form-control" autocomplete="one-time-code" />
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-outline-primary" :disabled="busy || !ready" @click="regenerate">New recovery codes</button>
                        <button
                            v-if="!status.required_by_firm"
                            type="button"
                            class="btn btn-outline-danger"
                            :disabled="busy || !ready"
                            @click="disable"
                        >Turn off</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</template>

<script setup>
/** SEC-12: the signed-in person's own second factor: enrol, see what is left, rotate recovery codes, turn it off. */
import { computed, onMounted, ref } from 'vue';
import axios from '@/api';
import { useToast } from '@/composables/use-toast';
import TwoFactorEnrol from '@/components/two-factor-enrol.vue';

const { showMessage } = useToast();

const status = ref(null);
const busy = ref(false);
const form = ref({ password: '', code: '' });
const newCodes = ref([]);
const ready = computed(() => form.value.password && form.value.code);

const load = () => axios.get('/api/v1/mfa').then(({ data }) => {
    status.value = data.data;
});

const run = async (url, after) => {
    busy.value = true;
    try {
        const { data } = await axios.post(url, { password: form.value.password, code: form.value.code.replace(/\s+/g, '') });
        showMessage(data.message);
        after(data.data);
        await load();
    } catch (e) {
        showMessage(e.response?.data?.message || 'That did not work. Please try again.', 'error');
    } finally {
        form.value = { password: '', code: '' };
        busy.value = false;
    }
};

const regenerate = () => run('/api/v1/mfa/recovery-codes', (d) => {
    newCodes.value = d.recovery_codes;
});
const disable = () => run('/api/v1/mfa/disable', () => {
    newCodes.value = [];
});

onMounted(load);
</script>

<style lang="scss" scoped>
.card-header {
    display: flex;
    align-items: center;
    gap: 10px;
}

.badge-on {
    background: #dcfce7;
    color: #166534;
}

.badge-off {
    background: #f1f5f9;
    color: #475569;
}

.hint {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.codes {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px 16px;
    list-style: none;
    padding: 12px 16px;
    background: var(--surface-muted, #f8fafc);
    border-radius: var(--radius-md, 8px);
}

.confirm-form {
    max-width: 360px;
}

.actions {
    display: flex;
    gap: 8px;
}
</style>
