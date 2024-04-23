<template>
    <div class="layout-px-spacing">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Users</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Account Settings</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="account-settings-container layout-top-spacing">
            <div class="account-content">
                <div class="scrollspy-example" data-spy="scroll" data-target="#account-settings-scroll" data-offset="-100">
                    <div class="row">
                        <div class="col-xl-12 col-lg-12 col-md-12 layout-spacing">
                            <form @submit.stop.prevent="submit_form5">
                                <div class="info">
                                    <h6>Invoice Information</h6>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault01">Case Number</label>
                                        <input type="text" v-model="invoice.case_number"  class="form-control" id="validationDefault01" placeholder="Case Number" readonly />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Client </label>
                                        <input type="text" v-model="invoice.client" class="form-control" id="validationDefault02" placeholder="Client Name" readonly />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Invoice Date</label>
                                        <input type="text" v-model="invoice.invoice_date" class="form-control" id="validationDefault03" placeholder="Phone Number" readonly />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Invoice Due Date</label>
                                        <input type="text" v-model="invoice.invoice_due_date" class="form-control" id="validationDefault03" placeholder="Phone Number" readonly />
                                    </div>
                                </div>
                            </form>

                        </div>

                        <div class="col-xl-12 col-lg-12 col-md-12 layout-spacing">
                            <form id="about" class="section about">
                                <div class="info">
                                    <h5 class="">Invoice Items</h5>
                                    <div class="col-md-12 text-end mb-5">
                                        <button type="button" id="preview-invoice" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clientI_modal">Preview</button>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-11 mx-auto">
                                            <div class="custom-table">
                                                <v-client-table :data="itemsI" :columns="columnsI" :options="table_optionI">
                                                    <template #actions="props">
                                                        <div class="actions text-center">
                                                            <a href="javascript:;" class="cancel me-1" @click="update_rowI(props.row)">
                                                                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#clientI_modal">Update</button>
                                                            </a>
                                                        </div>
                                                    </template>
                                                    <template #salary="props"> ${{ props.row.salary }} </template>
                                                </v-client-table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>

            <div class="account-settings-footer">
                <div class="as-footer-container">
                    <button type="button" class="btn btn-success" @click="view()">View</button>
                </div>
            </div>
        </div>



        <!-- Add Modal -->
        <div class="modal fade " id="clientI_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
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
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Quantity</label>
                                    <input
                                        type="number"
                                        step="0.001"
                                        v-model="client.quantity"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.quantity ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Quantity"
                                    />
                                    <div class="invalid-feedback">Please fill the quantity</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Rate</label>
                                    <input
                                        type="number"
                                        step="0.001"
                                        v-model="client.rate"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.rate ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Rate"
                                    />
                                    <div class="invalid-feedback">Please fill the rate</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Tax</label>
                                    <input
                                        type="number"
                                        step="0.001"
                                        v-model="client.tax"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.tax ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Tax"
                                    />
                                    <div class="invalid-feedback">Please fill the tax</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Total Amount</label>
                                    <input
                                        type="number"
                                        step="0.001"
                                        v-model="client.total_amount"
                                        class="form-control"
                                        :class="[is_submit_form4 ? (client.total_amount ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Total Amount"
                                    />
                                    <div class="invalid-feedback">Please fill the total Amount</div>
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
import {onMounted, ref} from 'vue';
import '@/assets/sass/scrollspyNav.scss';
import '@/assets/sass/users/account-setting.scss';

import { useMeta } from '@/composables/use-meta';
import {useRouter} from "vue-router";
import axios from "../../../../api";
useMeta({ title: 'Invoice Items' });

const columnsI = ref(['description', 'quantity', 'rate', 'tax', 'total_amount', 'actions']);
const itemsI = ref([]);
const table_optionI = ref({
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
    sortable: ['description', 'quantity', 'rate', 'tax', 'total_amount'],
    sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc',
    },
    resizableColumns: false,
});


const modal = ref({title: "", button: ""});

const invoice = ref({});

const is_submit_form4 = ref(false);
const router = useRouter();

const save = () => {
    showMessage('Settings saved successfully.');
};


const clientH = ref({id: "",invoice_id:"",description : "", quantity:"",rate:"", tax:"", total_amount:""});
const submit_form4 = () => {
    is_submit_form4.value = true;
    client.value.invoice_id = localStorage.getItem('invoiceId');
    if (client.value.invoice_id && client.value.description && client.value.tax && client.value.rate && client.value.total_amount) {
        //form validated success
        if(client.value.id > 0)//id exists then update
        {
            axios.put(`/api/invoiceItems/${client.value.id}`, client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Item updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating item. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/invoiceItems', client.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    itemsI.value = response.data.data;
                    showMessage('Item added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding item. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.description = "";
        client.value.quantity = "";
        client.value.tax = "";
        client.value.rate = "";
        modal.value.total_amount = "";
        modal.value.title = "Add Item";
        modal.value.button = "Add";
        is_submit_form4.value = false;

    }
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

const preview = (id) => {
    axios.get('/api/preview', {
        params: {
            id : id
        },
        responseType: 'blob'
    }) // Assuming your API endpoint for client creation is /api/clients
        .then(response => {
            const blob = new Blob([response.data], {type:'application/pdf'  });
            const url = window.URL.createObjectURL(blob);

            window.open(url);
        })
        .catch(error => {
            showMessage('Error viewing document. Please try again.');
            console.error(error);
        });
}
const update_rowI = (item) => {
    client.value.id = item.id;
    client.value.description = item.description;
    client.value.quantity = item.quantity;
    client.value.tax = item.tax;
    client.value.rate = item.rate;
    client.value.total_amount = item.total_amount;

    modal.value.title = "Update Item";
    modal.value.button = "Update";
};

const fetchData = () => {
    modal.value.title = "Add Item";
    modal.value.button = "Add";


    let invoiceId = localStorage.getItem('invoiceId');
    axios.get(`/api/invoiceItems/${invoiceId}`)
        .then(response => {
            itemsI.value = response.data.data[0];
        })
        .catch(error => {
            console.error('Error fetching items:', error);
        });


    axios.get(`/api/invoices/${invoiceId}`)
        .then(response => {
            invoice.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching invoice:', error);
        });


};


onMounted(() => {
    fetchData();
});
</script>
