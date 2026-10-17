<template>
    <div class="security-settings-page">
        <PageHeader title="Security" subtitle="Two-factor sign-in and single sign-on for everyone in the firm." icon="M7 11V8a5 5 0 0110 0v3M5 11h14v10H5z" />
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Settings</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Security</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="elevated-card" data-testid="mfa-policy-card">
            <div class="card-header">
                <h2 class="card-title">Two-Factor Authentication Policy</h2>
            </div>
            <div class="card-body">
                <p v-if="!policy" class="hint">Loading…</p>
                <template v-else>
                    <label class="toggle-row">
                        <input type="checkbox" :checked="policy.require_mfa" :disabled="savingPolicy" @change="setRequireMfa($event.target.checked)" />
                        <span>Require two-factor authentication for everyone in the firm</span>
                    </label>
                    <p class="hint">
                        People without it are asked to set it up at their next sign-in and can do nothing else until they
                        have. {{ policy.users_without_mfa }} {{ policy.users_without_mfa === 1 ? 'person has' : 'people have' }} not enrolled yet.
                        Turn it on for your own account first (Account Settings), so the policy cannot lock you out.
                    </p>
                </template>
            </div>
        </div>

        <div class="elevated-card" data-testid="sso-card">
            <div class="card-header">
                <h2 class="card-title">Single Sign-On</h2>
            </div>
            <div class="card-body">
                <p v-if="ssoState === 'loading'" class="hint">Loading…</p>
                <p v-else-if="ssoState === 'not-in-plan'" class="hint">
                    Single sign-on is not included in your firm's plan. Upgrade to connect Microsoft Entra ID, Google
                    Workspace, Okta or another OpenID Connect or SAML provider.
                </p>
                <form v-else @submit.prevent="saveSso">
                    <div class="protocol-switch" role="radiogroup" aria-label="Protocol">
                        <label><input v-model="sso.protocol" type="radio" value="oidc" data-testid="proto-oidc" /> OpenID Connect</label>
                        <label><input v-model="sso.protocol" type="radio" value="saml" data-testid="proto-saml" /> SAML 2.0</label>
                    </div>

                    <template v-if="sso.protocol === 'oidc'">
                        <p class="hint">
                            Register this application with your identity provider using the redirect URI
                            <code>{{ callbackUrl }}</code>, then enter its details here.
                        </p>
                        <div class="form-group">
                            <label class="form-label" for="sso-issuer">Issuer URL <span class="required">*</span></label>
                            <input id="sso-issuer" v-model="sso.issuer" type="url" class="form-control" placeholder="https://login.microsoftonline.com/<tenant>/v2.0"
                                :class="{ 'is-invalid': hasError('issuer') }" @input="clearErrors('issuer')" />
                            <div class="invalid-feedback" v-if="hasError('issuer')">{{ fieldError('issuer') }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="sso-client">Client ID <span class="required">*</span></label>
                            <input id="sso-client" v-model="sso.client_id" type="text" class="form-control"
                                :class="{ 'is-invalid': hasError('client_id') }" @input="clearErrors('client_id')" />
                            <div class="invalid-feedback" v-if="hasError('client_id')">{{ fieldError('client_id') }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="sso-secret">Client secret</label>
                            <input id="sso-secret" v-model="sso.client_secret" type="password" class="form-control" autocomplete="new-password"
                                :placeholder="configured ? 'Stored. Leave blank to keep it.' : ''"
                                :class="{ 'is-invalid': hasError('client_secret') }" @input="clearErrors('client_secret')" />
                            <div class="invalid-feedback" v-if="hasError('client_secret')">{{ fieldError('client_secret') }}</div>
                        </div>
                    </template>

                    <template v-else>
                        <p class="hint">Give your identity provider these two values (or the <a :href="metadataUrl" target="_blank" rel="noopener">metadata file</a>), then enter what it gives you.</p>
                        <dl class="sp-values">
                            <dt>Entity ID (audience)</dt><dd><code data-testid="sp-entity">{{ metadataUrl }}</code></dd>
                            <dt>Reply URL (ACS)</dt><dd><code data-testid="sp-acs">{{ acsUrl }}</code></dd>
                        </dl>
                        <div class="form-group">
                            <label class="form-label" for="saml-entity">Identity provider entity ID <span class="required">*</span></label>
                            <input id="saml-entity" v-model="sso.issuer" type="text" class="form-control" placeholder="https://sts.windows.net/<tenant>/"
                                :class="{ 'is-invalid': hasError('issuer') }" @input="clearErrors('issuer')" />
                            <div class="invalid-feedback" v-if="hasError('issuer')">{{ fieldError('issuer') }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="saml-url">Identity provider sign-in URL <span class="required">*</span></label>
                            <input id="saml-url" v-model="sso.saml_sso_url" type="url" class="form-control" placeholder="https://login.microsoftonline.com/<tenant>/saml2"
                                :class="{ 'is-invalid': hasError('saml_sso_url') }" @input="clearErrors('saml_sso_url')" />
                            <div class="invalid-feedback" v-if="hasError('saml_sso_url')">{{ fieldError('saml_sso_url') }}</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="saml-cert">Signing certificate (PEM)</label>
                            <textarea id="saml-cert" v-model="sso.saml_certificate" rows="5" class="form-control" spellcheck="false"
                                :placeholder="sso.has_certificate ? 'Stored. Paste a new one (or two, while your provider rolls its key) to replace it.' : '-----BEGIN CERTIFICATE-----'" />
                            <small class="hint">Responses must be signed with this certificate. Anything else is refused.</small>
                        </div>
                    </template>
                    <div class="form-group">
                        <label class="form-label" for="sso-domains">Allowed email domains</label>
                        <input id="sso-domains" v-model="domains" type="text" class="form-control" placeholder="example.co.ke, example.com" />
                        <small class="hint">Comma-separated. Only people with these email domains can sign in through SSO; blank allows any account that matches a colleague.</small>
                    </div>
                    <label class="toggle-row"><input v-model="sso.enabled" type="checkbox" /> <span>Offer "Sign in with SSO" on the login page</span></label>
                    <label class="toggle-row"><input v-model="sso.enforce" type="checkbox" /> <span>Require SSO (passwords then work for security administrators only)</span></label>
                    <label class="toggle-row"><input v-model="sso.idp_enforces_mfa" type="checkbox" /> <span>Our identity provider already requires a second factor (SSO sign-ins satisfy the two-factor policy)</span></label>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary" :disabled="savingSso">{{ savingSso ? 'Saving...' : 'Save' }}</button>
                        <button v-if="configured" type="button" class="btn btn-outline-danger" :disabled="savingSso" @click="removeSso">Remove SSO</button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="ssoState === 'ready'" class="elevated-card" data-testid="scim-card">
            <div class="card-header">
                <h2 class="card-title">User provisioning (SCIM)</h2>
            </div>
            <div class="card-body">
                <p class="hint">
                    Let your identity provider add, rename and deactivate people here automatically. Point it at
                    <code data-testid="scim-url">{{ scim.base_url || scimUrl }}</code> and give it a token below. New people get the role you choose.
                    It cannot change email addresses, and it cannot deactivate the last security administrator.
                </p>
                <p v-if="newToken" class="alert-token" data-testid="scim-new-token">
                    Copy this token now, it is not shown again:<br /><code>{{ newToken }}</code>
                </p>
                <form class="token-form" @submit.prevent="createToken">
                    <input v-model="tokenName" class="form-control" maxlength="100" placeholder="Name, e.g. Okta" required aria-label="Token name" />
                    <select v-model="tokenRole" class="form-control" aria-label="Role for new people">
                        <option :value="null">No role (they get access later)</option>
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                    <button type="submit" class="btn btn-primary" :disabled="creatingToken">Create token</button>
                </form>
                <ul class="token-list">
                    <li v-for="t in scim.data" :key="t.id">
                        <span><strong>{{ t.name }}</strong> <code>{{ t.prefix }}…</code>
                            <small class="hint"> · {{ t.revoked_at ? 'revoked' : (t.last_used_at ? 'last used ' + t.last_used_at : 'never used') }}</small></span>
                        <button v-if="!t.revoked_at" type="button" class="btn btn-outline-danger btn-sm" @click="revokeToken(t)">Revoke</button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script setup>
import PageHeader from '@/components/ui/page-header.vue';
import { confirmAction } from '@/composables/use-confirm';
/** SEC-12: the firm's security settings (manage-security-settings): the two-factor policy and its OIDC provider. */
import { computed, onMounted, ref } from 'vue';
import axios from '@/api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useFormErrors } from '@/composables/use-form-errors';

useMeta({ title: 'Security Settings' });

const { showMessage } = useToast();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const policy = ref(null);
const savingPolicy = ref(false);

const blankSso = () => ({ protocol: 'oidc', issuer: '', client_id: '', client_secret: '', saml_sso_url: '', saml_certificate: '', has_certificate: false, enabled: true, enforce: false, idp_enforces_mfa: false });
const sso = ref(blankSso());
const domains = ref('');
const configured = ref(false);
const ssoState = ref('loading');
const savingSso = ref(false);
const callbackUrl = computed(() => `${window.location.origin}/api/v1/sso/callback`);
const metadataUrl = computed(() => `${window.location.origin}/api/v1/sso/saml/metadata`);
const acsUrl = computed(() => `${window.location.origin}/api/v1/sso/saml/acs`);
const scimUrl = computed(() => `${window.location.origin}/api/v1/scim/v2`);
const scim = ref({ data: [], base_url: '' });
const roles = ref([]);
const tokenName = ref('');
const tokenRole = ref(null);
const newToken = ref('');
const creatingToken = ref(false);

const loadPolicy = () => axios.get('/api/v1/security/policy').then(({ data }) => {
    policy.value = data.data;
});

const setRequireMfa = async (value) => {
    if (!(await confirmAction({
        title: value ? 'Require two-factor sign-in for everyone?' : 'Make two-factor optional?',
        text: value ? 'Every user will have to set up an authenticator app the next time they sign in.' : 'Users will no longer be forced to use a second step when signing in. This weakens account security.',
        confirmText: value ? 'Require two-factor' : 'Make optional', danger: !value,
    }))) { loadPolicy(); return; }
    savingPolicy.value = true;
    axios.put('/api/v1/security/policy', { require_mfa: value })
        .then(() => {
            showMessage(value ? 'Two-factor authentication is now required.' : 'Two-factor authentication is now optional.');
        })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not change the policy.', 'error'))
        .finally(() => {
            savingPolicy.value = false;
            loadPolicy();
        });
};

const applySso = (view) => {
    configured.value = !!view;
    sso.value = view ? { ...blankSso(), ...view, client_secret: '', saml_certificate: '', saml_sso_url: view.saml_sso_url || '' } : blankSso();
    domains.value = view ? (view.allowed_email_domains || []).join(', ') : '';
};

const loadSso = () => axios.get('/api/v1/security/sso', { handles403: true })
    .then(({ data }) => {
        applySso(data.data);
        ssoState.value = 'ready';
    })
    .catch((e) => {
        ssoState.value = e.response?.data?.code === 'entitlement_required' ? 'not-in-plan' : 'ready';
    });

const saveSso = () => {
    savingSso.value = true;
    const saml = sso.value.protocol === 'saml';
    const body = {
        protocol: sso.value.protocol,
        issuer: sso.value.issuer,
        ...(saml ? { saml_sso_url: sso.value.saml_sso_url } : { client_id: sso.value.client_id }),
        enabled: sso.value.enabled,
        enforce: sso.value.enforce,
        idp_enforces_mfa: sso.value.idp_enforces_mfa,
        allowed_email_domains: domains.value.split(',').map((d) => d.trim()).filter(Boolean),
    };
    if (!saml && sso.value.client_secret) body.client_secret = sso.value.client_secret;
    if (saml && sso.value.saml_certificate) body.saml_certificate = sso.value.saml_certificate;
    axios.put('/api/v1/security/sso', body, { handles403: true })
        .then(({ data }) => {
            applySso(data.data);
            showMessage('Single sign-on saved.');
        })
        .catch((e) => showMessage(setErrorsFromResponse(e, 'Could not save single sign-on.'), 'error'))
        .finally(() => {
            savingSso.value = false;
        });
};

const removeSso = async () => {
    if (!(await confirmAction({ title: 'Remove single sign-on?', text: 'People will go back to signing in with their email and password.', confirmText: 'Remove single sign-on', danger: true }))) return;
    savingSso.value = true;
    axios.delete('/api/v1/security/sso')
        .then(() => {
            applySso(null);
            showMessage('Single sign-on removed.');
        })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not remove single sign-on.', 'error'))
        .finally(() => {
            savingSso.value = false;
        });
};

const loadScim = () => axios.get('/api/v1/security/scim-tokens', { handles403: true })
    .then(({ data }) => { scim.value = data; })
    .catch(() => { scim.value = { data: [], base_url: '' }; });

const loadRoles = () => axios.get('/api/v1/rolesDropDown', { handles403: true })
    .then(({ data }) => { roles.value = data.data || []; })
    .catch(() => { roles.value = []; });

const createToken = () => {
    creatingToken.value = true;
    axios.post('/api/v1/security/scim-tokens', { name: tokenName.value, default_role_id: tokenRole.value })
        .then(({ data }) => {
            newToken.value = data.data.token;
            tokenName.value = '';
            showMessage('Token created.');
            loadScim();
        })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not create the token.', 'error'))
        .finally(() => { creatingToken.value = false; });
};

const revokeToken = async (t) => {
    if (!(await confirmAction({ title: `Revoke "${t.name}"?`, text: 'The identity provider stops being able to add or change people. Nobody is signed out or deactivated.', confirmText: 'Revoke token', danger: true }))) return;
    axios.delete(`/api/v1/security/scim-tokens/${t.id}`)
        .then(() => { showMessage('Token revoked.'); loadScim(); })
        .catch((e) => showMessage(e.response?.data?.message || 'Could not revoke the token.', 'error'));
};

onMounted(() => {
    loadPolicy();
    loadSso().then(() => { if (ssoState.value === 'ready') { loadScim(); loadRoles(); } });
});
</script>

<style lang="scss" scoped>
.protocol-switch { display: flex; gap: 20px; margin-bottom: 12px; font-size: 14px; }
.protocol-switch label { display: flex; gap: 6px; align-items: center; cursor: pointer; }
.sp-values { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; font-size: 13px; margin: 8px 0 14px; }
.sp-values dt { color: var(--text-secondary); }
.sp-values dd { margin: 0; overflow-wrap: anywhere; }
.token-form { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.token-form .form-control { flex: 1 1 180px; min-width: 0; }
.token-list { list-style: none; padding: 0; margin: 0; }
.token-list li { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 0; border-top: 1px solid var(--border-color); flex-wrap: wrap; }
.alert-token { padding: 10px 12px; border-radius: 8px; background: var(--primary-50); font-size: 13px; overflow-wrap: anywhere; }

.hint {
    font-size: 13px;
    color: var(--text-secondary);
}

.toggle-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 14px;
    margin-bottom: 10px;
    cursor: pointer;

    input {
        margin-top: 3px;
    }
}

.actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

code {
    word-break: break-all;
}
</style>
