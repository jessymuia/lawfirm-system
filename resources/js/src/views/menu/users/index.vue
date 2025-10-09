<template>
    <div class="modern-page-container">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Users</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <!-- Page Header -->
        <div class="page-header-section">
            <div class="header-content">
                <div>
                    <h2 class="page-title">Users Management</h2>
                    <p class="page-subtitle">Manage system users and their permissions</p>
                </div>
                <button 
                    type="button" 
                    class="btn-primary-modern" 
                    data-bs-toggle="modal" 
                    data-bs-target="#client_modal"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Add User
                </button>
            </div>
        </div>

        <!-- Table Card -->
        <div class="modern-table-card">
            <div class="table-wrapper">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #name="props">
                        <div class="user-cell">
                            <div class="user-avatar">
                                {{ props.row.name.charAt(0).toUpperCase() }}
                            </div>
                            <span class="user-name">{{ props.row.name }}</span>
                        </div>
                    </template>
                    
                    <template #role="props">
                        <span class="role-badge">{{ props.row.role }}</span>
                    </template>
                    
                    <template #actions="props">
                        <div class="action-buttons">
                            <button 
                                class="btn-action btn-edit" 
                                @click="update_row(props.row)"
                                data-bs-toggle="modal" 
                                data-bs-target="#client_modal"
                                title="Edit user"
                            >
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

        <!-- Modern Modal -->
        <div class="modal fade" id="client_modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <div>
                            <h4 class="modal-title">{{ modal.title }}</h4>
                            <p class="modal-subtitle">Fill in the user information below</p>
                        </div>
                        <button 
                            id="closebtn" 
                            type="button" 
                            class="btn-close-modern" 
                            data-bs-dismiss="modal" 
                            aria-label="Close"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="modal-body">
                        <form class="modern-form" @submit.stop.prevent="submit_form4">
                            <div class="form-grid">
                                <!-- Name -->
                                <div class="form-group-modern">
                                    <label for="name">Full Name <span class="required">*</span></label>
                                    <input
                                        type="text"
                                        v-model="client.name"
                                        class="form-control-modern"
                                        :class="[is_submit_form4 ? (client.name ? 'is-valid' : 'is-invalid') : '']"
                                        id="name"
                                        placeholder="Enter full name"
                                    />
                                    <div class="error-message" v-if="is_submit_form4 && !client.name">Name is required</div>
                                </div>

                                <!-- Email -->
                                <div class="form-group-modern">
                                    <label for="email">Email Address <span class="required">*</span></label>
                                    <input
                                        type="email"
                                        v-model="client.email"
                                        class="form-control-modern"
                                        :class="[is_submit_form4 ? (client.email ? 'is-valid' : 'is-invalid') : '']"
                                        id="email"
                                        placeholder="user@example.com"
                                    />
                                    <div class="error-message" v-if="is_submit_form4 && !client.email">Valid email is required</div>
                                </div>

                                <!-- Phone -->
                                <div class="form-group-modern">
                                    <label for="phone">Phone Number <span class="required">*</span></label>
                                    <input
                                        type="text"
                                        v-model="client.phone_number"
                                        class="form-control-modern"
                                        :class="[is_submit_form4 ? (client.phone_number ? 'is-valid' : 'is-invalid') : '']"
                                        id="phone"
                                        placeholder="+254 712 345 678"
                                    />
                                    <div class="error-message" v-if="is_submit_form4 && !client.phone_number">Phone number is required</div>
                                </div>

                                <!-- Department -->
                                <div class="form-group-modern">
                                    <label for="department">Department <span class="required">*</span></label>
                                    <input
                                        type="text"
                                        v-model="client.department"
                                        class="form-control-modern"
                                        :class="[is_submit_form4 ? (client.department ? 'is-valid' : 'is-invalid') : '']"
                                        id="department"
                                        placeholder="e.g., Legal, Admin"
                                    />
                                    <div class="error-message" v-if="is_submit_form4 && !client.department">Department is required</div>
                                </div>

                                <!-- Hire Date -->
                                <div class="form-group-modern">
                                    <label for="hire_date">Hire Date <span class="required">*</span></label>
                                    <input 
                                        v-model="client.hire_date" 
                                        type="text" 
                                        id="hire_date" 
                                        class="form-control-modern"
                                        :class="[is_submit_form4 ? (client.hire_date ? 'is-valid' : 'is-invalid') : '']"
                                        v-maska="'##/##/####'" 
                                        placeholder="DD/MM/YYYY" 
                                    />
                                    <div class="error-message" v-if="is_submit_form4 && !client.hire_date">Hire date is required</div>
                                </div>

                                <!-- Roles -->
                                <div class="form-group-modern full-width">
                                    <label>Assign Roles <span class="required">*</span></label>
                                    <div class="role-checkboxes">
                                        <label 
                                            v-for="role in roles" 
                                            :key="role.id" 
                                            class="role-checkbox"
                                        >
                                            <input
                                                type="checkbox"
                                                :value="role.id"
                                                v-model="client.role"
                                                :id="'role_' + role.id"
                                            >
                                            <span class="checkbox-label">{{ role.name }}</span>
                                        </label>
                                    </div>
                                    <div class="error-message" v-if="is_submit_form4 && !client.role.length">At least one role must be selected</div>
                                </div>
                            </div>

                            <div class="modal-footer-actions">
                                <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn-primary-modern">
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
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';

