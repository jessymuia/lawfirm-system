<template>
    <div class="modern-court-types-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Court Types</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-actions">
            <div class="header-content">
                <h2 class="page-title">Court Types</h2>
                <p class="page-subtitle">Manage different types of courts</p>
            </div>
            <button type="button" class="btn-add-type" data-bs-toggle="modal" data-bs-target="#client_modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add Court Type
            </button>
        </div>

        <div class="types-table-card">
            <div class="table-header">
                <h3 class="table-title">All Court Types</h3>
                <div class="table-stats">
                    <span class="stat-badge">{{ items.length }} Total</span>
                </div>
            </div>
            
            <div class="table-wrapper">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #name="props">
                        <div class="type-name-cell">
                            <div class="type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                                </svg>
                            </div>
                            <span class="type-name-text">{{ props.row.name }}</span>
                        </div>
                    </template>
                    
                    <template #actions="props">
                        <div class="action-buttons">
                            <button type="button" class="btn-action btn-edit" @click="update_row(props.row)" data-bs-toggle="modal" data-bs-target="#client_modal" title="Edit Type">
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
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <div class="modal-title-wrapper">
                            <div class="modal-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="modal-title">{{ modal.title }}</h4>
                                <p class="modal-subtitle">{{ modal.title === 'Add Court Type' ? 'Enter type name below' : 'Update type name' }}</p>
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
                            <div class="form-group">
                                <label class="form-label">Type Name <span class="required">*</span></label>
                                <input type="text" v-model="client.name" class="form-control modern-input"
                                    :class="[is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '']"
                                    placeholder="e.g., High Court, Magistrates Court"/>
                                <div class="invalid-feedback">Type name is required</div>
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

useMeta({ title: 'Court Types' });

const router = useRouter();

const columns = ref(['name', 'actions']);
const items = ref([]);
const modal = ref({title: "Add Court Type", button: "Add"});
const is_submit_form4 = ref(false);

const client = ref({
    id: "",
    name: ""
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
        filterPlaceholder: 'Search types...',
        limit: 'Show:',
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
    fetchData();
});

const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.name) {
        if(client.value.id > 0) {
            axios.put(`/api/courttypes/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Court type updated successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                })
                .catch(error => {
                    showMessage('Error updating type. Please try again.', 'error');
                    console.error(error);
                });
        } else {
            axios.post('/api/courttypes', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Court type added successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                })
                .catch(error => {
                    showMessage('Error adding type. Please try again.', 'error');
                    console.error(error);
                });
        }
    }
};

const fetchData = () => {
    axios.get('/api/courttypes')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching court types:', error);
        });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.name = item.name;
    modal.value.title = "Update Court Type";
    modal.value.button = "Update";
};

const resetForm = () => {
    client.value = {
        id: "",
        name: ""
    };
    modal.value.title = "Add Court Type";
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
/* Modern Court Types Page */

.modern-court-types-page {
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

.btn-add-type {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #06b6d4, #0891b2);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-add-type:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(6, 182, 212, 0.4);
}

.types-table-card {
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
    background: #cffafe;
    color: #06b6d4;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.type-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.type-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #cffafe;
    color: #06b6d4;
    display: flex;
    align-items: center;
    justify-content: center;
}

.type-name-text {
    font-weight: 600;
    color: #1f2937;
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
    background: #cffafe;
    color: #06b6d4;
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

.form-group {
    margin-bottom: 20px;
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

.modern-input {
    width: 100%;
    padding: 12px 16px;
    border: 1.5px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    color: #1f2937;
    background: #ffffff;
    transition: all 0.2s ease;
}

.modern-input:focus {
    outline: none;
    border-color: #06b6d4;
    box-shadow: 0 0 0 3px #cffafe;
}

.modern-input.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid {
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
    background: linear-gradient(135deg, #06b6d4, #0891b2);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
}

@media (max-width: 768px) {
    .modern-court-types-page {
        padding: 16px;
    }
    
    .page-header-actions {
        flex-direction: column;
        gap: 16px;
        padding: 20px;
    }
    
    .btn-add-type {
        width: 100%;
        justify-content: center;
    }
}
</style>