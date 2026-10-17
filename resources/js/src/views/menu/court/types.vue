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
            <button type="button" class="btn-add-type" @click="resetForm" data-bs-toggle="modal" data-bs-target="#client_modal">
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
            </div>
            
            <div class="table-wrapper">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/courttypes"
                    :columns="[{ key: 'name', label: 'Name' }]"
                    :sortable-columns="['name']"
                    :searchable-columns="['name']"
                    empty-title="No court types yet"
                    empty-description="Add your first court type to get started."
                    empty-action-label="Add Court Type"
                    @empty-action="openAddModal"
                >
                    <template #cell-name="{ row }">
                        <div class="type-name-cell">
                            <div class="type-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                                </svg>
                            </div>
                            <span class="type-name-text">{{ row.name }}</span>
                            <CatalogBadge :row="row" />
                        </div>
                    </template>
                    
                    <template #actions="{ row }">
                        <div class="action-buttons">
                            <button type="button" class="btn-action btn-edit" @click="update_row(row)" data-bs-toggle="modal" data-bs-target="#client_modal" title="Edit Type" aria-label="Edit type">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                            <button v-if="!row.is_system" type="button" class="btn-action btn-delete" @click="delete_row(row)" title="Delete Type" aria-label="Delete type">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                </svg>
                            </button>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <CrudModal ref="crudModalRef" modal-id="client_modal" :submit-label="modal.button" :form-snapshot="() => ({ ...client })" @submit="submit_form4">
            <template #header>
                <div class="modal-title-wrapper">
                    <div class="modal-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                        </svg>
                    </div>
                    <div>
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <p class="modal-subtitle">{{ modal.title === 'Add Court Type' ? 'Enter type name below' : (client.is_system ? 'Set a local name' : 'Update type name') }}</p>
                    </div>
                </div>
            </template>

            <div v-if="client.is_system" class="form-group">
                <label class="form-label">Local name</label>
                <input type="text" v-model="client.alias" class="form-control modern-input" :class="{ 'is-invalid': hasError('alias') }" :placeholder="client.official_name" @input="clearErrors('alias')"/>
                <div class="invalid-feedback">{{ fieldError('alias') }}</div>
                <small class="form-text text-muted">Official name: <strong>{{ client.official_name }}</strong>. It is maintained by the platform and cannot be renamed or deleted; set the name your firm uses instead. Leave empty to use the official name.</small>
            </div>
            <div v-else class="form-group">
                <label class="form-label">Type Name <span class="required">*</span></label>
                <input type="text" v-model="client.name" class="form-control modern-input"
                    :class="[hasError('name') ? 'is-invalid' : (is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '')]"
                    placeholder="e.g., High Court, Magistrates Court" @input="clearErrors('name')"/>
                <div class="invalid-feedback">{{ hasError('name') ? fieldError('name') : 'Type name is required' }}</div>
            </div>
        </CrudModal>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import { useFormErrors } from '@/composables/use-form-errors';
import CrudModal from '@/components/ui/crud-modal.vue';
import DataTable from '@/components/ui/data-table.vue';
import CatalogBadge from '@/components/ui/catalog-badge.vue';

useMeta({ title: 'Court Types' });

const router = useRouter();
const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const tableRef = ref(null);
const crudModalRef = ref(null);
const modal = ref({title: "Add Court Type", button: "Add"});
const is_submit_form4 = ref(false);

const client = ref({
    id: "",
    name: "",
    is_system: false,
    alias: "",
    official_name: ""
});

// TEN-11: a system row only accepts a local name (alias); a firm row is renamed as before.
const payload = () => client.value.is_system ? { alias: client.value.alias } : { name: client.value.name };

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.name) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/courttypes/${client.value.id}`, payload())
                .then(() => {
                    showMessage('Court type updated successfully.');
                    crudModalRef.value?.clearDirtyGuard();
                    document.getElementById('client_modal-closebtn').click();
                    resetForm();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating type. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/courttypes', payload())
                .then(() => {
                    showMessage('Court type added successfully.');
                    crudModalRef.value?.clearDirtyGuard();
                    document.getElementById('client_modal-closebtn').click();
                    resetForm();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding type. Please try again.'), 'error');
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
    client.value = {id: item.id, name: item.official_name ?? item.name, is_system: !!item.is_system, alias: item.alias ?? "", official_name: item.official_name ?? item.name};
    modal.value.title = "Update Court Type";
    modal.value.button = "Update";
};

const delete_row = (item) => {
    confirmDelete({
        url: `/api/v1/courttypes/${item.id}`,
        itemLabel: item.name,
        onSuccess: () => { tableRef.value?.refresh(); },
    });
};

const resetForm = () => {
    client.value = {
        id: "",
        name: "",
        is_system: false,
        alias: "",
        official_name: ""
    };
    modal.value.title = "Add Court Type";
    modal.value.button = "Add";
    is_submit_form4.value = false;
    clearErrors();
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
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
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
    color: var(--primary-600);
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
    color: var(--primary-600);
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

.btn-action.btn-delete {
    background: #fee2e2;
    color: #e7515a;
}

.btn-action.btn-delete:hover {
    background: #e7515a;
    color: white;
    transform: translateY(-2px);
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
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px #cffafe;
}

.modern-input.is-invalid {
    border-color: #ef4444;
}

.modern-input.is-valid {
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