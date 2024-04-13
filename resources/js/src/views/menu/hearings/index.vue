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
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Hearing</button>
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
                        <button type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="needs-validation" novalidate @submit.stop.prevent="submit_form4">
                            <div class="row">
                                <div class="col-md-12">
                                    <div id="select_menu" class="form-group form-group">
                                        <select v-model="client.case_id" class="form-select" :class="[is_submit_form4 ? (client.case_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Case</option>
                                            <option v-for="data in cases" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please Select the Case</div>
                                    </div>
                                </div>
                                <div id="select_menu" class="form-group form-group">
                                    <select v-model="client.court_id" class="form-select" :class="[is_submit_form4 ? (client.court_id ? 'is-valid' : 'is-invalid') : '']">
                                        <option value="">Select Court</option>
                                        <option v-for="data in courts" :key="data.id" :value="data.id">{{ data.name }}</option>
                                    </select>
                                    <div class="invalid-feedback">Please Select the Court</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Hearing Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.hearing_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.hearing_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Hearing Type</label>
                                    <input
                                        type="text"
                                        v-model="client.hearing_type"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.hearing_type ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Hearing Type"
                                    />
                                    <div class="invalid-feedback">Please fill the hearing type</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Notes</label>
                                    <input
                                        type="text"
                                        v-model="client.notes"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.notes ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Notes"
                                    />
                                    <div class="invalid-feedback">Please fill the Notes</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Outcomes</label>
                                    <input
                                        type="text"
                                        v-model="client.outcome"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.outcome ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Description"
                                    />
                                    <div class="invalid-feedback">Please fill the outcomes</div>
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
useMeta({ title: 'Hearings' });

const columns = ref(['case', 'court','hearing_date','hearing_type','notes','outcome', 'actions']);
const items = ref([]);
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
    sortable: ['case', 'court','hearing_date','hearing_type','notes','outcome'],
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

const client = ref({id: "",case_id:"",court_id : "", hearing_date:"",hearing_type:"", notes:"", outcome:""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.case_id && client.value.hearing_type && client.value.hearing_date && client.value.court_id) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/hearings/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Expense added successfully.');
                    // Optionally, you can update the UI or perform other actions after successful insertion
                })
                .catch(error => {
                    showMessage('Error adding expense. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/hearings', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Expense added successfully.');
                    // Optionally, you can update the UI or perform other actions after successful insertion
                })
                .catch(error => {
                    showMessage('Error adding expense. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        modal.value.title = "Add Hearing";
        modal.value.button = "Add";

    }
};

const fetchData = () => {
    modal.value.title = "Add Hearing";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/hearings')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });

    // Fetch list of advocates
    axios.get('/api/casesDropDown')
        .then(response => {
            cases.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching roles:', error);
        });
    axios.get('/api/courts')
        .then(response => {
            courts.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
        });


};
const update_row = (item) => {
    client.value.id = item.id;
    client.value.case_id = item.case_id;
    client.value.court_id = item.court_id;
    client.value.hearing_date = item.hearing_date;
    client.value.notes = item.notes;
    client.value.outcome = item.outcome;
    client.value.hearing_type = item.hearing_type;

    modal.value.title = "Update Hearing";
    modal.value.button = "Update";
};
</script>
