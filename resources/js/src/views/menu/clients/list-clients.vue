<template>
    <div class="container layout-px-spacing">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Clients</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>
        <div class="row justify-content-end">
            <div class="col-auto">
                <button  type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Client</button>
            </div>
        </div>

        <div class="row layout-top-spacing">
            <div class="col-xl-12 col-lg-12 col-sm-12 layout-spacing">
                <div class="panel br-6 p-0">
                    <div class="custom-table">
                        <v-client-table :data="items" :columns="columns" :options="table_option">
                            <template #actions="props">
                                <div class="actions text-center">
                                    <a href="javascript:;" class="cancel" @click="view_row(props.row)">
                                        <button type="button" class="btn btn-primary btn-sm">View</button>
                                    </a>
                                    <a href="javascript:;" class="cancel me-1" @click="update_row(props.row)">
                                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#client_modal">Update</button>
                                    </a>
<!--                                    <router-link :to='view-client'>-->
<!--                                        <button type="button" class="btn btn-primary btn-sm">View</button>-->
<!--                                    </router-link>-->
                                </div>
                            </template>
                            <template #salary="props"> ${{ props.row.salary }} </template>
                        </v-client-table>
                    </div>
                </div>
            </div>
        </div>


        <!-- Add Modal -->
        <div class="modal fade" id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div  class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header" id="registerModalLabel">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="needs-validation" novalidate @submit.stop.prevent="submit_form4">
                                        <div class="row">
                                            <div class="col-md-12 form-group">
                                                <label for="validationCustom01">Name</label>
                                                <input
                                                    type="text"
                                                    v-model="client.name"
                                                    class="form-control"
                                                    :class="[is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '']"
                                                    id="validationCustom01"
                                                    placeholder="Name"
                                                />
                                                <div class="invalid-feedback">Please fill the name</div>
                                            </div>
                                            <div class="col-md-12 form-group">
                                                <label for="validationCustom02">Phone Number</label>
                                                <input
                                                    type="text"
                                                    v-model="client.phone_number"
                                                    class="form-control"
                                                    :class="[is_submit_form4 ? (client.phone_number ? 'is-valid' : 'is-invalid') : '']"
                                                    id="validationCustom02"
                                                    placeholder="Phone Number"
                                                />
                                                <div class="invalid-feedback">Please fill the Phone Number</div>
                                            </div>
                                            <div class="col-md-12 form-group">
                                                <label for="validationCustomUsername">Extra Phone Number</label>
                                                <div class="input-group">
                                                    <input
                                                        type="text"
                                                        v-model="client.extra_phone_number"
                                                        class="form-control"
                                                        :class="[is_submit_form4 ? (client.extra_phone_number ? 'is-valid' : 'is-invalid') : '']"
                                                        id="validationCustomUsername"
                                                        placeholder="Extra Phone Number"
                                                        aria-describedby="inputGroupPrepend"
                                                    />
                                                    <div class="invalid-feedback">Please input an extra phone.</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 form-group">
                                                <label for="validationCustom03">Address</label>
                                                <input
                                                    type="text"
                                                    v-model="client.address"
                                                    class="form-control"
                                                    :class="[is_submit_form4 ? (client.address ? 'is-valid' : 'is-invalid') : '']"
                                                    id="validationCustom03"
                                                    placeholder="Address"
                                                />
                                                <div class="invalid-feedback">Please provide a valid address.</div>
                                            </div>
<!--                                            <div class="col-md-12 form-group">-->
<!--                                                <label for="validationCustom04">Advocate</label>-->
<!--                                                <input-->
<!--                                                    type="text"-->
<!--                                                    v-model="client.advocate"-->
<!--                                                    class="form-control"-->
<!--                                                    :class="[is_submit_form4 ? (client.advocate ? 'is-valid' : 'is-invalid') : '']"-->
<!--                                                    id="validationCustom04"-->
<!--                                                    placeholder="Advocate"-->
<!--                                                />-->
<!--                                                <div class="invalid-feedback">Please provide a valid advocate.</div>-->
<!--                                            </div>-->
                                            <div class="col-md-12">
                                                <div id="select_menu" class="form-group form-group">
                                                    <select v-model="client.advocate" class="form-select" :class="[is_submit_form4 ? (client.advocate ? 'is-valid' : 'is-invalid') : '']">
                                                        <option value="">Select Advocate</option>
                                                        <option v-for="data in advocates" :key="data.id" :value="data.id">{{ data.name }}</option>
                                                    </select>
                                                    <div class="invalid-feedback">Please Select the advocate</div>
                                                </div>
                                            </div>
                                        </div>
                                        <button class="btn btn-primary mt-3" type="submit">{{ modal.button }}</button>
                                    </form>

                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";

import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Clients' });

const columns = ref(['name', 'phone_number', 'extra_phone_number', 'address', 'advocate', 'actions']);
const items = ref([]);
const advocates = ref([]); // List of advocates
const code_arr = ref([]);
const modal = ref({title: "", button: ""});
const table_option = ref({
    perPage: 10,
    perPageValues: [5, 10, 20, 50],
    skin: 'table table-hover',
    columnsClasses: { actions: 'actions text-center' },
    pagination: { nav: 'scroll', chunk: 5 },
    texts: {
        count: 'Showing {from} to {to} of {count}',
        filter: '',
        filterPlaceholder: 'Search...',
        limit: 'Results:',
    },
    sortable: ['name', 'phone_number', 'extra_phone_number', 'address', 'advocate'],
    sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc',
    },
    resizableColumns: false,
});

onMounted(() => {
    //bind_data();
    fetchData();
});

const router = useRouter();


const bind_data = () => {
    items.value = [
        { id: 1, name: 'Tiger Nixon', phone_number: '0777262222', extra_phone_number: '07826827722', address: 'Penyslvenia', advocate: 'Advoccate 1'}
    ];
};




const form4 = ref({ first_name: 'Shaun', last_name: 'Park', username: '', city: '', state: '', zip: '', is_terms: false });
const is_submit_form4 = ref(false);

const client = ref({id: "",name:"",phone_number : "",extra_phone_number : "",address : "",advocate : ""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.name && client.value.phone_number && client.value.extra_phone_number && client.value.address && client.value.advocate) {
        //form validated success
        if(client.value.id > 0) {
            axios.put(`/api/clients/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Client updatedsuccessfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding user. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/clients', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Client added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding client. Please try again.');
                    console.error(error);
                });
        }


        client.value.id = "";
        client.value.name = "";
        client.value.phone_number = "";
        client.value.extra_phone_number = "";
        client.value.address = "";
        client.value.advocate = "";
        modal.value.title = "Add Client";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const fetchData = () => {
    modal.value.title = "Add Client";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/clients')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });

    // Fetch list of advocates
    axios.get('/api/advocates')
        .then(response => {
            advocates.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching advocates:', error);
        });
};
const update_row = (item) => {
    client.value.id = item.id;
    client.value.name = item.name;
    client.value.phone_number = item.phone_number;
    client.value.extra_phone_number = item.extra_phone_number;
    client.value.advocate = item.advocate_id;

    modal.value.title = "Update Client";
    modal.value.button = "Update";
};

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
