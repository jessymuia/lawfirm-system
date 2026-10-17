<template>
    <div class="login-page">
        <div class="login-card">
            <div class="login-brand">
                <div class="login-logo" aria-hidden="true">{{ product.short }}</div>
                <h1 class="login-title" data-testid="login-product">{{ product.name }}</h1>
                <p v-if="portal === 'firm'" class="login-subtitle" data-testid="login-firm-name">Sign in to your firm's account</p>
                <p v-else class="login-subtitle" data-testid="login-operator-title">Platform operator: firms, billing and platform health</p>
            </div>

            <!-- Firm or operator: one sign-in page, no separate address to remember. -->
            <div v-if="operatorLogin" class="portal-switch" role="tablist" aria-label="Sign in as">
                <button type="button" role="tab" :aria-selected="portal === 'firm'" :class="{ active: portal === 'firm' }" data-testid="portal-firm" @click="setPortal('firm')">Firm</button>
                <button type="button" role="tab" :aria-selected="portal === 'operator'" :class="{ active: portal === 'operator' }" data-testid="portal-operator" @click="setPortal('operator')">Operator</button>
            </div>

            <template v-if="portal === 'firm'">

            <div v-if="finishingSso" class="login-status" role="status">Finishing single sign-on…</div>

            <!-- SEC-12: second step for an account with two-factor on. -->
            <form v-else-if="challenge" class="login-form" novalidate @submit.stop.prevent="submitCode">
                <p class="step-hint">
                    {{ useRecovery
                        ? 'Enter one of the recovery codes you saved when you turned on two-factor authentication. Each works once.'
                        : 'Enter the 6-digit code from your authenticator app.' }}
                </p>
                <div class="field-wrapper">
                    <label for="login-code">{{ useRecovery ? 'Recovery code' : 'Authentication code' }}</label>
                    <input
                        id="login-code"
                        ref="codeInput"
                        v-model="code"
                        type="text"
                        class="form-control code-input"
                        :class="{ 'is-invalid': submitting && !code }"
                        :inputmode="useRecovery ? 'text' : 'numeric'"
                        :autocomplete="useRecovery ? 'off' : 'one-time-code'"
                        :placeholder="useRecovery ? 'xxxxx-xxxxx' : '123456'"
                        :maxlength="useRecovery ? 32 : 8"
                    />
                </div>

                <button type="submit" class="btn-login" :disabled="submitting">
                    {{ submitting ? 'Checking...' : 'Verify' }}
                </button>

                <div class="login-row step-links">
                    <button type="button" class="link-button" @click="toggleRecovery">
                        {{ useRecovery ? 'Use an authenticator code' : 'Use a recovery code' }}
                    </button>
                    <button type="button" class="link-button" @click="startOver">Start again</button>
                </div>
            </form>

            <template v-else>
                <a v-if="sso.available" :href="ssoStartUrl" class="btn-sso" data-testid="sso-button">Sign in with SSO</a>
                <p v-if="sso.enforce" class="step-hint sso-note">
                    Your firm signs in through single sign-on. The password form below is for security administrators only.
                </p>
                <div v-if="sso.available" class="divider"><span>{{ sso.enforce ? 'administrator sign-in' : 'or' }}</span></div>

                <form class="login-form" novalidate @submit.stop.prevent="loginFunc">
                <div class="field-wrapper">
                    <label for="login-email">Email or username</label>
                    <div class="input-with-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <input
                            id="login-email"
                            type="text"
                            v-model="client.email"
                            class="form-control"
                            :class="{ 'is-invalid': submitting && !client.email }"
                            placeholder="Username"
                            autocomplete="username"
                        />
                    </div>
                </div>

                <div class="field-wrapper">
                    <label for="login-password">Password</label>
                    <div class="input-with-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input
                            id="login-password"
                            :type="showPassword ? 'text' : 'password'"
                            v-model="client.password"
                            class="form-control"
                            :class="{ 'is-invalid': submitting && !client.password }"
                            placeholder="Password"
                            autocomplete="current-password"
                        />
                        <button type="button" class="toggle-visibility" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'">
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </div>

                <div class="login-row">
                    <label class="remember-me">
                        <input type="checkbox" v-model="rememberMe" />
                        <span>Keep me logged in</span>
                    </label>
                    <router-link to="/auth/pass-recovery" class="forgot-link">Forgot password?</router-link>
                </div>

                <button type="submit" class="btn-login" :disabled="submitting">
                    {{ submitting ? 'Logging in...' : 'Log In' }}
                </button>
                </form>
            </template>

            </template>

            <!-- Platform operator (super admin): signs in to the control plane, then opens the operator console. -->
            <form v-else class="login-form" data-testid="operator-form" novalidate @submit.stop.prevent="operatorSubmit">
                <template v-if="!opChallenge">
                    <div class="field-wrapper">
                        <label for="operator-email">Work email</label>
                        <div class="input-with-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <input id="operator-email" type="email" v-model="operator.email" class="form-control" :class="{ 'is-invalid': submitting && !operator.email }" placeholder="you@company.co.ke" autocomplete="username" />
                        </div>
                    </div>
                    <div class="field-wrapper">
                        <label for="operator-password">Password</label>
                        <div class="input-with-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                            <input id="operator-password" :type="showPassword ? 'text' : 'password'" v-model="operator.password" class="form-control" :class="{ 'is-invalid': submitting && !operator.password }" placeholder="Password" autocomplete="current-password" />
                            <button type="button" class="toggle-visibility" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'">{{ showPassword ? 'Hide' : 'Show' }}</button>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <p class="step-hint">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
                    <div class="field-wrapper">
                        <label for="operator-code">Authentication code</label>
                        <input id="operator-code" v-model="opCode" type="text" class="form-control code-input" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" maxlength="32" />
                    </div>
                </template>

                <p v-if="opError" class="operator-error" role="alert" data-testid="operator-error">{{ opError }}</p>

                <button type="submit" class="btn-login" :disabled="submitting">
                    {{ submitting ? 'Checking...' : (opChallenge ? 'Verify' : 'Sign in as operator') }}
                </button>
                <div v-if="opChallenge" class="login-row step-links">
                    <button type="button" class="link-button" @click="opStartOver">Start again</button>
                </div>
            </form>

            <p v-if="signupUrl && portal === 'firm'" class="signup-cta" data-testid="signup-link">New to {{ product.name }}? <a :href="signupUrl">Start a free trial</a></p>
            <a v-if="platformLoginUrl" :href="platformLoginUrl" class="operator-login-link">Platform operator sign-in</a>
            <p class="login-footer">© {{ new Date().getFullYear() }} {{ product.name }}. All rights reserved.</p>
        </div>
    </div>
