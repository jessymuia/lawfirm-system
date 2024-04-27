<template>
    <div class="form full-form auth-cover">
        <div class="form-container">
            <div class="form-form">
                <div class="form-form-wrap">
                    <div class="form-container">
                        <div class="form-content">
                            <h1 class="">Password Recovery</h1>
                            <p class="signup-link">Enter your email and instructions will sent to you!</p>
                            <form class="text-start">
                                <div class="form">
                                    <div id="email-field" class="field-wrapper input">
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
                                        <input v-model="client.email" type="email" class="form-control" placeholder="Email" />
                                    </div>
                                    <div class="d-sm-flex justify-content-between">
                                        <div class="field-wrapper">
                                            <button @click="passRecFunc()" type="submit" class="btn btn-primary">Reset</button>
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
    import { useRouter } from 'vue-router';
    import { useMeta } from '@/composables/use-meta';
    import axios from "../../api";
    import {ref} from "vue";
    useMeta({ title: 'Password Recovery Cover' });

    const router = useRouter();

    const is_submit_form4 = ref(false);
    const client = ref({email: ""});
    const passRecFunc = () => {
        is_submit_form4.value = true;
        if (client.value.email) {
            axios.post('/api/password-recovery', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                        showMessage("Check your mail");
                        router.push("/login");
                        console.log(response);
                })
                .catch(error => {
                    showMessage('Error recovering password. Please try again.');
                    console.error(error);
                    router.push("/login");
                });
        }
    }

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
