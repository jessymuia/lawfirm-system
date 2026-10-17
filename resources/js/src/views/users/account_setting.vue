<template>
    <div class="account-settings-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">My Account</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Settings</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <SkeletonLoader v-if="loading" :rows="4" />

        <div v-else-if="loadError" class="settings-error">
            <p>Couldn't load your account details.</p>
            <button type="button" class="btn-retry" @click="fetchProfile">Retry</button>
        </div>

        <template v-else>
            <div class="elevated-card">
                <div class="card-header">
                    <h2 class="card-title">Profile</h2>
                </div>
                <div class="card-body">
                    <form @submit.prevent="submitProfile">
                        <div class="form-group">
                            <label class="form-label" for="acct-name">Name <span class="required">*</span></label>
                            <input
                                id="acct-name"
                                type="text"
                                v-model="profile.name"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('name') }"
                                @input="clearErrors('name')"
                            />
                            <div class="invalid-feedback" v-if="hasError('name')">{{ fieldError('name') }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="acct-email">Email <span class="required">*</span></label>
                            <input
                                id="acct-email"
                                type="email"
                                v-model="profile.email"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('email') }"
                                @input="clearErrors('email')"
                            />
                            <div class="invalid-feedback" v-if="hasError('email')">{{ fieldError('email') }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="acct-phone">Phone Number</label>
                            <input
                                id="acct-phone"
                                type="text"
                                v-model="profile.phone_number"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('phone_number') }"
                                @input="clearErrors('phone_number')"
                            />
                            <div class="invalid-feedback" v-if="hasError('phone_number')">{{ fieldError('phone_number') }}</div>
                        </div>

                        <button type="submit" class="btn-submit" :disabled="savingProfile">
                            {{ savingProfile ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="elevated-card">
                <div class="card-header">
                    <h2 class="card-title">Change Password</h2>
                </div>
                <div class="card-body">
                    <form @submit.prevent="submitPassword">
                        <div class="form-group">
                            <label class="form-label" for="acct-current-password">Current Password <span class="required">*</span></label>
                            <input
                                id="acct-current-password"
                                type="password"
                                v-model="passwordForm.current_password"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('current_password') }"
                                @input="clearErrors('current_password')"
                            />
                            <div class="invalid-feedback" v-if="hasError('current_password')">{{ fieldError('current_password') }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="acct-new-password">New Password <span class="required">*</span></label>
                            <input
                                id="acct-new-password"
                                type="password"
                                v-model="passwordForm.new_password"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('new_password') }"
                                @input="clearErrors('new_password')"
                            />
                            <div class="invalid-feedback" v-if="hasError('new_password')">{{ fieldError('new_password') }}</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="acct-confirm-password">Confirm New Password <span class="required">*</span></label>
                            <input
                                id="acct-confirm-password"
                                type="password"
                                v-model="passwordForm.new_password_confirmation"
                                class="form-control"
                            />
                        </div>

                        <button type="submit" class="btn-submit" :disabled="savingPassword">
                            {{ savingPassword ? 'Updating...' : 'Update Password' }}
                        </button>
                    </form>
                </div>
            </div>

            <TwoFactorSettings />
        </template>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from '../../api';
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useFormErrors } from '@/composables/use-form-errors';
import SkeletonLoader from '@/components/ui/skeleton-loader.vue';
import TwoFactorSettings from '@/components/two-factor-settings.vue';

/**
 * Replaces what was, in its entirety, leftover case-detail-viewing
 * markup (case fields, a documents table, a hearings table) — this file
 * had nothing to do with account settings despite being the exact page
 * linked from the header's "My Account" dropdown. See the new
 * ProfileController for the backend this page actually needed and
 * never had.
 */
useMeta({ title: 'Account Settings' });

const { showMessage } = useToast();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const loading = ref(true);
const loadError = ref(false);
const savingProfile = ref(false);
const savingPassword = ref(false);

const profile = ref({ name: '', email: '', phone_number: '' });
const passwordForm = ref({ current_password: '', new_password: '', new_password_confirmation: '' });

const fetchProfile = () => {
    loading.value = true;
    loadError.value = false;

    axios.get('/api/v1/profile')
        .then(response => {
            const data = response.data.data;
            profile.value = { name: data.name, email: data.email, phone_number: data.phone_number };
        })
        .catch(error => {
            console.error('Error fetching profile:', error);
            loadError.value = true;
        })
        .finally(() => {
            loading.value = false;
        });
};

const submitProfile = () => {
    savingProfile.value = true;
    axios.put('/api/v1/profile', profile.value)
        .then(() => {
            showMessage('Profile updated successfully.');
        })
        .catch(error => {
            showMessage(setErrorsFromResponse(error, 'Error updating profile. Please try again.'), 'error');
        })
        .finally(() => {
            savingProfile.value = false;
        });
};

const submitPassword = () => {
    savingPassword.value = true;
    axios.put('/api/v1/profile/password', passwordForm.value)
        .then(() => {
            showMessage('Password updated successfully.');
            passwordForm.value = { current_password: '', new_password: '', new_password_confirmation: '' };
        })
        .catch(error => {
            showMessage(setErrorsFromResponse(error, 'Error updating password. Please try again.'), 'error');
        })
        .finally(() => {
            savingPassword.value = false;
        });
};

onMounted(() => fetchProfile());
</script>

<style lang="scss" scoped>
.account-settings-page {
    padding: 20px;
    max-width: 600px;
}

.settings-error {
    text-align: center;
    padding: 40px 20px;
    color: #6b7280;
}

.btn-retry {
    margin-top: 12px;
    padding: 8px 20px;
    border-radius: 8px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    background: white;
    cursor: pointer;
}

.elevated-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
}

.card-header {
    padding: 20px 24px;
    border-bottom: 1px solid #f1f5f9;
}

.card-title {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
}

.card-body {
    padding: 24px;
}

.form-group {
    margin-bottom: 18px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 6px;

    .required {
        color: #ef4444;
    }
}

.invalid-feedback {
    display: block;
    font-size: 12px;
    color: #ef4444;
    margin-top: 6px;
}

.btn-submit {
    padding: 10px 24px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;

    &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
}
</style>