useMeta({ title: 'Users' });

const columns = ref(['name', 'email', 'phone_number', 'department', 'hire_date', 'role', 'actions']);
const items = ref([]);
const roles = ref([]);
const modal = ref({title: "Add User", button: "Add User"});
const is_submit_form4 = ref(false);
const client = ref({
    id: "",
    name: "",
    phone_number: "",
    email: "",
    department: "",
    role: [],
    hire_date: ""
});

const table_option = ref({
    perPage: 10,
    perPageValues: [5, 10, 20, 50],
    skin: 'table modern-table',
    columnsClasses: { 
        actions: 'actions-column',
        name: 'name-column'
    },
    pagination: { nav: 'scroll', chunk: 5 },
    texts: {
        count: 'Showing {from} to {to} of {count}',
        filter: '',
        filterPlaceholder: 'Search users...',
        limit: 'Show:',
    },
    sortable: ['name', 'email', 'phone_number', 'department', 'hire_date', 'role'],
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
    
    if (client.value.name && client.value.phone_number && client.value.email && 
        client.value.department && client.value.role.length && client.value.hire_date) {
        
        const endpoint = client.value.id > 0 
            ? `/api/users/${client.value.id}` 
            : '/api/users';
        const method = client.value.id > 0 ? 'put' : 'post';
        const successMessage = client.value.id > 0 
            ? 'User updated successfully.' 
            : 'User added successfully.';

        axios[method](endpoint, client.value)
            .then(response => {
                items.value = response.data.data;
                showMessage(successMessage);
                document.getElementById('closebtn').click();
                resetForm();
            })
            .catch(error => {
                showMessage(`Error ${client.value.id > 0 ? 'updating' : 'adding'} user. Please try again.`, 'error');
                console.error(error);
            });
    }
};

const fetchData = () => {
    axios.get('/api/users')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching users:', error);
        });

    axios.get('/api/rolesDropDown')
        .then(response => {
            roles.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching roles:', error);
        });
};

const update_row = (item) => {
    modal.value.title = "Update User";
    modal.value.button = "Update User";
    
    client.value.id = item.id;
    client.value.name = item.name;
    client.value.phone_number = item.phone_number;
    client.value.email = item.email;
    client.value.role = item.role_id;
    client.value.department = item.department;
    client.value.hire_date = item.hire_date;
};

const resetForm = () => {
    client.value = {
        id: "",
        name: "",
        phone_number: "",
        email: "",
        department: "",
        role: [],
        hire_date: ""
    };
    modal.value.title = "Add User";
    modal.value.button = "Add User";
    is_submit_form4.value = false;
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
.modern-page-container {
    padding: 1.5rem;
    max-width: 100%;
}

// Page Header
.page-header-section {
    margin-bottom: 2rem;
    
    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1.5rem;
    }
    
    .page-title {
        font-size: var(--font-size-2xl);
        font-weight: var(--font-weight-bold);
        color: var(--text-primary);
        margin: 0;
    }
    
    .page-subtitle {
        font-size: var(--font-size-sm);
        color: var(--text-secondary);
        margin: 0.25rem 0 0;
    }
}

