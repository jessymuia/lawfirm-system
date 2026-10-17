<template>
    <div class="modern-courts-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Courts</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-actions">
            <div class="header-content">
                <h2 class="page-title">Courts Management</h2>
                <p class="page-subtitle">Manage court information and types</p>
            </div>
            <button type="button" class="btn-add-court" @click="resetForm" data-bs-toggle="modal" data-bs-target="#client_modal">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New Court
            </button>
        </div>

        <div class="courts-table-card">
            <div class="table-header">
                <h3 class="table-title">All Courts</h3>
            </div>
            
            <div class="table-wrapper">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/courts"
                    :columns="[{ key: 'name', label: 'Name' }, { key: 'court_type', label: 'Type' }]"
                    :sortable-columns="['name']"
                    :searchable-columns="['name']"
                    empty-title="No courts yet"
                    empty-description="Add your first court to get started."
                    empty-action-label="Add New Court"
                    @empty-action="openAddModal"
                >
                    <template #cell-name="{ row }">
                        <div class="court-name-cell">
                            <div class="court-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                            </div>
                            <span class="court-name-text">{{ row.name }}</span>
                            <CatalogBadge :row="row" />
                        </div>
                    </template>

                    <template #cell-court_type="{ row }">
                        <span class="type-badge">{{ row.court_type }}</span>
                    </template>

                    <template #actions="{ row }">
                        <div class="action-buttons">
                            <button type="button" class="btn-action btn-edit" @click="update_row(row)" data-bs-toggle="modal" data-bs-target="#client_modal" title="Edit Court" aria-label="Edit court">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                            <button v-if="!row.is_system" type="button" class="btn-action btn-delete" @click="delete_row(row)" title="Delete Court" aria-label="Delete court">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                    <path d="M10 11v6"></path>
                                    <path d="M14 11v6"></path>
                                </svg>
                            </button>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <div class="modal fade" id="client_modal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <div class="modal-title-wrapper">
                            <div class="modal-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                            </div>
                            <div>
                                <h4 class="modal-title">{{ modal.title }}</h4>
                                <p class="modal-subtitle">{{ modal.title === 'Add Court' ? 'Enter court information below' : (client.is_system ? 'Set a local name' : 'Update court details') }}</p>
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
                                <h5 class="section-title">Court Information</h5>
                                <div v-if="client.is_system" class="form-group">
                                    <label class="form-label">Local name</label>
                                    <input type="text" v-model="client.alias" class="form-control modern-input" :class="{ 'is-invalid': hasError('alias') }" :placeholder="client.official_name" @input="clearErrors('alias')"/>
                                    <div class="invalid-feedback">{{ fieldError('alias') }}</div>
                                    <small class="form-text text-muted">Official name: <strong>{{ client.official_name }}</strong>. It is maintained by the platform and cannot be renamed or deleted; set the name your firm uses instead. Leave empty to use the official name.</small>
                                </div>
                                <div v-else class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Court Name <span class="required">*</span></label>
                                        <input type="text" v-model="client.name" class="form-control modern-input"
                                            :class="[hasError('name') ? 'is-invalid' : (is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '')]"
                                            placeholder="Enter court name" @input="clearErrors('name')"/>
                                        <div class="invalid-feedback">{{ hasError('name') ? fieldError('name') : 'Court name is required' }}</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Court Type <span class="required">*</span></label>
                                        <select v-model="client.type" class="form-select modern-select"
                                            :class="[hasError('type') ? 'is-invalid' : (is_submit_form4 ? (client.type ? 'is-valid' : 'is-invalid') : '')]"
                                            @change="clearErrors('type')">
                                            <option value="">Select Court Type</option>
                                            <option v-for="data in types" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">{{ hasError('type') ? fieldError('type') : 'Please select a court type' }}</div>
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
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import { useFormErrors } from '@/composables/use-form-errors';
import DataTable from '@/components/ui/data-table.vue';
import CatalogBadge from '@/components/ui/catalog-badge.vue';

useMeta({ title: 'Courts' });

const router = useRouter();
const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const tableRef = ref(null);
const types = ref([]);
const modal = ref({title: "Add Court", button: "Add"});
const is_submit_form4 = ref(false);

const client = ref({
    id: "",
    name: "",
    type: "",
    is_system: false,
    alias: "",
    official_name: ""
});

// TEN-11: a system court only accepts a local name (alias); a firm's court is edited as before.
const payload = () => client.value.is_system ? { alias: client.value.alias } : { name: client.value.name, type: client.value.type };

onMounted(() => {
    axios.get('/api/v1/courttypes/options')
        .then(response => {
            types.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching court types:', error);
            showMessage('Could not load court types. Please try refreshing the page.', 'error');
        });
});

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.name && client.value.type) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/courts/${client.value.id}`, payload())
                .then(() => {
                    showMessage('Court updated successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating court. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/courts', payload())
                .then(() => {
                    showMessage('Court added successfully.');
                    document.getElementById('closebtn').click();
                    resetForm();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding court. Please try again.'), 'error');
                });
        }
    }
};

const openAddModal = () => {
    resetForm();
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};

const update_row = (item) => {
    clearErrors();
    client.value = {id: item.id, name: item.official_name ?? item.name, type: item.type, is_system: !!item.is_system, alias: item.alias ?? "", official_name: item.official_name ?? item.name};

    modal.value.title = "Update Court";
    modal.value.button = "Update";
};

const delete_row = (item) => {
    confirmDelete({
        url: `/api/v1/courts/${item.id}`,
        itemLabel: item.name,
        onSuccess: () => { tableRef.value?.refresh(); },
    });
};

const resetForm = () => {
    client.value = {
        id: "",
        name: "",
        type: "",
        is_system: false,
        alias: "",
        official_name: ""
    };
    modal.value.title = "Add Court";
    modal.value.button = "Add";
    is_submit_form4.value = false;
    clearErrors();
};
</script>

<style lang="scss" scoped>
/* Modern Courts Page */

.modern-courts-page {
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

.btn-add-court {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-add-court:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(2, 132, 199, 0.4);
}

.courts-table-card {
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
    background: #ede9fe;
    color: var(--primary-600);
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.court-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.court-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #ede9fe;
    color: var(--primary-600);
    display: flex;
    align-items: center;
    justify-content: center;
}

.court-name-text {
    font-weight: 600;
    color: #1f2937;
}

.type-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #dbeafe;
    color: #1d4ed8;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
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

.btn-action.btn-delete {
    background: #fee2e2;
    color: #e7515a;
}

.btn-action.btn-delete:hover {
    background: #e7515a;
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
    background: #ede9fe;
    color: var(--primary-600);
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
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px #ede9fe;
}

.modern-input.is-invalid,
.modern-select.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid,
.modern-select.is-valid {
    border-color: var(--primary-600);
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
    color: #4b5563;
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
}

@media (max-width: 768px) {
    .modern-courts-page {
        padding: 16px;
    }
    
    .page-header-actions {
        flex-direction: column;
        gap: 16px;
        padding: 20px;
    }
    
    .btn-add-court {
        width: 100%;
        justify-content: center;
    }
}
</style>