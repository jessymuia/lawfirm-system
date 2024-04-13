<template>
    <div class="container">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Forms</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Validation</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="container">
<!--            <div class="nav sidenav">-->
<!--                <div class="sidenav-content" v-scroll-spy-active v-scroll-spy-link>-->
<!--                    <a href="#browser_default" class="nav-link">Browser Default</a>-->
<!--                </div>-->
<!--            </div>-->
            <div class="row layout-top-spacing">

                <div id="browser_default" class="col-lg-12 layout-spacing col-md-12">
                    <div class="statbox panel box box-shadow">
                        <div class="panel-heading">
                            <div class="row">
                                <div class="col-xl-12 col-md-12 col-sm-12 col-12">
                                    <h4>Client Details</h4>
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <form @submit.stop.prevent="submit_form5">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault01">First name</label>
                                        <input type="text" v-model="form5.first_name" class="form-control" id="validationDefault01" placeholder="First name" required />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Middle name</label>
                                        <input type="text" v-model="form5.last_name" class="form-control" id="validationDefault02" placeholder="Last name" required />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Last name</label>
                                        <input type="text" v-model="form5.last_name" class="form-control" id="validationDefault02" placeholder="Last name" required />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Phone Number</label>
                                        <input type="text" v-model="form5.city" class="form-control" id="validationDefault03" placeholder="Phone Number" required />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault04">Advocate</label>
                                        <input type="text" v-model="form5.state" class="form-control" id="validationDefault04" placeholder="Advocate" required />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">City</label>
                                        <input type="text" v-model="form5.city" class="form-control" id="validationDefault03" placeholder="City" required />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault04">Address</label>
                                        <input type="text" v-model="form5.state" class="form-control" id="validationDefault04" placeholder="Address" required />
                                    </div>
<!--                                    <div class="col-md-3 form-group">-->
<!--                                        <label for="validationDefault05">Zip</label>-->
<!--                                        <input type="text" v-model="form5.zip" class="form-control" id="validationDefault05" placeholder="Zip" required />-->
<!--                                    </div>-->
                                </div>
<!--                                <div class="form-group form-group">-->
<!--                                    <div class="custom-control custom-checkbox checkbox-primary">-->
<!--                                        <input type="checkbox" v-model="form5.is_terms" class="custom-control-input" id="invalidCheck2" required />-->
<!--                                        <label class="custom-control-label" for="invalidCheck2">Agree to terms and conditions</label>-->
<!--                                        <div class="invalid-feedback">Agree to terms and conditions</div>-->
<!--                                    </div>-->
<!--                                </div>-->
                                <button class="btn btn-primary mt-3" type="submit">Submit form</button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import '@/assets/sass/scrollspyNav.scss';
import highlight from '@/components/plugins/highlight.vue';

import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Form Validation' });

const code_arr = ref([]);
const form1 = ref({ name: '' });
const is_submit_form1 = ref(false);
const form2 = ref({ email: '' });
const is_submit_form2 = ref(false);
const form3 = ref({ select: '' });
const is_submit_form3 = ref(false);
const form4 = ref({ first_name: 'Shaun', last_name: 'Park', username: '', city: '', state: '', zip: '', is_terms: false });
const is_submit_form4 = ref(false);
const form5 = ref({ first_name: 'Shaun', last_name: 'Park', username: '', city: '', state: '', zip: '', is_terms: false });
const is_submit_form5 = ref(false);
const form6 = ref({ first_name: 'Shaun', last_name: 'Park', username: '', city: '', state: '', zip: '', is_terms: false });
const is_submit_form6 = ref(false);

const toggleCode = (name) => {
    if (code_arr.value.includes(name)) {
        code_arr.value = code_arr.value.filter((d) => d != name);
    } else {
        code_arr.value.push(name);
    }
};
const email_validate = (email) => {
    const regexp = /^[\w.%+-]+@[\w.-]+\.[\w]{2,6}$/;
    return regexp.test(email);
};
const submit_form1 = () => {
    is_submit_form1.value = true;
    if (form1.value.name) {
        //form validated success
        showMessage('Form submitted successfully.');
    }
};
const submit_form2 = () => {
    is_submit_form2.value = true;
    if (email_validate(form2.value.email)) {
        //form validated success
        showMessage('Form submitted successfully.');
    }
};
const submit_form3 = () => {
    is_submit_form3.value = true;
    if (form3.value.select) {
        //form validated success
        showMessage('Form submitted successfully.');
    }
};
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (form4.value.first_name && form4.value.last_name && form4.value.username && form4.value.city && form4.value.state && form4.value.zip && form4.value.is_terms) {
        //form validated success
        showMessage('Form submitted successfully.');
    }
};
const submit_form5 = () => {
    is_submit_form5.value = true;
    if (form5.value.first_name && form5.value.last_name && form5.value.username && form5.value.city && form5.value.state && form5.value.zip && form5.value.is_terms) {
        //form validated success
        showMessage('Form submitted successfully.');
    }
};
const submit_form6 = () => {
    is_submit_form6.value = true;
    if (form6.value.first_name && form6.value.last_name && form6.value.username && form6.value.city && form6.value.state && form6.value.zip && form6.value.is_terms) {
        //form validated success
        showMessage('Form submitted successfully.');
    }
};

const showMessage = (msg = '', type = 'success') => {
    const toast = window.Swal.mixin({ toast: true, position: 'top', showConfirmButton: false, timer: 3000 });
    toast.fire({ icon: type, title: msg, padding: '10px 20px' });
};
</script>