// Modern Buttons
.btn-primary-modern {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    background: linear-gradient(135deg, var(--primary-600) 0%, var(--primary-700) 100%);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: var(--font-weight-medium);
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
    
    &:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }
}

.btn-secondary-modern {
    padding: 0.625rem 1.25rem;
    background: var(--neutral-100);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    font-weight: var(--font-weight-medium);
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
    
    &:hover {
        background: var(--neutral-200);
    }
}

// Table Card
.modern-table-card {
    background: var(--card-bg);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-light);
    overflow: hidden;
}

.table-wrapper {
    padding: 1.5rem;
    
    :deep(.modern-table) {
        width: 100%;
        
        thead {
            th {
                background: var(--neutral-50);
                color: var(--text-secondary);
                font-weight: var(--font-weight-semibold);
                font-size: var(--font-size-xs);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 1rem;
                border-bottom: 2px solid var(--border-light);
            }
        }
        
        tbody {
            tr {
                transition: background var(--transition-fast);
                
                &:hover {
                    background: var(--neutral-50);
                }
                
                td {
                    padding: 1rem;
                    border-bottom: 1px solid var(--border-light);
                    color: var(--text-primary);
                    font-size: var(--font-size-sm);
                }
            }
        }
    }
    
    :deep(.VueTables__search) {
        margin-bottom: 1.5rem;
        
        input {
            width: 100%;
            max-width: 300px;
            padding: 0.625rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: var(--font-size-sm);
            
            &:focus {
                outline: none;
                border-color: var(--primary-500);
                box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
            }
        }
    }
    
    :deep(.VueTables__limit) {
        margin-bottom: 1.5rem;
        
        select {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: var(--font-size-sm);
        }
    }
}

// User Cell
.user-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-500) 0%, var(--primary-600) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: var(--font-weight-semibold);
    font-size: var(--font-size-sm);
}

.user-name {
    font-weight: var(--font-weight-medium);
    color: var(--text-primary);
}

// Role Badge
.role-badge {
    display: inline-block;
    padding: 0.375rem 0.75rem;
    background: var(--primary-100);
    color: var(--primary-700);
    border-radius: var(--radius-md);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
}

// Action Buttons
.action-buttons {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
}

.btn-action {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-fast);
    
    &.btn-edit {
        background: var(--success-100);
        color: var(--success-700);
        
        &:hover {
            background: var(--success-200);
            transform: scale(1.1);
        }
    }
}

// Modern Modal
.modern-modal {
    border-radius: var(--radius-xl);
    border: none;
    box-shadow: var(--shadow-2xl);
    
    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        
        .modal-title {
            font-size: var(--font-size-xl);
            font-weight: var(--font-weight-semibold);
            color: var(--text-primary);
            margin: 0;
        }
        
        .modal-subtitle {
            font-size: var(--font-size-sm);
            color: var(--text-secondary);
            margin: 0.25rem 0 0;
        }
    }
    
    .btn-close-modern {
        background: var(--neutral-100);
        border: none;
        border-radius: var(--radius-md);
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all var(--transition-fast);
        
        &:hover {
            background: var(--neutral-200);
        }
    }
    
    .modal-body {
        padding: 1.5rem;
    }
}

// Modern Form
.modern-form {
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.5rem;
        
        @media (max-width: 768px) {
            grid-template-columns: 1fr;
        }
    }
    
    .form-group-modern {
        &.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-medium);
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            
            .required {
                color: var(--error-500);
            }
        }
        
        .form-control-modern {
            width: 100%;
            padding: 0.625rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: var(--font-size-sm);
            transition: all var(--transition-fast);
            
            &:focus {
                outline: none;
                border-color: var(--primary-500);
                box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
            }
            
            &.is-invalid {
                border-color: var(--error-500);
            }
            
            &.is-valid {
                border-color: var(--success-500);
            }
        }
        
        .error-message {
            margin-top: 0.25rem;
            font-size: var(--font-size-xs);
            color: var(--error-600);
        }
    }
    
    .role-checkboxes {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        
        .role-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            
            input[type="checkbox"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
            }
            
            .checkbox-label {
                font-size: var(--font-size-sm);
                color: var(--text-primary);
            }
        }
    }
    
    .modal-footer-actions {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-light);
    }
}
</style>