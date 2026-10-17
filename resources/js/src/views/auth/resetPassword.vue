<template>
    <div class="form full-form auth-cover">
        <div class="form-container">
            <div class="form-form">
                <div class="form-form-wrap">
                    <div class="form-container">
                        <div class="form-content">
                            <h1 class="">Password Recovery</h1>
                            <p class="signup-link">Enter your email and instructions will sent to you!</p>
                            <form class="text-start" @submit.prevent="passRecFunc">
                                <div class="form">
                                    <div id="password-field" class="field-wrapper input">
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            class="feather feather-at-sign"
                                        >
                                            <circle cx="12" cy="12" r="4"></circle>
                                            <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"></path>
                                        </svg>
                                        <input
                                            v-model="client.password"
                                            type="password"
                                            class="form-control"
                                            :class="{ 'is-invalid': hasError('password') }"
                                            placeholder="Password"
                                            @input="clearErrors('password')"
                                        />
                                        <div class="invalid-feedback" v-if="hasError('password')">{{ fieldError('password') }}</div>
                                    </div>
                                    <div id="cpassowrd-field" class="field-wrapper input">
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            class="feather feather-at-sign"
                                        >
                                            <circle cx="12" cy="12" r="4"></circle>
                                            <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"></path>
                                        </svg>
                                        <input
                                            v-model="client.password_confirmation"
                                            type="password"
                                            class="form-control"
                                            :class="{ 'is-invalid': hasError('password_confirmation') }"
                                            placeholder="Confirm Password"
                                            @input="clearErrors('password_confirmation')"
                                        />
                                        <div class="invalid-feedback" v-if="hasError('password_confirmation')">{{ fieldError('password_confirmation') }}</div>
                                    </div>
                                    <div class="d-sm-flex justify-content-between">
                                        <div class="field-wrapper">
                                            <button type="submit" class="btn btn-primary">Reset</button>
                                        </div>
                                        <div class="field-wrapper">
                                            <router-link to="/login">Go To Login</router-link>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            <p class="terms-conditions">
                                © 2024 All Rights Reserved.
<!--                                <router-link to="/">CORK</router-link> is a product of Arrangic Solutions LLP. <a href="javascript:void(0);">Cookie Preferences</a>,-->
<!--                                <a href="javascript:void(0);">Privacy</a>, and <a href="javascript:void(0);">Terms</a>.-->
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-image">
                <div class="l-image"></div>
            </div>
        </div>
    </div>
</template>

<script setup>
import '@/assets/sass/authentication/auth.scss';

import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useFormErrors } from '@/composables/use-form-errors';
import axios from "../../api";
import {ref} from "vue";
import { useRoute, useRouter } from 'vue-router';
useMeta({ title: 'Password Recovery Cover' });

const { showMessage } = useToast();
const { errors, hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();
const route = useRoute();
const router = useRouter();

const is_submit_form4 = ref(false);
const client = ref({token: "", password: "", password_confirmation: ""});
const passRecFunc = () => {
    is_submit_form4.value = true;
    clearErrors();
    client.value.token = route.params.token;

    if (!client.value.password || !client.value.password_confirmation) {
        return;
    }

    // UI-6: this used to silently do nothing at all on a mismatch — no
    // toast, no inline error, nothing — since the whole request was
    // gated behind this comparison. Surfacing it as a real field error
    // means a mismatch is never a dead click.
    if (client.value.password !== client.value.password_confirmation) {
        errors.password_confirmation = ['Passwords do not match.'];
        return;
    }

    axios.post('/api/v1/password-reset', client.value)
        .then(response => {
            showMessage(response.data.message);
            router.push("/login");
        })
        .catch(error => {
            showMessage(setErrorsFromResponse(error, 'Error resetting password. Please check the token in your email.'), 'error');
        });
}
</script>
