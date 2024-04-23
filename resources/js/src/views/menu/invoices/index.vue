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
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Invoice</button>
            </div>
        </div>

        <div class="row layout-top-spacing">
            <div class="col-xl-12 col-lg-12 col-sm-12 layout-spacing">
                <div class="panel br-6 p-0">
                    <div class="custom-table">
                        <v-client-table :data="items" :columns="columns" :options="table_option">
                            <template #actions="props">
                                <div class="actions text-center">
                                    <a href="javascript:" class="cancel" @click="view_row(props.row.id)">
                                       <button type="button" class="btn btn-primary btn-sm">Add Items</button>
                                    </a>
                                    <a href="javascript:" class="cancel me-1" @click="update_row(props.row)">
                                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#client_modal">Update</button>
                                    </a>
                                    <a href="javascript:" class="cancel me-1" @click="preview_row(props.row.id)">
                                        <button type="button" class="btn btn-success btn-sm">Preview</button>
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
        <div class="modal fade " id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div  class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header" id="registerModalLabel">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button  id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
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
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Invoice Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.invoice_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.invoice_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Invoice Due Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.invoice_due_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.invoice_due_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

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

const columns = ref(['case', 'client','invoice_date','invoice_due_date','actions']);
const items = ref([]);
const cases = ref([]); // List of advocates
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
    sortable: ['case', 'client','invoice_date','invoice_due_date'],
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
    if (client.value.case_id && client.value.invoice_date && client.value.invoice_due_date && client.value.client_id) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/invoices/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Invoice updated successfully.');

                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating invoice. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/invoices', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Invoice aded successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding invoice. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.client_id = "";
        client.value.case_id = "";
        client.value.invoice_date = "";
        client.value.invoice_due_date = "";
        modal.value.title = "Add Invoice";
        modal.value.button = "Add";
        is_submit_form4.value = false;

    }
};

const fetchData = () => {
    modal.value.title = "Add Hearing";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/invoices')
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
    client.value.client_id = item.client_id;
    client.value.case_id = item.case_id;
    client.value.invoice_date = item.invoice_date;
    client.value.invoice_due_date = item.invoice_due_date;

    modal.value.title = "Update Hearing";
    modal.value.button = "Update";
};

const view_row = (itemId) => {
    localStorage.setItem("invoiceId", itemId);
    router.push("/view-invoice");
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
