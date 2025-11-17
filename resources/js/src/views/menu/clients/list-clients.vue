<template>
    <div class="modern-clients-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Clients</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-actions">
            <div class="header-content">
                <h2 class="page-title">Client Management</h2>
                <p class="page-subtitle">Manage and organize your client information</p>
            </div>
            <button type="button" class="btn-add-client" data-bs-toggle="modal" data-bs-target="#client_modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New Client
            </button>
        </div>

        <div class="clients-table-card">
            <div class="table-header">
                <h3 class="table-title">All Clients</h3>
                <div class="table-stats">
                    <span class="stat-badge">{{ items.length }} Total</span>
                </div>
            </div>
            
            <div class="table-wrapper">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #name="props">
                        <div class="client-name-cell">
                            <div class="client-avatar">
                                {{ props.row.name ? props.row.name.charAt(0).toUpperCase() : 'C' }}
                            </div>
                            <span class="client-name-text">{{ props.row.name }}</span>
                        </div>
                    </template>
                    
                    <template #phone_number="props">
                        <div class="contact-cell">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                            </svg>
                            <span>{{ props.row.phone_number }}</span>
                        </div>
                    </template>
                    
                    <template #extra_phone_number="props">
                        <div class="contact-cell secondary" v-if="props.row.extra_phone_number">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                            </svg>
                            <span>{{ props.row.extra_phone_number }}</span>
                        </div>
                        <span v-else class="no-data">—</span>
                    </template>
                    
                    <template #address="props">
                        <div class="address-cell">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <span>{{ props.row.address }}</span>
                        </div>
                    </template>
                    
                    <template #advocate="props">
                        <span class="advocate-badge">{{ props.row.advocate }}</span>
                    </template>
                    
                    <template #actions="props">
                        <div class="action-buttons">
                            <button type="button" class="btn-action btn-view" @click="view_row(props.row)" title="View Details">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                            <button type="button" class="btn-action btn-edit" @click="update_row(props.row)" data-bs-toggle="modal" data-bs-target="#client_modal" title="Edit Client">
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
            <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <div class="modal-title-wrapper">
                            <div class="modal-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            </div>
                            <div>
                                <h4 class="modal-title">{{ modal.title }}</h4>
                                <p class="modal-subtitle">{{ modal.title === 'Add Client' ? 'Enter client information below' : 'Update client details' }}</p>
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
                                <h5 class="section-title">Personal Information</h5>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="clientName" class="form-label">
                                            Full Name <span class="required">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            v-model="client.name"
                                            class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '']"
                                            id="clientName"
                                            placeholder="Enter full name"
                                        />
                                        <div class="invalid-feedback">Please enter client name</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Contact Information</h5>
                                <div class="form-grid grid-2">
                                    <div class="form-group">
                                        <label for="phoneNumber" class="form-label">
                                            Primary Phone <span class="required">*</span>
                                        </label>
                                        <input
                                            type="tel"
                                            v-model="client.phone_number"
                                            class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.phone_number ? 'is-valid' : 'is-invalid') : '']"
                                            id="phoneNumber"
                                            placeholder="+254 700 000 000"
                                        />
                                        <div class="invalid-feedback">Please enter phone number</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="extraPhone" class="form-label">
                                            Alternative Phone
                                        </label>
                                        <input
                                            type="tel"
                                            v-model="client.extra_phone_number"
                                            class="form-control modern-input"
                                            id="extraPhone"
                                            placeholder="+254 700 000 000 (Optional)"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Location & Assignment</h5>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="address" class="form-label">
                                            Address <span class="required">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            v-model="client.address"
                                            class="form-control modern-input"
                                            :class="[is_submit_form4 ? (client.address ? 'is-valid' : 'is-invalid') : '']"
                                            id="address"
                                            placeholder="Enter complete address"
                                        />
                                        <div class="invalid-feedback">Please enter address</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="advocate" class="form-label">
                                            Assigned Advocate <span class="required">*</span>
                                        </label>
                                        <select 
                                            v-model="client.advocate" 
                                            class="form-select modern-select" 
                                            :class="[is_submit_form4 ? (client.advocate ? 'is-valid' : 'is-invalid') : '']"
                                            id="advocate"
                                        >
                                            <option value="">Select an advocate</option>
                                            <option v-for="data in advocates" :key="data.id" :value="data.id">
                                                {{ data.name }}
                                            </option>
                                        </select>
                                        <div class="invalid-feedback">Please select an advocate</div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer-actions">
                                <button type="button" class="btn-cancel" data-bs-dismiss="modal">
                                    Cancel
                                </button>
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

useMeta({ title: 'Clients' });

const router = useRouter();

const columns = ref(['name', 'phone_number', 'extra_phone_number', 'address', 'advocate', 'actions']);
const items = ref([]);
const advocates = ref([]);
const modal = ref({ title: "Add Client", button: "Add Client" });
const is_submit_form4 = ref(false);
const client = ref({
    id: "",
    name: "",
    phone_number: "",
    extra_phone_number: "",
    address: "",
    advocate: ""
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
        filterPlaceholder: 'Search clients...',
        limit: 'Show:',
    },
    sortable: ['name', 'phone_number', 'address', 'advocate'],
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

const fetchData = () => {
    axios.get('/api/clients')
        .then(response => {
            items.value = response.data.data;
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
};

const submit_form4 = () => {
    is_submit_form4.value = true;
    
    if (client.value.name && client.value.phone_number && client.value.address && client.value.advocate) {
        if (client.value.id > 0) {
            axios.put(`/api/clients/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Client updated successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                })
                .catch(error => {
                    showMessage('Error updating client. Please try again.', 'error');
                    console.error(error);
                });
        } else {
            axios.post('/api/clients', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Client added successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                })
                .catch(error => {
                    showMessage('Error adding client. Please try again.', 'error');
                    console.error(error);
                });
        }
    }
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.name = item.name;
    client.value.phone_number = item.phone_number;
    client.value.extra_phone_number = item.extra_phone_number;
    client.value.advocate = item.advocate_id;
    client.value.address = item.address;
    
    modal.value.title = "Update Client";
    modal.value.button = "Update Client";
};

const view_row = (item) => {
    router.push({ name: 'view-client', params: { id: item.id } });
};

const resetForm = () => {
    client.value = {
        id: "",
        name: "",
        phone_number: "",
        extra_phone_number: "",
        address: "",
        advocate: ""
    };
    modal.value.title = "Add Client";
    modal.value.button = "Add Client";
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
/* Modern Clients Page - Simplified SCSS */

.modern-clients-page {
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

.btn-add-client {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-add-client:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4);
}

.clients-table-card {
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
    background: #eef2ff;
    color: #4f46e5;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.client-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.client-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 16px;
}

.client-name-text {
    font-weight: 500;
    color: #1f2937;
}

.contact-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
    font-size: 14px;
}

.address-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
    font-size: 14px;
}

.advocate-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #d1fae5;
    color: #10b981;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.no-data {
    color: #9ca3af;
    font-style: italic;
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
    background: #eef2ff;
    color: #4f46e5;
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
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px #eef2ff;
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
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

@media (max-width: 768px) {
    .modern-clients-page {
        padding: 16px;
    }
    
    .page-header-actions {
        flex-direction: column;
        gap: 16px;
        padding: 20px;
    }
    
    .btn-add-client {
        width: 100%;
        justify-content: center;
    }
    
    .form-grid.grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>