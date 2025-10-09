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
                <button type="button" class="btn-add-role" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Role
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #name="props">
                        <div class="role-name">
                            <svg class="role-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-width="2"/>
                                <circle cx="8.5" cy="7" r="4" stroke-width="2"/>
                                <path d="M20 8v6M23 11h-6" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            {{ props.row.name }}
                        </div>
                    </template>
                    <template #created_at="props">
                        <div class="date-cell">{{ props.row.created_at }}</div>
                    </template>
                    <template #updated_at="props">
                        <div class="date-cell">{{ props.row.updated_at }}</div>
                    </template>
                    <template #actions="props">
                        <div class="actions text-center">
                            <a href="javascript:;" class="cancel me-1" @click="update_row(props.row)">
                                <button type="button" class="btn-edit" data-bs-toggle="modal" data-bs-target="#client_modal">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Edit
                                </button>
                            </a>
                        </div>
                    </template>
                </v-client-table>
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
                                           :class="[is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '']"
                                           placeholder="e.g., Administrator, Manager, Staff" />
                                    <div class="invalid-feedback">Please fill the role name</div>
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
useMeta({ title: 'Roles' });

const columns = ref(['name','created_at','updated_at','actions']);
const items = ref([]);
const itemsP = ref([]);
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
    sortable: ['name','created_at','updated_at'],
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

const router = useRouter();
const checkedPermissions = ref([]);

watch(checkedPermissions, (newPermissions) => {
    client.value.permissions = newPermissions;
});

const is_submit_form4 = ref(false);
const client = ref({id: "",name: "", permissions : []});

const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.name) {
        if(client.value.id > 0) {
            axios.put(`/api/roles/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Role updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating role. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/roles', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Role added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding role. Please try again.');
                    console.error(error);
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

const fetchData = () => {
    modal.value.title = "Add Role";
    modal.value.button = "Add";
    
    axios.get('/api/roles')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching roles:', error);
        });

    axios.get('/api/permissions/')
        .then(response => {
            itemsP.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching permissions:', error);
        });
};

const getPermissions = () => {
    axios.get(`/api/permissions/${client.value.id}`)
        .then(response => {
            client.value.permissions = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching permissions:', error);
        });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.name = item.name;

    modal.value.title = "Update Role: " + item.name;
    modal.value.button = "Update";

    getPermissions();
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
    background: linear-gradient(135deg, #ec4899, #db2777);
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
    background: linear-gradient(135deg, #ec4899, #db2777);
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
    background: linear-gradient(135deg, #ec4899, #db2777);
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

.modern-modal .modal-header {
    background: linear-gradient(135deg, #ec4899, #db2777);
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
    background: linear-gradient(135deg, #ec4899, #db2777);
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
    background: linear-gradient(135deg, #ec4899, #db2777);
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