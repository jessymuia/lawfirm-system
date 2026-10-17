<template>
    <div class="modern-expense-categories-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Expenses</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Categories</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="page-header-card">
            <div class="header-content">
                <div class="header-title">
                    <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M4 7h16M4 12h16M4 17h16" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="7" cy="7" r="1" fill="currentColor"/>
                        <circle cx="7" cy="12" r="1" fill="currentColor"/>
                        <circle cx="7" cy="17" r="1" fill="currentColor"/>
                    </svg>
                    <h4>Expense Categories</h4>
                </div>
                <button type="button" class="btn-add-category" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add Category
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/expense-categories"
                    :columns="[{ key: 'name', label: 'Name' }]"
                    :sortable-columns="['name']"
                    :searchable-columns="['name']"
                    empty-title="No categories yet"
                    empty-description="Add your first expense category to get started."
                    empty-action-label="Add Category"
                    @empty-action="openAddModal"
                >
                    <template #cell-name="{ row }">
                        <div class="category-name">
                            <svg class="category-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M7 7h10M7 12h10" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            {{ row.name }}
                        </div>
                    </template>
                    <template #actions="{ row }">
                        <div class="actions text-center">
                            <a href="javascript:;" class="cancel me-1" @click="update_row(row)">
                                <button type="button" class="btn-edit" data-bs-toggle="modal" data-bs-target="#client_modal">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Edit
                                </button>
                            </a>
                            <a href="javascript:;" class="cancel" @click="delete_row(row)">
                                <button type="button" class="btn-delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <polyline points="3 6 5 6 21 6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Delete
                                </button>
                            </a>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <div class="modal fade" id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="modern-form" novalidate @submit.stop.prevent="submit_form4">
                            <div class="form-group">
                                <label class="form-label">Category Name *</label>
                                <input
                                    type="text"
                                    v-model="client.name"
                                    class="modern-input"
                                    :class="[hasError('name') ? 'is-invalid' : (is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '')]"
                                    placeholder="e.g., Travel, Court Fees, Legal Research"
                                    @input="clearErrors('name')"
                                />
                                <div class="invalid-feedback">{{ hasError('name') ? fieldError('name') : 'Please fill the category name' }}</div>
                            </div>

                            <button class="btn-submit" type="submit">{{ modal.button }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
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
import DataTable from '@/components/ui/data-table.vue';

useMeta({ title: 'Expense Categories' });

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const tableRef = ref(null);
const modal = ref({title: "Add Expense Category", button: "Add"});

const router = useRouter();
const is_submit_form4 = ref(false);
const client = ref({id: "", name: ""});

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.name) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/expense-categories/${client.value.id}`, client.value)
                .then(() => {
                    showMessage('Category updated successfully.');
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating category. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/expense-categories', client.value)
                .then(() => {
                    showMessage('Expense category added successfully.');
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding category. Please try again.'), 'error');
                });
        }

        client.value.id = "";
        client.value.name = "";
        modal.value.title = "Add Expense Category";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const update_row = (item) => {
    clearErrors();
    client.value.id = item.id;
    client.value.name = item.name;
    modal.value.title = "Update Expense Category";
    modal.value.button = "Update";
};

const openAddModal = () => {
    clearErrors();
    client.value.id = "";
    client.value.name = "";
    is_submit_form4.value = false;
    modal.value.title = "Add Expense Category";
    modal.value.button = "Add";
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};

const delete_row = (item) => {
    confirmDelete({
        url: `/api/v1/expense-categories/${item.id}`,
        itemLabel: item.name,
        onSuccess: () => { tableRef.value?.refresh(); },
    });
};
</script>

<style lang="scss" scoped>
.modern-expense-categories-page {
    padding: 20px;
    min-height: 100vh;
}

.page-header-card {
    background: white;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-icon {
    width: 48px;
    height: 48px;
    padding: 12px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border-radius: 12px;
    flex-shrink: 0;
}

.header-title h4 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
    color: #1f2937;
}

.btn-add-category {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
}

.btn-add-category:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
}

.btn-add-category svg {
    width: 20px;
    height: 20px;
    stroke-width: 2.5;
}

.table-container {
    background: white;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.table-card {
    overflow-x: auto;
}

.category-name {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
    color: #1f2937;
}

.category-icon {
    width: 20px;
    height: 20px;
    color: #f97316;
    flex-shrink: 0;
    stroke-width: 2;
}

.btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-edit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
}

.btn-edit svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.btn-delete {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #e7515a, #c53040);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-delete:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(231, 81, 90, 0.3);
}

.btn-delete svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.modern-modal .modal-header {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border-radius: 12px 12px 0 0;
    padding: 20px 24px;
    border: none;
}

.modern-modal .modal-title {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
}

.modern-modal .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.8;
}

.modern-modal .btn-close:hover {
    opacity: 1;
}

.modern-modal .modal-body {
    padding: 32px;
}

.modern-form {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.form-group {
    margin-bottom: 0;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #374151;
    font-size: 14px;
}

.modern-input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: white;
}

.modern-input:focus {
    outline: none;
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
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

.btn-submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 32px;
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-add-category {
        justify-content: center;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }
}
</style>