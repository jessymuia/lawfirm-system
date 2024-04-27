<template>
    <div class="form full-form auth-cover">
        <div class="form-container">
            <div class="form-form">
                <div class="form-form-wrap">
                    <div class="form-container">
                        <div class="form-content">
                            <h1 class="">
                                Log In to <router-link to="/"><span class="brand-name">Njoga and Co. Advocates</span></router-link>
                            </h1>
                            <form class="needs-validation" novalidate @submit.stop.prevent="loginFunc">
                                <div class="form">
                                    <div id="username-field" class="field-wrapper input">
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
                                            class="feather feather-user"
                                        >
                                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="12" cy="7" r="4"></circle>
                                        </svg>
                                        <input type="text" v-model = "client.email" class="form-control" placeholder="Username" />
                                    </div>

                                    <div id="password-field" class="field-wrapper input mb-2">
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
                                            class="feather feather-lock"
                                        >
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                        </svg>
                                        <input type="password" v-model="client.password" class="form-control" placeholder="Password" />
                                    </div>
                                    <div class="d-sm-flex justify-content-between">
                                        <div class="field-wrapper toggle-pass d-flex align-items-center">
                                            <p class="d-inline-block">Show Password</p>
                                            <label class="switch s-primary mx-2">
                                                <input type="checkbox" class="custom-control-input" checked="" />
                                                <span class="slider round"></span>
                                            </label>
                                        </div>
                                        <div class="field-wrapper">
                                            <button type="submit" class="btn btn-primary">Log In</button>
                                        </div>
                                    </div>

                                    <div class="field-wrapper text-center keep-logged-in">
                                        <div class="checkbox-outline-primary custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" value="true" id="chkRemember" />
                                            <label class="custom-control-label" for="chkRemember">Keep me logged in</label>
                                        </div>
                                    </div>

                                    <div class="field-wrapper">
                                        <router-link to="/auth/pass-recovery" class="forgot-pass-link">Forgot Password?</router-link>
                                    </div>
                                </div>
                            </form>
                            <p class="terms-conditions">
                                © 2020 All Rights Reserved.
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
    import '@/assets/sass/scrollspyNav.scss';
    import {useRouter} from "vue-router";
    import { useMeta } from '@/composables/use-meta';
    import { onMounted, ref } from 'vue';
    import axios from "../../api";
    useMeta({ title: 'Login Cover' });

    const router = useRouter();

    const is_submit_form4 = ref(false);
    const client = ref({email: "", password: ""});
    const loginFunc = () => {
        is_submit_form4.value = true;
        if (client.value.email && client.value.password) {
            axios.post('/api/login', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {

                    if(response.data.data !== 400) {
                        const logged_user = {
                            login: true,
                            user_id: response.data.data[0].id,
                            name: response.data.data[0].name,
                            token: response.data.data[1],
                            isAllowed: response.data.data[2],
                            roles: response.data.data[3],
                            // email: data.data.email,
                        }
                        localStorage.setItem('user', JSON.stringify(logged_user));
                        router.push("/view-case");
                    } else {
                        showMessage(response.data.message);
                    }


                })
                .catch(error => {
                    showMessage('Error logging in. Please try again.');
                    console.error(error);
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

    onMounted(() => {
        //localStorage.clear();
    });

</script>
