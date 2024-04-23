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
                                    <h6>Case Information</h6>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault01">Case Number</label>
                                        <input type="text" v-model="caseD.case_number"  class="form-control" id="validationDefault01" placeholder="First name" readonly />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Client </label>
                                        <input type="text" v-model="caseD.client" class="form-control" id="validationDefault02" placeholder="Last name" readonly />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Advocate</label>
                                        <input type="text" v-model="caseD.assigned" class="form-control" id="validationDefault02" placeholder="Last name" readonly />
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label for="validationDefault02">Case Type</label>
                                        <input type="text" v-model="caseD.type" class="form-control" id="validationDefault02" placeholder="Last name" readonly />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Start Date</label>
                                        <input type="text" v-model="caseD.start_date" class="form-control" id="validationDefault03" placeholder="Phone Number" readonly />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault04">Police Station</label>
                                        <input type="text" v-model="caseD.police_station" class="form-control" id="validationDefault04" placeholder="Advocate" readonly />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault03">Court</label>
                                        <input type="text" v-model="caseD.court"  class="form-control" id="validationDefault03" placeholder="City" readonly />
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="validationDefault04">Opposing Party</label>
                                        <input type="text" v-model="caseD.opposing_party" class="form-control" id="validationDefault04" placeholder="Address" readonly />
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-12 form-group">
                                        <label for="validationDefault03">Description</label>
                                        <textarea class="form-control" v-model="caseD.description" id="validationDefault03" rows="12" readonly>Description</textarea>
                                    </div>
                                </div>
                                <!--                                <button class="btn btn-primary mt-3" type="submit">Update</button>-->
                            </form>

                        </div>

                        <div class="col-xl-12 col-lg-12 col-md-12 layout-spacing">
                            <form id="about" class="section about">
                                <div class="info">
                                    <h5 class="">Case Documents</h5>
                                    <div class="col-md-12 text-end mb-5">
                                        <button type="button" id="add-education" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clientD_modal">Add</button>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-11 mx-auto">
                                            <div class="custom-table">
                                                <v-client-table :data="itemsD" :columns="columnsD" :options="table_optionD">

                                                    <template #actions="props">
                                                        <div class="actions text-center">
                                                            <a href="javascript:;" class="cancel" @click="preview(props.row.id)">
                                                                <button type="button" class="btn btn-primary btn-sm">Preview</button>
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
                            </form>
                        </div>

                        <div class="col-xl-12 col-lg-12 col-md-12 layout-spacing">
                            <form id="about" class="section about">
                                <div class="info">
                                    <h5 class="">Case Hearings</h5>
                                    <div class="col-md-12 text-end mb-5">
                                        <button type="button" id="add-education" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clientH_modal">Add</button>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-11 mx-auto">
                                            <div class="custom-table">
                                                <v-client-table :data="itemsH" :columns="columnsH" :options="table_optionH">
                                                    <template #actions="props">
                                                        <div class="actions text-center">
                                                            <a href="javascript:;" class="cancel" @click="view_row(props.row.id)">
                                                                <button type="button" class="btn btn-primary btn-sm">View</button>
                                                            </a>
                                                            <a href="javascript:;" class="cancel me-1" @click="update_rowH(props.row)">
                                                                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#clientH_modal">Update</button>
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

                        <div class="col-xl-12 col-lg-12 col-md-12 layout-spacing">
                            <form id="about" class="section about">
                                <div class="info">
                                    <h5 class="">Case Expenses</h5>
