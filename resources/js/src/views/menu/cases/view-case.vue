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
            <div class="nav sidenav">
                <div class="sidenav-content" v-scroll-spy-active v-scroll-spy-link>
                    <a href="#browser_default" class="nav-link">Case Details</a>
                </div>
            </div>
            <div class="row layout-top-spacing">

                <div id="browser_default" class="col-lg-12 layout-spacing col-md-12">
                    <div class="statbox panel box box-shadow">
                        <div class="panel-heading">
                            <div class="row">
                                <div class="col-xl-12 col-md-12 col-sm-12 col-12">
                                    <h4>Case Details</h4>
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <form @submit.stop.prevent="submit_form5">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault01">Case Number</label>
                                        <input type="text" v-model="form5.first_name" class="form-control" id="validationDefault01" placeholder="First name" required />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Name</label>
                                        <input type="text" v-model="form5.last_name" class="form-control" id="validationDefault02" placeholder="Last name" required />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">CNR Number</label>
                                        <input type="text" v-model="form5.last_name" class="form-control" id="validationDefault02" placeholder="Last name" required />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault01">Client</label>
                                        <input type="text" v-model="form5.first_name" class="form-control" id="validationDefault01" placeholder="First name" required />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Advocate</label>
                                        <input type="text" v-model="form5.last_name" class="form-control" id="validationDefault02" placeholder="Last name" required />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Case Type</label>
                                        <input type="text" v-model="form5.last_name" class="form-control" id="validationDefault02" placeholder="Last name" required />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Start Date</label>
                                        <input type="text" v-model="form5.city" class="form-control" id="validationDefault03" placeholder="Phone Number" required />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault04">Police Station</label>
                                        <input type="text" v-model="form5.state" class="form-control" id="validationDefault04" placeholder="Advocate" required />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Court ID</label>
                                        <input type="text" v-model="form5.city" class="form-control" id="validationDefault03" placeholder="City" required />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault04">Opposing Party</label>
                                        <input type="text" v-model="form5.state" class="form-control" id="validationDefault04" placeholder="Address" required />
                                    </div>
                                    <!--                                    <div class="col-md-3 form-group">-->
                                    <!--                                        <label for="validationDefault05">Zip</label>-->
                                    <!--                                        <input type="text" v-model="form5.zip" class="form-control" id="validationDefault05" placeholder="Zip" required />-->
                                    <!--                                    </div>-->
                                </div>
                                <div class="row">
                                    <div class="col-md-12 form-group">
                                        <label for="validationDefault03">Description</label>
                                        <textarea class="form-control" id="validationDefault03" rows="12" required>Description</textarea>
                                        <!--                                        <input type="text" v-model="form5.city" class="form-control" rows="12"-->
                                        <!--                                               id="validationDefault03" placeholder="City" required />-->
                                    </div>
                                </div>
                                <button class="btn btn-primary mt-3" type="submit">Submit form</button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
            <div class="col-lg-12 col-12 layout-spacing">
                <div class="statbox panel box box-shadow">
                    <div class="panel-heading">
                        <div class="row">
                            <div class="col-xl-12 col-md-12 col-sm-12 col-12">
                                <h4>Justify Center Tabs</h4>
                            </div>
                        </div>
                    </div>
                    <div class="panel-body tab-justify-centered tabs">
                        <ul class="nav nav-tabs mb-3 mt-3 justify-content-center" id="justifyCenterTab" role="tablist">
                            <li class="nav-item">
                                <a
                                    class="nav-link active"
                                    id="justify-center-home-tab"
                                    data-bs-toggle="tab"
                                    href="#justify-center-home"
                                    role="tab"
                                    aria-controls="justify-center-home"
                                    aria-selected="true"
                                >Home</a
                                >
                            </li>
                            <li class="nav-item">
                                <a
                                    class="nav-link"
                                    id="justify-center-profile-tab"
                                    data-bs-toggle="tab"
                                    href="#justify-center-profile"
                                    role="tab"
                                    aria-controls="justify-center-profile"
                                    aria-selected="false"
                                >Profile</a
                                >
                            </li>
                            <li class="nav-item">
                                <a
                                    class="nav-link"
                                    id="justify-center-contact-tab"
                                    data-bs-toggle="tab"
                                    href="#justify-center-contact"
                                    role="tab"
                                    aria-controls="justify-center-contact"
                                    aria-selected="false"
                                >Contact</a
                                >
                            </li>
                        </ul>

                        <div class="tab-content" id="justifyCenterTabContent">
                            <div class="tab-pane fade show active" id="justify-center-home" role="tabpanel" aria-labelledby="justify-center-home-tab">
                                <p class="mb-4">
                                    Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
                                    nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
                                </p>

                                <p>
                                    Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
                                    nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
                                </p>
                            </div>
                            <div class="tab-pane fade" id="justify-center-profile" role="tabpanel" aria-labelledby="justify-center-profile-tab">
                                <div class="media">
                                    <img class="me-3" src="@/assets/images/profile-32.jpeg" alt="Generic placeholder image" />
                                    <div class="media-body">
                                        Cras sit amet nibh libero, in gravida nulla. Nulla vel metus scelerisque ante sollicitudin. Cras purus odio, vestibulum in vulputate at, tempus viverra
                                        turpis. Fusce condimentum nunc ac nisi vulputate fringilla. Donec lacinia congue felis in faucibus.
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="justify-center-contact" role="tabpanel" aria-labelledby="justify-center-contact-tab">
                                <p class="dropcap dc-outline-primary">
                                    Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
                                    nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore
                                    eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
                                </p>
                            </div>
                        </div>

                        <div class="code-section-container show-code">
                            <button type="button" class="btn-default toggle-code-snippet" @click="toggleCode('code13')"><span>Code</span></button>

                            <div v-if="code_arr.includes('code13')" class="code-section text-start">
                                <highlight>
                                            <pre>
