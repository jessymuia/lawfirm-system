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
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Expense Category</button>
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
        <div class="modal fade " id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
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
                                    <div class="invalid-feedback">Please fill the category name</div>
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
useMeta({ title: 'Hearing Types' });

const columns = ref(['name', 'actions']);
const items = ref([]);
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
    sortable: ['name'],
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

const client = ref({id: "",name:""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.name) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/expense-categories/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Category updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating category. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/expense-categories', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Expense  category added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding category. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.name = "";
        modal.value.title = "Add Expense Category";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const fetchData = () => {
    modal.value.title = "Add Expense Category";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/expense-categories')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching categories:', error);
        });

};
const update_row = (item) => {
    client.value.id = item.id;
    client.value.name = item.name;
    modal.value.title = "Update Expense Category";
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