<!--                                    <div class="col-md-12 text-end mb-5">-->
<!--                                        <button type="button" id="add-education" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#clientH_modal">Add</button>-->
<!--                                    </div>-->
                                    <div class="row">
                                        <div class="col-md-11 mx-auto">
                                            <div class="custom-table">
                                                <v-client-table :data="itemsE" :columns="columnsE" :options="table_optionE">
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
                            </form>
                        </div>


                    </div>
                </div>
            </div>

            <div class="account-settings-footer">
                <div class="as-footer-container">
                    <button type="button" class="btn btn-primary">Reset All</button>
                    <button type="button" class="btn btn-success" @click="save()">Save Changes</button>
                </div>
            </div>
        </div>



        <!-- Add Modal -->
        <div class="modal fade " id="clientH_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div  class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header" id="registerModalLabel">
                        <h4 class="modal-title">{{ modalH.title }}</h4>
                        <button id="closebtnH" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="needs-validation" novalidate @submit.stop.prevent="submit_form4H">
                            <div class="row">
                                <div id="select_menu" class="form-group form-group">
                                    <select v-model="clientH.court_id" class="form-select" :class="[is_submit_form4H ? (clientH.court_id ? 'is-valid' : 'is-invalid') : '']">
                                        <option value="">Select Court</option>
                                        <option v-for="data in courts" :key="data.id" :value="data.id">{{ data.name }}</option>
                                    </select>
                                    <div class="invalid-feedback">Please Select the Court</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <div class="form-group">
                                        <label for="date">Hearing Date="'##/##/####'" (dd/mm/yyyy)</label>
                                        <input v-model="clientH.hearing_date" type="text" id="date" class="form-control"
                                               :class="[is_submit_form4H ? (clientH.hearing_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date.</div>
                                    </div>

                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Hearing Type</label>
                                    <input
                                        type="text"
                                        v-model="clientH.hearing_type"
                                        class="form-control"
                                        :class="[is_submit_form4H ? (clientH.hearing_type ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Hearing Type"
                                    />
                                    <div class="invalid-feedback">Please fill the hearing type</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Notes</label>
                                    <input
                                        type="text"
                                        v-model="clientH.notes"
                                        class="form-control"
                                        :class="[is_submit_form4H ? (clientH.notes ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Notes"
                                    />
                                    <div class="invalid-feedback">Please fill the Notes</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Outcomes</label>
                                    <input
                                        type="text"
                                        v-model="clientH.outcome"
                                        class="form-control"
                                        :class="[is_submit_form4H ? (clientH.outcome ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Description"
                                    />
                                    <div class="invalid-feedback">Please fill the outcomes</div>
                                </div>

                            </div>
                            <button class="btn btn-primary mt-3" type="submit">{{ modalH.button }}</button>
                        </form>


                    </div>
                </div>
            </div>
        </div>

        <!-- Add Modal -->
        <div class="modal fade " id="clientD_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div  class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header" id="registerModalLabel">
                        <h4 class="modal-title">{{ modalD.title }}</h4>
                        <button id="closebtnD" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="needs-validation" novalidate @submit.stop.prevent="submit_form4D">
                            <div class="row">
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">Title</label>
                                    <input
                                        type="text"
                                        v-model="clientD.title"
                                        class="form-control"
                                        :class="[is_submit_form4D? (clientD.title ? 'is-valid' : 'is-invalid') : '']"
                                        id="validationCustom01"
                                        placeholder="Description"
                                    />
                                    <div class="invalid-feedback">Please fill the title</div>
                                </div>
                                <div class="col-md-12 form-group">
                                    <label for="validationCustom01">File Upload</label>
                                    <label class="custom-file-container__custom-file">
                                        <input type="file" class="custom-file-container__custom-file__custom-file-input" @change="handleFileInputChange" />
                                        <input type="hidden" name="MAX_FILE_SIZE" value="10485760" />
                                        <span class="custom-file-container__custom-file__custom-file-control"></span>
                                    </label>
                                    <div class="custom-file-container__image-preview"></div>

                                </div>

                            </div>
                            <button class="btn btn-primary mt-3" type="submit">{{ modalD.button }}</button>
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
import axios from "../../../api";
useMeta({ title: 'Account Setting' });

const columns = ref(['case_number', 'description', 'client', 'attorney', 'start_date', 'end_date','case_type','police_station','court','opposing_party', 'actions']);
const items = ref([]);
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