&lt;div class=&quot;panel-body tab-justify-centered tabs&quot;&gt;
    &lt;ul class=&quot;nav nav-tabs mb-3 mt-3 justify-content-center&quot; id=&quot;justifyCenterTab&quot; role=&quot;tablist&quot;&gt;
        &lt;li class=&quot;nav-item&quot;&gt;
            &lt;a
                class=&quot;nav-link active&quot;
                id=&quot;justify-center-home-tab&quot;
                data-bs-toggle=&quot;tab&quot;
                href=&quot;#justify-center-home&quot;
                role=&quot;tab&quot;
                aria-controls=&quot;justify-center-home&quot;
                aria-selected=&quot;true&quot;
                &gt;Home&lt;/a
            &gt;
        &lt;/li&gt;
        &lt;li class=&quot;nav-item&quot;&gt;
            &lt;a
                class=&quot;nav-link&quot;
                id=&quot;justify-center-profile-tab&quot;
                data-bs-toggle=&quot;tab&quot;
                href=&quot;#justify-center-profile&quot;
                role=&quot;tab&quot;
                aria-controls=&quot;justify-center-profile&quot;
                aria-selected=&quot;false&quot;
                &gt;Profile&lt;/a
            &gt;
        &lt;/li&gt;
        &lt;li class=&quot;nav-item&quot;&gt;
            &lt;a
                class=&quot;nav-link&quot;
                id=&quot;justify-center-contact-tab&quot;
                data-bs-toggle=&quot;tab&quot;
                href=&quot;#justify-center-contact&quot;
                role=&quot;tab&quot;
                aria-controls=&quot;justify-center-contact&quot;
                aria-selected=&quot;false&quot;
                &gt;Contact&lt;/a
            &gt;
        &lt;/li&gt;
    &lt;/ul&gt;

    &lt;div class=&quot;tab-content&quot; id=&quot;justifyCenterTabContent&quot;&gt;
        &lt;div class=&quot;tab-pane fade show active&quot; id=&quot;justify-center-home&quot; role=&quot;tabpanel&quot; aria-labelledby=&quot;justify-center-home-tab&quot;&gt;
            &lt;p class=&quot;mb-4&quot;&gt;
                Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
                nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
            &lt;/p&gt;

            &lt;p&gt;
                Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
                nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
            &lt;/p&gt;
        &lt;/div&gt;
        &lt;div class=&quot;tab-pane fade&quot; id=&quot;justify-center-profile&quot; role=&quot;tabpanel&quot; aria-labelledby=&quot;justify-center-profile-tab&quot;&gt;
            &lt;div class=&quot;media&quot;&gt;
                &lt;img class=&quot;me-3&quot; src=&quot;@/assets/images/profile-32.jpeg&quot; alt=&quot;Generic placeholder image&quot; /&gt;
                &lt;div class=&quot;media-body&quot;&gt;
                    Cras sit amet nibh libero, in gravida nulla. Nulla vel metus scelerisque ante sollicitudin. Cras purus odio, vestibulum in vulputate at, tempus viverra
                    turpis. Fusce condimentum nunc ac nisi vulputate fringilla. Donec lacinia congue felis in faucibus.
                &lt;/div&gt;
            &lt;/div&gt;
        &lt;/div&gt;
        &lt;div class=&quot;tab-pane fade&quot; id=&quot;justify-center-contact&quot; role=&quot;tabpanel&quot; aria-labelledby=&quot;justify-center-contact-tab&quot;&gt;
            &lt;p class=&quot;dropcap dc-outline-primary&quot;&gt;
                Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
                nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu
                fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
            &lt;/p&gt;
        &lt;/div&gt;
    &lt;/div&gt;
&lt;/div&gt;
</pre
>
                                </highlight>
                            </div>
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
