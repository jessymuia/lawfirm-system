<template>
    <div class="modern-cases-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Cases</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-actions">
            <div class="header-content">
                <h2 class="page-title">Cases Management</h2>
                <p class="page-subtitle">Track and manage all your legal cases</p>
            </div>
            <button type="button" class="btn-add-case" data-bs-toggle="modal" data-bs-target="#client_modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New Case
            </button>
        </div>

        <div class="cases-table-card">
            <div class="table-header">
                <h3 class="table-title">All Cases</h3>
                <div class="table-stats">
                    <span class="stat-badge">{{ items.length }} Total</span>
                </div>
            </div>
            
            <div class="table-wrapper">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #case_number="props">
                        <div class="case-number-cell">
                            <div class="case-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                </svg>
                            </div>
                            <span class="case-number-text">{{ props.row.case_number }}</span>
                        </div>
                    </template>
                    
                    <template #client="props">
                        <span class="client-name">{{ props.row.client }}</span>
                    </template>
                    
                    <template #attorney="props">
                        <span class="attorney-badge">{{ props.row.attorney }}</span>
                    </template>
                    
                    <template #start_date="props">
                        <span class="date-text">{{ props.row.start_date }}</span>
                    </template>
                    
                    <template #case_type="props">
                        <span class="type-badge">{{ props.row.case_type }}</span>
                    </template>
                    
                    <template #opposing_party="props">
                        <span class="opposing-text">{{ props.row.opposing_party }}</span>
                    </template>
                    
                    <template #actions="props">
                        <div class="action-buttons">
                            <button type="button" class="btn-action btn-view" @click="view_row(props.row.id)" title="View Details">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                            <button type="button" class="btn-action btn-edit" @click="update_row(props.row)" data-bs-toggle="modal" data-bs-target="#client_modal" title="Edit Case">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                        </div>
                    </template>
                </v-client-table>
            </div>
        </div>

        <div class="modal fade" id="client_modal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <div class="modal-title-wrapper">
                            <div class="modal-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                </svg>
                            </div>
                            <div>
                                <h4 class="modal-title">{{ modal.title }}</h4>
                                <p class="modal-subtitle">{{ modal.title === 'Add Case' ? 'Enter case information below' : 'Update case details' }}</p>
                            </div>
                        </div>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close-modern">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="modal-body">
                        <form class="modern-form" @submit.stop.prevent="submit_form4">
                            <div class="form-section">
                                <h5 class="section-title">Case Details</h5>
                                <div class="form-grid grid-2">
                                    <div class="form-group">
                                        <label class="form-label">Case Number <span class="required">*</span></label>
                                        <input type="text" v-model="client.case_number" class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.case_number ? 'is-valid' : 'is-invalid') : '']"
                                            placeholder="Enter case number"/>
                                        <div class="invalid-feedback">Case number is required</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Description <span class="required">*</span></label>
                                        <input type="text" v-model="client.description" class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.description ? 'is-valid' : 'is-invalid') : '']"
                                            placeholder="Brief description"/>
                                        <div class="invalid-feedback">Description is required</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Party Information</h5>
                                <div class="form-grid grid-2">
                                    <div class="form-group">
                                        <label class="form-label">Client <span class="required">*</span></label>
                                        <select v-model="client.client_id" class="form-select modern-select"
                                            :class="[is_submit_form4 ? (client.client_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Client</option>
                                            <option v-for="data in clients" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select a client</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Attorney <span class="required">*</span></label>
                                        <select v-model="client.assigned_to" class="form-select modern-select"
                                            :class="[is_submit_form4 ? (client.assigned_to ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Attorney</option>
                                            <option v-for="data in advocates" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select an attorney</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Case Timeline & Details</h5>
                                <div class="form-grid grid-2">
                                    <div class="form-group">
                                        <label class="form-label">Start Date <span class="required">*</span></label>
                                        <input v-model="client.start_date" type="text" class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.start_date ? 'is-valid' : 'is-invalid') : '']"
                                            v-maska="'##/##/####'" placeholder="DD/MM/YYYY"/>
                                        <div class="invalid-feedback">Start date is required</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">End Date</label>
                                        <input v-model="client.end_date" type="text" class="form-control modern-input"
                                            v-maska="'##/##/####'" placeholder="DD/MM/YYYY (Optional)"/>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Case Type <span class="required">*</span></label>
                                        <input type="text" v-model="client.case_type" class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.case_type ? 'is-valid' : 'is-invalid') : '']"
                                            placeholder="e.g., Civil, Criminal"/>
                                        <div class="invalid-feedback">Case type is required</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Police Station <span class="required">*</span></label>
                                        <input type="text" v-model="client.police_station" class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.police_station ? 'is-valid' : 'is-invalid') : '']"
                                            placeholder="Station name"/>
                                        <div class="invalid-feedback">Police station is required</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Court <span class="required">*</span></label>
                                        <select v-model="client.court_id" class="form-select modern-select"
                                            :class="[is_submit_form4 ? (client.court_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Court</option>
                                            <option v-for="data in courts" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select a court</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Opposing Party <span class="required">*</span></label>
                                        <input type="text" v-model="client.opposing_party" class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.opposing_party ? 'is-valid' : 'is-invalid') : '']"
                                            placeholder="Opposing party name"/>
                                        <div class="invalid-feedback">Opposing party is required</div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer-actions">
                                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn-submit" type="submit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    {{ modal.button }}
                                </button>
                            </div>
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