const columnsH = ref(['case', 'court','hearing_date','hearing_type','notes','outcome', 'actions']);
const itemsH = ref([]);
const table_optionH = ref({
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

const columnsD = ref(['title', 'filename',  'actions']);
const itemsD = ref([]);
const table_optionD = ref({
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
    sortable: ['title', 'filename'],
    sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc',
    },
    resizableColumns: false,
});

const itemsE = ref([]);
const columnsE = ref(['case', 'expense_date','amount','category','description','vendor','payment_method','invoice_number','user', 'actions']);
const table_optionE = ref({
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

const modalH = ref({title: "", button: ""});
const modalD = ref({title: "", button: ""});

const caseD = ref({});
const courts = ref([]);

const is_submit_form4D = ref(false);
const router = useRouter();

const selected = ref(null);
const selected_file = ref(null);
const ddl_1 = ref('20');
const ddl_2 = ref('Jan');
const ddl_3 = ref('1989');
const ddl_4 = ref('May');
const ddl_5 = ref('2009');
const ddl_6 = ref('United States');
const months = ref(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
const range_1 = ref(25);
const range_2 = ref(50);
const range_3 = ref(70);
const range_4 = ref(60);

const change_file = (event) => {
    selected_file.value = event.target.files[0];
};

const save = () => {
    showMessage('Settings saved successfully.');
};

const handleFileInputChange = (event) => {
    selected_file.value = event.target.files[0];
};

const is_submit_form4H = ref(false);
const clientH = ref({id: "",case_id:"",court_id : "", hearing_date:"",hearing_type:"", notes:"", outcome:""});
const submit_form4H = () => {
    is_submit_form4H.value = true;
    clientH.value.case_id = localStorage.getItem('caseId');
    if (clientH.value.case_id && clientH.value.hearing_type && clientH.value.hearing_date && clientH.value.court_id) {
        //form validated success
        if(clientH.value.id > 0)//id exists then update
        {
            axios.put(`/api/hearings/${clientH.value.id}`, clientH.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    itemsH.value = response.data.data;
                    showMessage('Hearing updated successfully.');
                    document.getElementById('closebtnH').click();
                })
                .catch(error => {
                    showMessage('Error updating hearing. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/hearings', clientH.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    itemsH.value = response.data.data;
                    showMessage('Hearing added successfully.');
                    document.getElementById('closebtnH').click();
                })
                .catch(error => {
                    showMessage('Error adding hearing. Please try again.');
                    console.error(error);
                });
        }

        clientH.value.id = "";
        clientH.value.hearing_date = "";
        clientH.value.hearing_type = "";
        clientH.value.notes = "";
        clientH.value.outcome = "";
        modalH.value.title = "Add Hearing";
        modalH.value.button = "Add";
        is_submit_form4H.value = false;

    }
};

const clientD = ref({id: "",title:"", case_id:"",file:null});
const submit_form4D = () => {
    is_submit_form4D.value = true;
    clientD.value.case_id = localStorage.getItem('caseId')
    if (clientD.value.case_id && clientD.value.title ) {
        //form validated success
        if(clientD.value.id > 0)//id exists then update
        {

            axios.put(`/api/documents/${clientH.value.id}`, clientH.value) // Assuming your API endpoint for client creation is /api/clients
                .then(response => {
                    itemsD.value = response.data.data;
                    showMessage('Case Document updated successfully.');
                    document.getElementById('closebtnD').click();
                })
                .catch(error => {
                    showMessage('Error updating document. Please try again.');
                    console.error(error);
                });
        } else {
            let formData = new FormData();
            formData.append('case_id', clientD.value.case_id);
            formData.append('title', clientD.value.title);
            formData.append('file', selected_file.value); // Append the selected file

            axios.post('/api/documents',  formData, {
                headers: {
                    'Content-Type': 'multipart/form-data' // Set content type for FormData
                }
            }).then(response => {
                itemsD.value = response.data.data;
                showMessage('Doc added successfully.');
                document.getElementById('closebtnD').click();
            })
                .catch(error => {
                    showMessage('Error adding doc. Please try again.');
                    console.error(error);
                });
        }

        clientD.value.id = "";
        clientD.value.title = "";
        clientD.value.file = null;
        selected_file.value = null;
        modalD.value.title = "Add Document";
        modalD.value.button = "Add";
        is_submit_form4D.value = false;

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
const update_rowH = (item) => {
    clientH.value.id = item.id;
    clientH.value.case_id = localStorage.getItem('caseId');
    clientH.value.court_id = item.court_id;
    clientH.value.hearing_date = item.hearing_date;
    clientH.value.notes = item.notes;
    clientH.value.outcome = item.outcome;
    clientH.value.hearing_type = item.hearing_type;

    modalH.value.title = "Update Hearing";
    modalH.value.button = "Update";
};

const update_rowD = (item) => {
    clientD.value.id = item.id;
    clientD.value.title = item.title;

    modalD.value.title = "Update Document";
    modalD.value.button = "Update";
};

const fetchData = () => {
    modalH.value.title = "Add Hearing";
    modalH.value.button = "Add";

    modalD.value.title = "Add Document";
    modalD.value.button = "Add";
    // Fetch list of clients
    axios.get('/api/hearings')
        .then(response => {
            itemsH.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching hearings:', error);
        });

    axios.get('/api/expenses')
        .then(response => {
            itemsE.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching expenses:', error);
        });

    let caseId = localStorage.getItem('caseId');
    axios.get(`/api/documents/${caseId}`)
        .then(response => {
            itemsD.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching docs:', error);
        });

    // Fetch list of advocates
    axios.get(`/api/cases/${caseId}`)
        .then(response => {
            caseD.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching case:', error);
        });
    axios.get('/api/courts')
        .then(response => {
            courts.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching case:', error);
        });


};


onMounted(() => {
    //bind_data();
    fetchData();
});
</script>
