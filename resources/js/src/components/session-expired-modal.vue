<template>
    <div
        v-if="visible"
        class="session-expired-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="session-expired-title"
    >
        <div class="session-expired-card">
            <h4 id="session-expired-title">Your session has ended</h4>
            <p class="session-expired-copy">
                For your security, you've been logged out. Log back in below to keep
                working — whatever you were doing is still on screen, just re-submit it
                once you're back in.
            </p>

            <form v-if="challenge" @submit.prevent="submitCode">
                <div class="field-wrapper">
                    <label for="session-expired-code">Authenticator or recovery code</label>
                    <input
                        id="session-expired-code"
                        v-model="code"
                        type="text"
                        class="form-control"
                        autocomplete="one-time-code"
                        required
                    />
                </div>

                <p v-if="errorMessage" class="session-expired-error">{{ errorMessage }}</p>

                <div class="session-expired-actions">
                    <button type="button" class="btn-secondary" @click="goToLoginPage">
                        Go to login page
                    </button>
                    <button type="submit" class="btn-primary" :disabled="submitting">
                        {{ submitting ? 'Checking...' : 'Verify' }}
                    </button>
                </div>
            </form>

            <form v-else @submit.prevent="reLogin">
                <div class="field-wrapper">
                    <label for="session-expired-email">Email</label>
                    <input
                        id="session-expired-email"
                        v-model="email"
                        type="text"
                        class="form-control"
                        autocomplete="username"
                        required
                    />
                </div>
                <div class="field-wrapper">
                    <label for="session-expired-password">Password</label>
                    <input
                        id="session-expired-password"
                        v-model="password"
                        type="password"
                        class="form-control"
                        autocomplete="current-password"
                        required
                    />
                </div>

                <p v-if="errorMessage" class="session-expired-error">{{ errorMessage }}</p>

                <div class="session-expired-actions">
                    <button type="button" class="btn-secondary" @click="goToLoginPage">
                        Go to login page
                    </button>
                    <button type="submit" class="btn-primary" :disabled="submitting">
                        {{ submitting ? 'Logging in...' : 'Log in' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
/**
 * UI-25: mounted once in App.vue. Listens for the `auth:expired` event
 * dispatched by the api.js response interceptor on a 401 (outside the
 * login request itself) and offers to re-authenticate without throwing
 * away the current screen. On success it only closes itself — it does
 * not retry whatever request 401'd, and it does not reload the page.
 * The person re-submits their in-progress action themselves; that's an
 * honest, simple contract rather than guessing at replay semantics.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { AUTH_EXPIRED_EVENT } from '@/api';
import { signInWithPassword, redeemChallenge } from '@/composables/use-sign-in';

const visible = ref(false);
const email = ref('');
const password = ref('');
const submitting = ref(false);
const errorMessage = ref('');
// SEC-12: the code step for an account with two-factor on.
const challenge = ref(null);
const code = ref('');

const open = () => {
    // Avoid stacking multiple modals if several requests 401 in a burst.
    if (visible.value) return;
    visible.value = true;
    email.value = '';
    password.value = '';
    errorMessage.value = '';
    challenge.value = null;
    code.value = '';
};

const goToLoginPage = () => {
    window.location.href = '/login';
};

const done = (outcome) => {
    // The firm's policy may now require enrolment: that is a page, not something to do inside a modal.
    if (outcome.mustEnrolMfa) {
        window.location.href = '/auth/two-factor';
        return;
    }
    visible.value = false;
};

const reLogin = () => {
    submitting.value = true;
    errorMessage.value = '';

    signInWithPassword(email.value, password.value)
        .then((outcome) => {
            if (outcome.challenge) {
                challenge.value = outcome.challenge;
                password.value = '';
                return;
            }
            done(outcome);
        })
        .catch((error) => {
            errorMessage.value = error.response?.data?.message || 'Could not log in. Please check your credentials.';
        })
        .finally(() => {
            submitting.value = false;
        });
};

const submitCode = () => {
    submitting.value = true;
    errorMessage.value = '';

    redeemChallenge(challenge.value, code.value)
        .then(done)
        .catch((error) => {
            code.value = '';
            errorMessage.value = error.response?.data?.message || 'That code is not right.';
        })
        .finally(() => {
            submitting.value = false;
        });
};

onMounted(() => {
    window.addEventListener(AUTH_EXPIRED_EVENT, open);
});

onBeforeUnmount(() => {
    window.removeEventListener(AUTH_EXPIRED_EVENT, open);
});
</script>

<style lang="scss" scoped>
.session-expired-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 16px;
}

.session-expired-card {
    background: var(--card-bg, #fff);
    border-radius: var(--radius-lg, 12px);
    padding: 28px;
    width: 100%;
    max-width: 380px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);

    h4 {
        margin: 0 0 8px;
        font-weight: 700;
    }
}

.session-expired-copy {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    margin: 0 0 20px;
}

.field-wrapper {
    margin-bottom: 14px;

    label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 4px;
        color: var(--text-secondary, #64748b);
    }

    .form-control {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 8px;
        font-size: 13px;

        &:focus {
            outline: none;
            border-color: var(--primary-600);
        }
    }
}

.session-expired-error {
    color: #e7515a;
    font-size: 12px;
    margin: -4px 0 14px;
}

.session-expired-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 8px;
}

.btn-secondary {
    padding: 9px 16px;
    background: var(--surface, #f1f5f9);
    color: var(--text-primary, #0f172a);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.btn-primary {
    padding: 9px 18px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}
</style>