useMeta({ title: 'Cases' });

const router = useRouter();

const columns = ref(['case_number', 'client', 'attorney', 'start_date','case_type','opposing_party', 'actions']);
const items = ref([]);
const courts = ref([]);
const advocates = ref([]);
const clients = ref([]);
const modal = ref({title: "Add Case", button: "Add"});
const is_submit_form4 = ref(false);

const client = ref({
    id: "",
    case_number:"",
    description: "",
    client_id: "",
    assigned_to: "",
    start_date: "",
    end_date: "",
    case_type:"",
    police_station:"",
    court_id: "",
    opposing_party:""
});

const table_option = ref({
    perPage: 10,
    perPageValues: [5, 10, 20, 50],
    skin: 'table table-hover modern-table',
    columnsClasses: { actions: 'actions-column' },
    pagination: { nav: 'scroll', chunk: 5 },
    texts: {
        count: 'Showing {from} to {to} of {count}',
        filter: '',
        filterPlaceholder: 'Search cases...',
        limit: 'Show:',
    },
    sortable: ['case_number', 'client', 'attorney', 'start_date','case_type','opposing_party'],
    sortIcon: {
        base: 'sort-icon-none',
        up: 'sort-icon-asc',
        down: 'sort-icon-desc',
    },
    resizableColumns: false,
});

onMounted(() => {
    fetchData();
});

