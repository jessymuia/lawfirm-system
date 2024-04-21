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
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Role</button>
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
        <div class="modal fade " ref="clientModal" id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
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
                                    <label for="validationCustom01">Role Name</label>
                                    <input
                                        type="text"
                                        v-model="client.name"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Role name"
                                    />
                                    <div class="invalid-feedback">Please fill the role name</div>
                                </div>
                                <div class="row">
                                    <div class="col-lg-12">
                                        <div class="mb-3">
                                            <label for="name">Permissions</label>
                                            <div class="row">
                                                <div class="col-md-3" v-for="(permission, index) in itemsP" :key="index">
                                                    <div class="checkbox-info custom-control custom-checkbox">
                                                        <input
                                                            type="checkbox"
                                                            :id="'chk_info_' + index"
                                                        v-model="checkedPermissions"
                                                        class="custom-control-input"
                                                        />
                                                        <label :for="'chk_info_' + index" class="custom-control-label">{{ permission.name }}</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
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
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";

import { useMeta } from '@/composables/use-meta';
import Multiselect from "@suadelabs/vue3-multiselect";
useMeta({ title: 'Hearings' });

const columns = ref(['name','created_at','updated_at','actions']);
const items = ref([]);
const itemsP = ref([]);
const cases = ref([]); // List of advocates
const users = ref([]);
const courts = ref([]);
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
    sortable: ['name','created_at','updated_at'],
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

const checkedPermissions = ref([]);

// Watch for changes in checkedPermissions and update client.permissions
watch(checkedPermissions, (newPermissions) => {
    client.value.permissions = newPermissions;
});





const is_submit_form4 = ref(false);

const client = ref({id: "",name: "", permissions : []});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.name ) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/roles/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Role updated successfully.');

                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding role. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/roles', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Role added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding role. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.name = "";
        client.value.permissions = [];
        modal.value.title = "Add Role";
        modal.value.button = "Add";
        is_submit_form4.value = false;

    }
};

const fetchData = () => {
    modal.value.title = "Add Role";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/roles')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });

    // Fetch list of advocates
    axios.get('/api/permissions/')
        .then(response => {
            itemsP.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching roles:', error);
        });
};

const getPermissions = () => {
    axios.get(`/api/permissions/${client.value.id}`)
        .then(response => {
            client.value.permissions = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching roles:', error);
        });
};
const update_row = (item) => {
    console.log(item);
    client.value.id = item.id;
    client.value.name = item.name;

    modal.value.title = "Update Role "+item.name;
    modal.value.button = "Update";

    getPermissions();
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
