<template>
    <div class="modern-roles-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Users</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Roles</span></li>
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
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="9" cy="7" r="4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <h4>Roles & Permissions</h4>
                </div>
                <button type="button" class="btn-add-role" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Role
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/roles"
                    :columns="[
                        { key: 'name', label: 'Role' },
                        { key: 'created_at', label: 'Created' },
                        { key: 'updated_at', label: 'Updated' },
                    ]"
                    :sortable-columns="['name', 'created_at', 'updated_at']"
                    :searchable-columns="['name']"
                    empty-title="No roles yet"
                    empty-description="Add your first role to get started."
                    empty-action-label="Add New Role"
                    @empty-action="openAddModal"
                >
                    <template #cell-name="{ row }">
                        <div class="role-name">
                            <svg class="role-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2"/>
                                <circle cx="8.5" cy="7" r="4" stroke-width="2"/>
                                <path d="M20 8v6M23 11h-6" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            {{ row.name }}
                        </div>
                    </template>
                    <template #cell-created_at="{ row }">
                        <div class="date-cell">{{ row.created_at }}</div>
                    </template>
                    <template #cell-updated_at="{ row }">
                        <div class="date-cell">{{ row.updated_at }}</div>
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
                            <a v-if="row.name !== 'admin'" href="javascript:;" class="cancel" @click="delete_row(row)">
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
        <div class="modal fade" ref="clientModal" id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="modern-form" novalidate @submit.stop.prevent="submit_form4">
                            <div class="form-section">
                                <h5 class="section-title">Role Information</h5>
                                <div class="form-group">
                                    <label class="form-label">Role Name *</label>
                                    <input type="text" v-model="client.name" class="modern-input"
                                           :class="[hasError('name') ? 'is-invalid' : (is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '')]"
                                           placeholder="e.g., Administrator, Manager, Staff" @input="clearErrors('name')" />
                                    <div class="invalid-feedback">{{ hasError('name') ? fieldError('name') : 'Please fill the role name' }}</div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Permissions</h5>
                                <div class="permissions-grid">
                                    <div v-for="(permission, index) in itemsP" :key="index" class="permission-item">
                                        <label class="permission-checkbox">
                                            <input
                                                type="checkbox"
                                                :id="'chk_info_' + index"
                                                v-model="client.permissions"
                                                :value="permission.name"
                                            />
                                            <span class="checkmark">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <path d="M20 6L9 17l-5-5" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </span>
                                            <span class="permission-label">{{ permission.name }}</span>
                                        </label>
                                    </div>
                                </div>
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
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import { useFormErrors } from '@/composables/use-form-errors';
import DataTable from '@/components/ui/data-table.vue';

useMeta({ title: 'Roles' });

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { hasError, fieldError, clearErrors, setErrorsFromResponse } = useFormErrors();

const tableRef = ref(null);
const itemsP = ref([]);
const modal = ref({title: "Add Role", button: "Add"});

onMounted(() => {
    fetchPermissions();
});

const router = useRouter();
const checkedPermissions = ref([]);

watch(checkedPermissions, (newPermissions) => {
    client.value.permissions = newPermissions;
});

const is_submit_form4 = ref(false);
const client = ref({id: "",name: "", permissions : []});

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.name) {
        const isUpdate = client.value.id > 0;

        if(isUpdate) {
            axios.put(`/api/v1/roles/${client.value.id}`, client.value)
                .then(() => {
                    showMessage('Role updated successfully.');
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating role. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/roles', client.value)
                .then(() => {
                    showMessage('Role added successfully.');
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding role. Please try again.'), 'error');
                });
        }

        client.value.id = "";
        client.value.name = "";
        client.value.permissions = [];
        modal.value.title = "Add Role";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const openAddModal = () => {
    clearErrors();
    client.value = {id: "",name: "", permissions : []};
    modal.value.title = "Add Role";
    modal.value.button = "Add";
    is_submit_form4.value = false;
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};

const fetchPermissions = () => {
    axios.get('/api/v1/permissions')
        .then(response => {
            itemsP.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching permissions:', error);
            showMessage('Could not load permissions. Please try refreshing the page.', 'error');
        });
};

const getPermissions = () => {
    axios.get(`/api/v1/permissions/${client.value.id}`)
        .then(response => {
            client.value.permissions = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching permissions:', error);
            showMessage('Could not load permissions. Please try refreshing the page.', 'error');
        });
};

const update_row = (item) => {
    clearErrors();
    client.value.id = item.id;
    client.value.name = item.name;

    modal.value.title = "Update Role: " + item.name;
    modal.value.button = "Update";

    getPermissions();
};

const delete_row = (item) => {
    // Deleting a role is more consequential than most modules — any
    // user still holding it loses every permission it granted with no
    // separate warning beyond this confirm dialog, so the itemLabel
    // spells that out explicitly rather than just naming the role.
    confirmDelete({
        url: `/api/v1/roles/${item.name}`,
        itemLabel: `the "${item.name}" role`,
        warning: 'Anyone still assigned this role will lose all of its permissions. This cannot be undone from the list.',
        onSuccess: () => { tableRef.value?.refresh(); },
    });
};
</script>

<style lang="scss" scoped>
.modern-roles-page {
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

.btn-add-role {
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
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
}

.btn-add-role:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(236, 72, 153, 0.4);
}

.btn-add-role svg {
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

.role-name {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #1f2937;
    font-size: 15px;
}

.role-icon {
    width: 20px;
    height: 20px;
    color: #ec4899;
    flex-shrink: 0;
    stroke-width: 2;
}

.date-cell {
    color: #6b7280;
    font-size: 14px;
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
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
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
    gap: 32px;
}

.form-section {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.section-title {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
    margin: 0;
    padding-bottom: 12px;
    border-bottom: 2px solid #f3f4f6;
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
    border-color: #ec4899;
    box-shadow: 0 0 0 3px rgba(236, 72, 153, 0.1);
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

.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 12px;
}

.permission-item {
    background: #fdf2f8;
    border: 2px solid #fce7f3;
    border-radius: 8px;
    padding: 12px;
    transition: all 0.3s ease;
}

.permission-item:hover {
    background: #fce7f3;
    border-color: #ec4899;
}

.permission-checkbox {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    margin: 0;
}

.permission-checkbox input[type="checkbox"] {
    display: none;
}

.checkmark {
    width: 24px;
    height: 24px;
    border: 2px solid #d1d5db;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.checkmark svg {
    width: 16px;
    height: 16px;
    stroke-width: 3;
    opacity: 0;
    transform: scale(0);
    transition: all 0.3s ease;
}

.permission-checkbox input[type="checkbox"]:checked ~ .checkmark {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    border-color: #ec4899;
}

.permission-checkbox input[type="checkbox"]:checked ~ .checkmark svg {
    opacity: 1;
    transform: scale(1);
    stroke: white;
}

.permission-label {
    color: #374151;
    font-size: 14px;
    font-weight: 500;
    user-select: none;
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
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(236, 72, 153, 0.4);
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-add-role {
        justify-content: center;
    }

    .permissions-grid {
        grid-template-columns: 1fr;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }
}
</style>