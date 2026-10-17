<template>
    <div class="two-factor-enrol">
        <div v-if="stage === 'start'">
            <p class="hint">
                Two-factor authentication asks for a code from an authenticator app (Google Authenticator, Microsoft
                Authenticator, 1Password and the like) each time you sign in, so a stolen password alone is not enough.
            </p>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="begin" data-testid="mfa-begin">
                {{ busy ? 'Preparing...' : 'Set up two-factor authentication' }}
            </button>
        </div>

        <div v-else-if="stage === 'scan'">
            <ol class="steps">
                <li>Scan this code with your authenticator app.</li>
                <li>Enter the 6-digit code it shows to confirm.</li>
            </ol>
            <div class="qr-wrap">
                <img v-if="qr" :src="qr" alt="QR code for your authenticator app" width="200" height="200" />
            </div>
            <p class="hint">
                Can't scan it? Enter this key instead:
                <code class="secret" data-testid="mfa-secret">{{ groupedSecret }}</code>
            </p>
            <form class="confirm-row" @submit.prevent="confirm">
                <input
                    v-model="code"
                    type="text"
                    class="form-control"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="8"
                    placeholder="123456"
                    aria-label="Code from your authenticator app"
                    data-testid="mfa-confirm-code"
                />
                <button type="submit" class="btn btn-primary" :disabled="busy || !code">
                    {{ busy ? 'Checking...' : 'Turn on' }}
                </button>
            </form>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
        </div>

        <div v-else-if="stage === 'codes'">
            <p class="hint">
                Two-factor authentication is on. Save these recovery codes somewhere safe (a password manager, or
                printed and filed). Each one signs you in once if you lose your phone. They are not shown again.
            </p>
            <ul class="codes" data-testid="mfa-recovery-codes">
                <li v-for="c in recoveryCodes" :key="c"><code>{{ c }}</code></li>
            </ul>
            <div class="code-actions">
                <button type="button" class="btn btn-outline-primary" @click="copyCodes">{{ copied ? 'Copied' : 'Copy' }}</button>
                <button type="button" class="btn btn-outline-primary" @click="downloadCodes">Download</button>
                <button type="button" class="btn btn-primary" @click="$emit('done')" data-testid="mfa-done">I have saved them</button>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * SEC-12: enrolment in two-factor authentication, used by the enrolment page a firm's policy sends people to and by
 * Account Settings. setup → scan (QR of the otpauth:// URI, rendered locally; the secret never leaves the browser
 * for a third-party QR service) → confirm with a first code → show the recovery codes once.
 */
import { computed, ref } from 'vue';
import QRCode from 'qrcode';
import axios from '@/api';

defineEmits(['done']);

const stage = ref('start');
const busy = ref(false);
const error = ref('');
const secret = ref('');
const qr = ref('');
const code = ref('');
const recoveryCodes = ref([]);
const copied = ref(false);

const groupedSecret = computed(() => secret.value.replace(/(.{4})/g, '$1 ').trim());

const message = (e, fallback) => e.response?.data?.message || fallback;

const begin = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post('/api/v1/mfa/setup');
        secret.value = data.data.secret;
        qr.value = await QRCode.toDataURL(data.data.uri, { width: 200, margin: 1 });
        stage.value = 'scan';
    } catch (e) {
        error.value = message(e, 'Could not start setup. Please try again.');
        window.Swal?.fire({ icon: 'error', title: error.value });
    } finally {
        busy.value = false;
    }
};

const confirm = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post('/api/v1/mfa/enable', { code: code.value.replace(/\s+/g, '') });
        recoveryCodes.value = data.data.recovery_codes;
        secret.value = '';
        qr.value = '';
        stage.value = 'codes';
    } catch (e) {
        error.value = message(e, 'That code is not right. Try the next one your app shows.');
        code.value = '';
    } finally {
        busy.value = false;
    }
};

const codesText = () => recoveryCodes.value.join('\n') + '\n';

const copyCodes = async () => {
    try {
        await navigator.clipboard.writeText(codesText());
        copied.value = true;
    } catch {
        copied.value = false;
    }
};

const downloadCodes = () => {
    const url = URL.createObjectURL(new Blob([codesText()], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
};
</script>

<style lang="scss" scoped>
.hint {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
}

.steps {
    font-size: 13px;
    padding-left: 18px;
}

.qr-wrap {
    display: flex;
    justify-content: center;
    margin: 12px 0;
    min-height: 200px;
}

.secret {
    display: inline-block;
    margin-top: 4px;
    font-size: 13px;
    word-break: break-all;
}

.confirm-row {
    display: flex;
    gap: 8px;

    .form-control {
        max-width: 160px;
        letter-spacing: 0.15em;
    }
}

.error {
    color: #e7515a;
    font-size: 13px;
    margin-top: 8px;
}

.codes {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px 16px;
    list-style: none;
    padding: 12px 16px;
    background: var(--surface-muted, #f8fafc);
    border-radius: var(--radius-md, 8px);
    font-size: 14px;
}

.code-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
</style>