</template>

<script setup>
    import { useRoute } from "vue-router";
    import { useMeta } from '@/composables/use-meta';
    import { nextTick, onMounted, ref } from 'vue';
    import { signInWithPassword, redeemChallenge, finishSso, ssoConfig, landingFor, SSO_START_URL } from '@/composables/use-sign-in';
    useMeta({ title: 'Log In' });

    const route = useRoute();
    const appRoot = document.getElementById('app');
    const signupUrl = appRoot?.dataset.signupUrl || '';
    const platformLoginUrl = appRoot?.dataset.platformLoginUrl || '';
    // The system's own name (not the firm's) and whether the operator can sign in on this very page.
    const product = { name: appRoot?.dataset.productName || 'Law Management System', short: appRoot?.dataset.productShort || 'Law' };
    const operatorLogin = appRoot?.dataset.operatorLogin === '1';
    const portal = ref(operatorLogin && route.query.as === 'operator' ? 'operator' : 'firm');
    const operator = ref({ email: '', password: '' });
    const opChallenge = ref('');
    const opCode = ref('');
    const opError = ref('');

    const submitting = ref(false);
    const showPassword = ref(false);
    const rememberMe = ref(false);
    const client = ref({email: "", password: ""});

    // SEC-12: the second step (two-factor) and single sign-on.
    const challenge = ref(null);
    const code = ref('');
    const useRecovery = ref(false);
    const codeInput = ref(null);
    const sso = ref({ available: false, enforce: false });
    const finishingSso = ref(false);
    const ssoStartUrl = SSO_START_URL;

    // A full page load, not a router push: the app shell reads the signed-in marker once at start-up.
    const goTo = (outcome) => {
        window.location.href = landingFor(outcome, route.query.redirectFrom);
    };

    const setPortal = (which) => {
        portal.value = which;
        opError.value = '';
    };

    const opStartOver = () => {
        opChallenge.value = '';
        opCode.value = '';
        opError.value = '';
    };

    // Plain fetch, not the firm API client: the operator API has its own bearer tokens and error shape.
    const platformPost = async (path, body) => {
        const res = await fetch(`/api/platform${path}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.message || (res.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : 'Could not sign in. Please try again.'));
        }
        return data;
    };

    const openOperatorConsole = (token) => {
        try {
            sessionStorage.setItem('platform-console-token', token);   // the console reads it on load
        } catch (_) {
            // sessionStorage blocked: the console will ask for the sign-in itself
        }
        window.location.assign('/console');
    };

    const operatorSubmit = async () => {
        opError.value = '';
        submitting.value = true;
        try {
            if (opChallenge.value) {
                if (!opCode.value) return;
                const data = await platformPost('/login/mfa', { challenge: opChallenge.value, code: opCode.value.trim() });
                openOperatorConsole(data.token);
                return;
            }
            if (!operator.value.email || !operator.value.password) return;
            const data = await platformPost('/login', { email: operator.value.email.trim(), password: operator.value.password });
            if (data.mfa_required) {
                opChallenge.value = data.challenge;
                operator.value.password = '';
                return;
            }
            openOperatorConsole(data.token);
        } catch (error) {
            opError.value = error.message;
        } finally {
            submitting.value = false;
        }
    };

    const failed = (error, fallback) => {
        const message = error.response?.data?.message || fallback;
        showMessage(message, 'error');
    };

    const loginFunc = () => {
        submitting.value = true;
        if (!client.value.email || !client.value.password) {
            submitting.value = false;
            return;
        }
        signInWithPassword(client.value.email, client.value.password)
            .then((outcome) => {
                if (outcome.challenge) {
                    challenge.value = outcome.challenge;
                    client.value.password = '';
                    nextTick(() => codeInput.value?.focus());
                    return;
                }
                goTo(outcome);
            })
            .catch(error => failed(error, 'Error logging in. Please try again.'))
            .finally(() => {
                submitting.value = false;
            });
    };

    const submitCode = () => {
        submitting.value = true;
        if (!code.value) {
            submitting.value = false;
            return;
        }
        redeemChallenge(challenge.value, code.value)
            .then(goTo)
            .catch(error => {
                // One generic answer covers a wrong code and a spent challenge (five tries or expiry); the step
                // stays open and "Start again" goes back to the password.
                code.value = '';
                failed(error, 'That code is not right. Please try again.');
            })
            .finally(() => {
                submitting.value = false;
            });
    };

    const toggleRecovery = () => {
        useRecovery.value = !useRecovery.value;
        code.value = '';
        nextTick(() => codeInput.value?.focus());
    };

    const startOver = () => {
        challenge.value = null;
        code.value = '';
        useRecovery.value = false;
    };

    onMounted(() => {
        if (route.query.sso === 'complete') {
            finishingSso.value = true;
            finishSso()
                .then(goTo)
                .catch(() => {
                    finishingSso.value = false;
                    showMessage('Single sign-on did not complete. Please try again.', 'error');
                });
            return;
        }
        // UI-16: arrived from another firm, but this firm asks for its own sign-in
        const switchNotes = {
            sso: 'This firm signs people in with single sign-on. Use "Sign in with SSO".',
            mfa: 'This firm asks for your two-factor code: please sign in here.',
            invalid: 'That firm link has expired. Please sign in here, or switch again.',
        };
        if (switchNotes[route.query.switch]) {
            showMessage(switchNotes[route.query.switch], 'info');
        }
        if (route.query.sso_error) {
            showMessage('Single sign-on did not complete. Ask your firm administrator if this keeps happening.', 'error');
        }
        ssoConfig().then((config) => {
            sso.value = config;
        });
    });

    const showMessage = (msg = '', type = 'success') => {
        const toast = window.Swal.mixin({
            toast: true,
            position: 'top',
            showConfirmButton: false,
            timer: 3000,
        });
        toast.fire({
            icon: type,
            title: msg,
            padding: '10px 20px',
        });
    };
</script>

<style lang="scss" scoped>
/* UI-4: purpose-built single-card login layout, replacing the generic
   admin-template split-screen page — placeholder logo mark/colors here
   until the Phase 3 branding pass (design tokens, real firm logo)
   supplies finals; every color below is already a CSS custom property
   so that pass is a token swap, not a rewrite. */
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
    max-width: 400px;
    background: var(--card-bg, #fff);
    border-radius: var(--radius-lg, 16px);
    padding: 40px 36px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
}

.login-logo-img {
    max-height: 64px;
    max-width: 220px;
    margin: 0 auto 12px;
    display: block;
}

.login-brand {
    text-align: center;
    margin-bottom: 28px;
}

.login-logo {
    width: 56px;
    height: 56px;
    margin: 0 auto 16px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 18px;
    letter-spacing: 0.02em;
}

.login-title {
    font-size: 19px;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--text-primary, #0f172a);
}

.login-subtitle {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    margin: 0;
}

.field-wrapper {
    margin-bottom: 18px;

    label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-secondary, #64748b);
        margin-bottom: 6px;
    }
}

.input-with-icon {
    position: relative;
    display: flex;
    align-items: center;

    svg {
        position: absolute;
        left: 12px;
        color: var(--text-tertiary, #94a3b8);
        pointer-events: none;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px 10px 38px;
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: var(--radius-md, 8px);
        font-size: 14px;

        &:focus {
            outline: none;
            border-color: var(--primary-600);
        }

        &.is-invalid {
            border-color: #e7515a;
        }
    }

    .toggle-visibility {
        position: absolute;
        right: 10px;
        background: none;
        border: none;
        font-size: 12px;
        font-weight: 600;
        color: var(--primary-600);
        cursor: pointer;
        padding: 4px 6px;
    }
}

.login-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    margin-bottom: 20px;
}

.remember-me {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-secondary, #64748b);
    cursor: pointer;
}

.forgot-link {
    color: var(--primary-600);
    text-decoration: none;
    font-weight: 600;

    &:hover {
        text-decoration: underline;
    }
}

.signup-cta { text-align: center; margin: 16px 0 0; font-size: 14px; color: var(--text-muted, #64748b); a { font-weight: 600; } }
.operator-login-link {
    display: block;
    margin-top: 18px;
    color: var(--primary-600);
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;

    &:hover {
        text-decoration: underline;
    }
}

.btn-login {
    width: 100%;
    padding: 11px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: #fff;
    border: none;
    border-radius: var(--radius-md, 8px);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;

    &:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }
}

.login-product {
    margin: 0 0 14px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-tertiary, #94a3b8);
}

.login-logo-operator {
    background: linear-gradient(135deg, #0f766e, #134e4a);
}

.portal-switch {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    padding: 4px;
    margin: 0 0 24px;
    background: var(--primary-50, #f1f5f9);
    border-radius: var(--radius-md, 8px);

    button {
        padding: 8px 0;
        border: none;
        border-radius: 6px;
        background: transparent;
        font-size: 13px;
        font-weight: 600;
        color: var(--text-secondary, #64748b);
        cursor: pointer;

        &.active {
            background: #fff;
            color: var(--primary-700);
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15);
        }
    }
}

.operator-error {
    margin: 0 0 14px;
    font-size: 13px;
    color: #b42318;
}

.login-footer {
    margin-top: 24px;
    text-align: center;
    font-size: 11px;
    color: var(--text-tertiary, #94a3b8);
}
.login-status {
    text-align: center;
    color: var(--text-secondary, #64748b);
    padding: 24px 0;
}

.step-hint {
    font-size: 13px;
    color: var(--text-secondary, #64748b);
    margin: 0 0 16px;
}

.sso-note {
    margin-top: 12px;
}

.code-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: var(--radius-md, 8px);
    font-size: 18px;
    letter-spacing: 0.2em;
    text-align: center;

    &:focus {
        outline: none;
        border-color: var(--primary-600);
    }

    &.is-invalid {
        border-color: #e7515a;
    }
}

.step-links {
    margin: 16px 0 0;
}

.link-button {
    background: none;
    border: none;
    padding: 0;
    color: var(--primary-600);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;

    &:hover {
        text-decoration: underline;
    }
}

.btn-sso {
    display: block;
    width: 100%;
    padding: 11px;
    text-align: center;
    border: 1px solid var(--primary-600);
    border-radius: var(--radius-md, 8px);
    color: var(--primary-700);
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;

    &:hover {
        background: var(--primary-50);
    }
}

.divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 20px 0;
    font-size: 12px;
    color: var(--text-tertiary, #94a3b8);

    &::before,
    &::after {
        content: '';
        flex: 1;
        border-top: 1px solid var(--border-color, #e2e8f0);
    }
}
</style>