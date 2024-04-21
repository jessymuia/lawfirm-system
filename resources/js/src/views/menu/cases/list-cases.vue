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
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Case</button>
            </div>
        </div>

        <div class="row layout-top-spacing">
            <div class="col-xl-12 col-lg-12 col-sm-12 layout-spacing">
                <div class="panel br-6 p-0">
                    <div class="custom-table">
                        <v-client-table :data="items" :columns="columns" :options="table_option">
                            <template #actions="props">
                                <div class="actions text-center">
                                    <a href="javascript:;" class="cancel" @click="view_row(props.row.id)">
                                      <button type="button" class="btn btn-primary btn-sm">View</button>
                                    </a>
                                    <a href="javascript:;" class="cancel me-1" @click="update_row(props.row)">
                                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#client_modal">Update</button>
                                    </a>
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
                                    <label for="validationCustom01">Case Number</label>
                                    <input
                                        type="text"
                                        v-model="client.case_number"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.case_number ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Case Number"
                                    />
                                    <div class="invalid-feedback">Please fill the desciption</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Description</label>
                                    <input
                                        type="text"
                                        v-model="client.description"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.description ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Description"
                                    />
                                    <div class="invalid-feedback">Please fill the desciption</div>
                                </div>
                                <div class="col-md-12">
                                    <div id="select_menu" class="form-group form-group">
                                        <select v-model="client.client_id" class="form-select" :class="[is_submit_form4 ? (client.client_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Client</option>
                                            <option v-for="data in clients" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please Select the Client</div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div id="select_menu" class="form-group form-group">
                                        <select v-model="client.assigned_to" class="form-select" :class="[is_submit_form4 ? (client.assigned_to ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Attorney</option>
                                            <option v-for="data in advocates" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please Select the Attorney</div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Start Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.start_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.start_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">End Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.end_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.end_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Case Type</label>
                                    <input
                                        type="text"
                                        v-model="client.case_type"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.case_type ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Case Type"
                                    />
                                    <div class="invalid-feedback">Please provide a valid Case Type</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Police Station</label>
                                    <input
                                        type="text"
                                        v-model="client.police_station"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.police_station ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Police Station"
                                    />
                                    <div class="invalid-feedback">Please provide a valid Police Station.</div>
                                </div>

                                <div class="col-md-12">
                                    <div id="select_menu" class="form-group form-group">
                                        <select v-model="client.court_id" class="form-select" :class="[is_submit_form4 ? (client.court_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Role</option>
                                            <option v-for="data in courts" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please Select the Court</div>
                                    </div>
                                </div>

                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Opposing Party</label>
                                    <input
                                        type="text"
                                        v-model="client.opposing_party"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.opposing_party ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Opposing Party"
                                    />
                                    <div class="invalid-feedback">Please provide a valid Opposing party.</div>
                                </div>

                            </div>
                            <button class="btn btn-primary mt-3" type="submit">{{modal.button}}</button>
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
useMeta({ title: 'Cases' });

const columns = ref(['case_number', 'description', 'client', 'attorney', 'start_date', 'end_date','case_type','police_station','court','opposing_party', 'actions']);
const items = ref([]);
const courts = ref([]); // List of advocates
const advocates = ref([]);
const clients = ref([]);
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
    sortable: ['case_number', 'description', 'client', 'attorney', 'start_date', 'end_date','case_type','police_station','court','opposing_party'],
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

const client = ref({id: "",case_number:"",description: "", client_id: "", assigned_to: "", start_date: "", end_date: "", case_type:"", police_station:"",court_id: "",opposing_party:""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.case_number && client.value.client_id  && client.value.assigned_to && client.value.start_date && client.value.police_station && client.value.court_id && client.value.case_type) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/cases/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Case updatedsuccessfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating Cases. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/cases', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('User added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding case. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.case_number = "";
        client.value.description = "";
        client.value.client_id = "";
        client.value.assigned_to = "";
        client.value.start_date = "";
        client.value.end_date = "";
        client.value.case_type = "";
        client.value.police_station ="";
        client.value.court_id = "";
        client.value.opposing_party ="";
        modal.value.title = "Add Case";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const fetchData = () => {
    modal.value.title = "Add Case";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/cases')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });

    axios.get('/api/courts')
        .then(response => {
            courts.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });

    axios.get('/api/advocates')
        .then(response => {
            advocates.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching advocates:', error);
        });

    axios.get('/api/clientsDropDown')
        .then(response => {
            clients.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching advocates:', error);
        });
};
const update_row = (item) => {
    client.value.id = item.id;
    client.value.case_number = item.case_number;
    client.value.client_id = item.client_id;
    client.value.assigned_to = item.assigned_to;
    client.value.start_date = item.start_date;
    client.value.end_date = item.end_date;
    client.value.police_station = item.police_station;
    client.value.court_id = item.court_id;
    client.value.opposing_party = item.opposing_party;
    client.value.case_type = item.case_type;
    client.value.description = item.description;

    modal.value.title = "Update Case";
    modal.value.button = "Update";
};
const view_row = (itemId) => {
    localStorage.setItem("caseId", itemId);
    router.push("/view-case");
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
