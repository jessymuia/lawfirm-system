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
                <button type="button" class="btn btn-secondary mb-2" data-bs-toggle="modal" data-bs-target="#client_modal">Add Expense</button>
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
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Expense Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="client.expense_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4 ? (client.expense_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Amount</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        v-model="client.amount"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.amount ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Amount"
                                    />
                                    <div class="invalid-feedback">Please fill the amount</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Category</label>
                                    <input
                                        type="text"
                                        v-model="client.category"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.category ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Category"
                                    />
                                    <div class="invalid-feedback">Please fill the category</div>
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
                                        <select v-model="client.user_id" class="form-select" :class="[is_submit_form4 ? (client.user_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select User</option>
                                            <option v-for="data in users" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please Select the User</div>
                                    </div>
                                </div>

                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Vendor</label>
                                    <input
                                        type="text"
                                        v-model="client.vendor"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.vendor ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Vendor"
                                    />
                                    <div class="invalid-feedback">Please provide a valid Vendor</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Payment Method</label>
                                    <input
                                        type="text"
                                        v-model="client.payment_method"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.payment_method ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Payment Method"
                                    />
                                    <div class="invalid-feedback">Please provide a valid payment method</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom03">Invoice Number</label>
                                    <input
                                        type="text"
                                        v-model="client.invoice_number"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.invoice_number ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom03"
                                        placeholder="Invoice Number"
                                    />
                                    <div class="invalid-feedback">Please provide a valid invoice number</div>
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
useMeta({ title: 'Courts' });

const columns = ref(['case', 'expense_date','amount','category','description','vendor','payment_method','invoice_number','user', 'actions']);
const items = ref([]);
const cases = ref([]); // List of advocates
const users = ref([]);
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
    sortable: ['case', 'expense_date','amount','category','description','vendor','payment_method','invoice_number','user'],
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

const client = ref({id: "",case_id:"",expense_date : "",amount:"",category:"",description:"",vendor:"",payment_method:"",invoice_number:"",user_id:""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.case_id  && client.value.expense_date && client.value.payment_method) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/expenses/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
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
            axios.post('/api/expenses', client.value) // Assuming your API endpoint for client creation is /api/clients
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
        modal.value.title = "Add Expense";
        modal.value.button = "Add";

    }
};

const fetchData = () => {
    modal.value.title = "Add Expense";
    modal.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/expenses')
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

    axios.get('/api/advocates')
        .then(response => {
            users.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching advocates:', error);
        });
};
const update_row = (item) => {
    client.value.id = item.id;
    client.value.case_id = item.case_id;
    client.value.expense_date = item.expense_date;
    client.value.amount = item.value;
    client.value.category = item.category;
    client.value.description = item.description;
    client.value.vendor = item.vendor;
    client.value.payment_method = item.payment_method;
    client.value.invoice_number = item.invoice_number;
    client.value.user_id = item.user_id;

    modal.value.title = "Update Expense";
    modal.value.button = "Update";

};
</script>