const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.case_number && client.value.client_id  && client.value.assigned_to && client.value.start_date && client.value.police_station && client.value.court_id && client.value.case_type) {
        if(client.value.id > 0) {
            axios.put(`/api/cases/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Case updated successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                })
                .catch(error => {
                    showMessage('Error updating case. Please try again.', 'error');
                    console.error(error);
                });
        } else {
            axios.post('/api/cases', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Case added successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                })
                .catch(error => {
                    showMessage('Error adding case. Please try again.', 'error');
                    console.error(error);
                });
        }
    }
};

const fetchData = () => {
    axios.get('/api/cases')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching cases:', error);
        });

    axios.get('/api/courts')
        .then(response => {
            courts.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching courts:', error);
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
            console.error('Error fetching clients:', error);
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

const resetForm = () => {
    client.value = {
        id: "",
        case_number:"",
        description: "",
        client_id: "",
        assigned_to: "",
        start_date: "",
        end_date: "",
        case_type:"",
        police_station:"",
        court_id: "",
        opposing_party:""
    };
    modal.value.title = "Add Case";
    modal.value.button = "Add";
    is_submit_form4.value = false;
};

const showMessage = (msg = '', type = 'success') => {
    const toast = window.Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
    });
    toast.fire({
        icon: type,
        title: msg,
        padding: '12px 20px',
    });
};
</script>

<style lang="scss" scoped>
/* Modern Cases Page */

.modern-cases-page {
    padding: 24px;
    background: #f9fafb;
    min-height: 100vh;
}

.page-header-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    padding: 24px;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.header-content .page-title {
    font-size: 28px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 4px 0;
}

.header-content .page-subtitle {
    font-size: 14px;
    color: #6b7280;
    margin: 0;
}

.btn-add-case {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-add-case:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
}

.cases-table-card {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.table-header .table-title {
    font-size: 18px;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
}

.table-header .stat-badge {
    padding: 6px 12px;
    background: #d1fae5;
    color: #10b981;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.case-number-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.case-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #eef2ff;
    color: #4f46e5;
    display: flex;
    align-items: center;
    justify-content: center;
}

.case-number-text {
    font-weight: 600;
    color: #1f2937;
}

.client-name {
    color: #1f2937;
    font-weight: 500;
}

.attorney-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #fef3c7;
    color: #f59e0b;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.date-text {
    color: #6b7280;
    font-size: 14px;
}

.type-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #e0f2fe;
    color: #0ea5e9;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.opposing-text {
    color: #6b7280;
    font-size: 14px;
}

.action-buttons {
    display: flex;
    gap: 8px;
    justify-content: center;
}

.btn-action {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-action.btn-view {
    background: #e0f2fe;
    color: #0ea5e9;
}

.btn-action.btn-view:hover {
    background: #0ea5e9;
    color: white;
    transform: translateY(-2px);
}

.btn-action.btn-edit {
    background: #fef3c7;
    color: #f59e0b;
}

.btn-action.btn-edit:hover {
    background: #f59e0b;
    color: white;
    transform: translateY(-2px);
}

.modern-modal {
    border: none;
    border-radius: 16px;
}

.modern-modal .modal-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
    background: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.modal-title-wrapper {
    display: flex;
    align-items: center;
    gap: 16px;
}

.modal-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: #d1fae5;
    color: #10b981;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-title {
    font-size: 20px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.modal-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin: 4px 0 0 0;
}

.btn-close-modern {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-close-modern:hover {
    background: #fee2e2;
    color: #ef4444;
}

.modern-modal .modal-body {
    padding: 24px;
}

.form-section {
    margin-bottom: 28px;
}

.form-section .section-title {
    font-size: 15px;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 16px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}

.form-grid {
    display: grid;
    gap: 20px;
}

.form-grid.grid-2 {
    grid-template-columns: repeat(2, 1fr);
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: 8px;
}

.form-label .required {
    color: #ef4444;
    margin-left: 2px;
}

.modern-input,
.modern-select {
    width: 100%;
    padding: 12px 16px;
    border: 1.5px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    color: #1f2937;
    background: #ffffff;
    transition: all 0.2s ease;
}

.modern-input:focus,
.modern-select:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 3px #d1fae5;
}

.modern-input.is-invalid,
.modern-select.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid,
.modern-select.is-valid {
    border-color: #10b981;
}

.invalid-feedback {
    display: none;
    font-size: 12px;
    color: #ef4444;
    margin-top: 6px;
}

.is-invalid ~ .invalid-feedback {
    display: block;
}

.modal-footer-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
}

.btn-cancel {
    padding: 10px 20px;
    background: #f3f4f6;
    color: #6b7280;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-cancel:hover {
    background: #e5e7eb;
}

.btn-submit {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 24px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

@media (max-width: 768px) {
    .modern-cases-page {
        padding: 16px;
    }
    
    .page-header-actions {
        flex-direction: column;
        gap: 16px;
        padding: 20px;
    }
    
    .btn-add-case {
        width: 100%;
        justify-content: center;
    }
    
    .form-grid.grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>