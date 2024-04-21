<template>
    <div class="container layout-px-spacing">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Users</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>
        <div class="row justify-content-end">
            <div class="col-auto">
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add User</button>
            </div>
        </div>

        <div class="row layout-top-spacing">
            <div class="col-xl-12 col-lg-12 col-sm-12 layout-spacing">
                <div class="panel br-6 p-0">
                    <div class="custom-table">
                        <v-client-table :data="items" :columns="columns" :options="table_option">
                            <template #actions="props">
                                <div class="actions text-center">
<!--                                    <a href="javascript:;" class="cancel" @click="view_row(props.row)">-->
<!--                                        <button type="button" class="btn btn-primary btn-sm">View</button>-->
<!--                                    </a>-->
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
                                    <label for="validationCustom02">Email</label>
                                    <input
                                        type="email"
                                        v-model="client.email"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.email ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom02"
                                        placeholder="email"
                                    />
                                    <div class="invalid-feedback">Please fill the Email</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustomUsername">Phone Number</label>
                                    <div class="input-group">
                                        <input
                                            type="text"
                                            v-model="client.phone_number"
                                            class="form-control"
                                            :class="[is_submit_form4 ? (client.phone_number ? 'is-valid' : 'is-invalid') : '']"
                                            id="validationCustomUsername"
                                            placeholder="Phone Number"
                                            aria-describedby="inputGroupPrepend"
                                        />
                                        <div class="invalid-feedback">Please input a phone.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Department</label>
                                    <input
                                        type="text"
                                        v-model="client.department"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.department ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Department"
                                    />
                                    <div class="invalid-feedback">Please provide a valid department.</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Hire Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.hire_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.hire_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>

                                <div class="col-md-12">
                                    <div id="select_menu" class="form-group form-group">
                                        <select v-model="client.role" class="form-select" :class="[is_submit_form4 ? (client.role ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Role</option>
                                            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please Select the role</div>
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
import Multiselect from "@suadelabs/vue3-multiselect";
useMeta({ title: 'Users' });

const columns = ref(['name', 'email', 'phone_number', 'department', 'hire_date', 'role', 'actions']);
const items = ref([]);
const roles = ref([]); // List of advocates
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
    sortable: ['name', 'email', 'phone_number', 'department', 'hire_date', 'role',],
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







const is_submit_form4 = ref(false);

const client = ref({id: "",name:"",phone_number : "",email : "",department : "",role : "",hire_date: ""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.name && client.value.phone_number && client.value.email && client.value.department && client.value.role) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/users/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('User updated successfully.');

                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating user. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/users', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('User added successfully.');

                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding user. Please try again.');
                    console.error(error);
                });
        }

        client = ref({id: "",name:"",phone_number : "",email : "",department : "",role : "",hire_date: ""});
        modal.value.title = "Add User";
        modal.value.button = "Add";

    }
};

const fetchData = () => {
    modal.value.title = "Add User";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/users')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });

    // Fetch list of advocates
    axios.get('/api/rolesDropDown')
        .then(response => {
            roles.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching roles:', error);
        });
};
const update_row = (item) => {
    modal.value.title = "Update User";
    modal.value.button = "Update";

    client.value.id = item.id;
    client.value.name = item.name;
    client.value.phone_number = item.phone_number;
    client.value.email = item.email;
    client.value.role = item.role_id;
    client.value.department = item.department;
    client.value.hire_date = item.hire_date;

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
