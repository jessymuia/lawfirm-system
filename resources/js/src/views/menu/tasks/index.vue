<template>
    <div class="modern-tasks-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Tasks</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>List</span></li>
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
                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M9 12h6M9 16h6" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h4>Tasks Management</h4>
                </div>
                <button type="button" class="btn-add-task" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Task
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #title="props">
                        <div class="task-title">
                            <svg class="task-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" stroke-width="2"/>
                                <rect x="9" y="3" width="6" height="4" rx="1" stroke-width="2"/>
                            </svg>
                            {{ props.row.title }}
                        </div>
                    </template>
                    <template #description="props">
                        <div class="description-text">{{ props.row.description }}</div>
                    </template>
                    <template #advocate="props">
                        <div class="advocate-cell">
                            <svg class="advocate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-width="2"/>
                                <circle cx="12" cy="7" r="4" stroke-width="2"/>
                            </svg>
                            {{ props.row.advocate }}
                        </div>
                    </template>
                    <template #due_date="props">
                        <div class="date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2"/>
                            </svg>
                            {{ props.row.due_date }}
                        </div>
                    </template>
                    <template #priority="props">
                        <span :class="['priority-badge', `priority-${props.row.priority?.toLowerCase()}`]">
                            {{ props.row.priority }}
                        </span>
                    </template>
                    <template #status="props">
                        <span :class="['status-badge', `status-${props.row.status?.toLowerCase()?.replace('_', '-')}`]">
                            {{ props.row.status?.replace('_', ' ') }}
                        </span>
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
        <div class="modal fade" id="client_modal" tabindex="-1" role="dialog" aria-labelledby="registerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
                <div class="modal-content modern-modal">
                    <div class="modal-header">
                        <h4 class="modal-title">{{ modal.title }}</h4>
                        <button id="closebtn" type="button" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" class="btn-close"></button>
                    </div>
                    <div class="modal-body">
                        <form class="modern-form" novalidate @submit.stop.prevent="submit_form4">
                            <div class="form-section">
                                <h5 class="section-title">Task Information</h5>
                                <div class="row">
                                    <div class="col-md-12 form-group">
                                        <label class="form-label">Title *</label>
                                        <input type="text" v-model="client.title" class="modern-input"
                                               :class="[is_submit_form4 ? (client.title ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="Enter task title" />
                                        <div class="invalid-feedback">Please fill the title</div>
                                    </div>

                                    <div class="col-md-12 form-group">
                                        <label class="form-label">Description *</label>
                                        <textarea v-model="client.description" rows="3" class="modern-input"
                                                  :class="[is_submit_form4 ? (client.description ? 'is-valid' : 'is-invalid') : '']"
                                                  placeholder="Enter task description"></textarea>
                                        <div class="invalid-feedback">Please fill the description</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Assignment & Timeline</h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Assigned To *</label>
                                        <select v-model="client.assigned_to" class="modern-select" :class="[is_submit_form4 ? (client.assigned_to ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select User</option>
                                            <option v-for="data in users" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the user</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Due Date * (dd/mm/yyyy)</label>
                                        <input v-model="client.due_date" type="text" class="modern-input"
                                               :class="[is_submit_form4 ? (client.due_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Priority *</label>
                                        <select v-model="client.priority" class="modern-select"
                                                :class="[is_submit_form4 ? (client.priority ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Priority</option>
                                            <option value="low">Low</option>
                                            <option value="medium">Medium</option>
                                            <option value="high">High</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the priority</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Status *</label>
                                        <select v-model="client.status" class="modern-select"
                                                :class="[is_submit_form4 ? (client.status ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Status</option>
                                            <option value="pending">Pending</option>
                                            <option value="in_progress">In Progress</option>
                                            <option value="completed">Completed</option>
                                            <option value="overdue">Overdue</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the status</div>
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
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
useMeta({ title: 'Tasks' });

const columns = ref(['title','description','advocate','due_date','priority','status', 'actions']);
const items = ref([]);
const users = ref([]);
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
    sortable: ['title','description','advocate','due_date','priority','status'],
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
const is_submit_form4 = ref(false);
const client = ref({id: "",description:"",title : "", assigned_to: "", due_date:"",priority:"",status:""});

const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.priority && client.value.status && client.value.title && client.value.description) {
        if(client.value.id > 0) {
            axios.put(`/api/tasks/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Task updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating task. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/tasks', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Task added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding task. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.description = "";
        client.value.title = "";
        client.value.assigned_to = "";
        client.value.due_date = "";
        client.value.priority = "";
        client.value.status = "";
        modal.value.title = "Add Task";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const fetchData = () => {
    modal.value.title = "Add Task";
    modal.value.button = "Add";
    
    axios.get('/api/tasks')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching tasks:', error);
        });

    axios.get('/api/advocates')
        .then(response => {
            users.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching advocates:', error);
        });
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.description = item.description;
    client.value.title = item.title;
    client.value.assigned_to = item.assigned_to;
    client.value.due_date = item.due_date;
    client.value.priority = item.priority;
    client.value.status = item.status;
    modal.value.title = "Update Task";
    modal.value.button = "Update";
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
.modern-tasks-page {
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
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
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

.btn-add-task {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.btn-add-task:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
}

.btn-add-task svg {
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

.task-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #1f2937;
    font-size: 14px;
}

.task-icon {
    width: 18px;
    height: 18px;
    color: #8b5cf6;
    flex-shrink: 0;
    stroke-width: 2;
}

.description-text {
    color: #6b7280;
    font-size: 13px;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.advocate-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #374151;
    font-size: 14px;
}

.advocate-icon {
    width: 18px;
    height: 18px;
    color: #8b5cf6;
    flex-shrink: 0;
    stroke-width: 2;
}

.date-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
    font-size: 14px;
}

.date-cell svg {
    width: 16px;
    height: 16px;
    color: #8b5cf6;
    stroke-width: 2;
}

.priority-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.priority-low {
    background: #dbeafe;
    color: #1e40af;
}

.priority-medium {
    background: #fef3c7;
    color: #92400e;
}

.priority-high {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.status-pending {
    background: #f3f4f6;
    color: #4b5563;
}

.status-in-progress {
    background: #dbeafe;
    color: #1e40af;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
}

.status-overdue {
    background: #fee2e2;
    color: #991b1b;
}

.btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
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
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.btn-edit svg {
    width: 16px;
    height: 16px;
    stroke-width: 2;
}

.modern-modal .modal-header {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
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

.modern-input,
.modern-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: white;
}

textarea.modern-input {
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
}

.modern-input:focus,
.modern-select:focus {
    outline: none;
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
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

.btn-submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 32px;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-add-task {
        justify-content: center;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }

    .description-text {
        max-width: 200px;
    }
}
</style>