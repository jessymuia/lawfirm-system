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
                <button type="button" class="btn-add-task" @click="openAddModal" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Task
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <DataTable
                    ref="tableRef"
                    fetch-url="/api/v1/tasks"
                    table-id="tasks-list"
                    :selectable="true"
                    :columns="[
                        { key: 'title', label: 'Title' },
                        { key: 'description', label: 'Description' },
                        { key: 'advocate', label: 'Assigned To' },
                        { key: 'due_date', label: 'Due Date' },
                        { key: 'priority', label: 'Priority' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :sortable-columns="['title', 'due_date', 'priority']"
                    :searchable-columns="['title', 'description']"
                    empty-title="No tasks yet"
                    empty-description="Add your first task to get started."
                    empty-action-label="Add New Task"
                    @empty-action="openAddModal"
                >
                    <template #bulk-actions="{ selectedIds, clearSelection }">
                        <button type="button" class="btn-toolbar" @click="bulkDeleteTasks(selectedIds, clearSelection)">Delete Selected</button>
                    </template>
                    <template #cell-title="{ row }">
                        <div class="task-title">
                            <svg class="task-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" stroke-width="2"/>
                                <rect x="9" y="3" width="6" height="4" rx="1" stroke-width="2"/>
                            </svg>
                            {{ row.title }}
                        </div>
                    </template>
                    <template #cell-description="{ row }">
                        <div class="description-text">{{ row.description }}</div>
                    </template>
                    <template #cell-advocate="{ row }">
                        <div class="advocate-cell">
                            <svg class="advocate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke-width="2"/>
                                <circle cx="12" cy="7" r="4" stroke-width="2"/>
                            </svg>
                            {{ row.advocate }}
                        </div>
                    </template>
                    <template #cell-due_date="{ row }">
                        <div class="date-cell">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="4" width="18" height="18" rx="2" stroke-width="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18" stroke-width="2"/>
                            </svg>
                            {{ row.due_date }}
                        </div>
                    </template>
                    <template #cell-priority="{ row }">
                        <span :class="['priority-badge', `priority-${row.priority?.toLowerCase()}`]">
                            {{ row.priority }}
                        </span>
                    </template>
                    <template #cell-status="{ row }">
                        <span :class="['status-badge', `status-${row.status?.toLowerCase()?.replace('_', '-')}`]">
                            {{ row.status?.replace('_', ' ') }}
                        </span>
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
                                        <SearchSelect
                                            fetch-url="/api/v1/advocates"
                                            v-model="client.assigned_to"
                                            :initial-label="client.advocate_name"
                                            placeholder="Search users..."
                                            :invalid="is_submit_form4 && !client.assigned_to"
                                        />
                                        <div class="invalid-feedback" :style="is_submit_form4 && !client.assigned_to ? 'display:block' : ''">Please select the user</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Due Date *</label>
                                        <AppDatePicker v-model="client.due_date"
                                               :invalid="is_submit_form4 && !client.due_date" />
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
import { confirmAction } from '@/composables/use-confirm';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from "../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import AppDatePicker from '@/components/ui/app-date-picker.vue';
import { useFormErrors } from '@/composables/use-form-errors';
import DataTable from '@/components/ui/data-table.vue';
import SearchSelect from '@/components/ui/search-select.vue';
import { useModalDirtyGuard } from '@/composables/use-modal-dirty-guard';

const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();
const { setErrorsFromResponse, clearErrors } = useFormErrors();
useMeta({ title: 'Tasks' });

const tableRef = ref(null);

const bulkDeleteTasks = (selectedIds, clearSelection) => {
    if (!selectedIds.length) return;

    confirmAction({
        title: `Delete ${selectedIds.length} task${selectedIds.length > 1 ? 's' : ''}?`,
        text: 'This will delete every selected task. This can be undone by an administrator.',
        confirmText: 'Yes, delete all',
        danger: true,
    }).then((ok) => {
        if (!ok) return;

        axios.post('/api/v1/tasks/bulk-delete', { ids: selectedIds })
            .then(() => {
                showMessage(`Deleted ${selectedIds.length} task${selectedIds.length > 1 ? 's' : ''}.`);
                clearSelection();
                tableRef.value?.refresh();
            })
            .catch((error) => {
                const message = error.response?.data?.message || 'Error deleting selected tasks. Please try again.';
                showMessage(message, 'error');
            });
    });
};
const modal = ref({title: "Add Task", button: "Add"});

onMounted(() => {});

const router = useRouter();
const is_submit_form4 = ref(false);
const client = ref({id: "",description:"",title : "", assigned_to: "", advocate_name: "", due_date:"",priority:"",status:""});

// UI-15: closing this modal with unsaved edits now asks for confirmation.
// Bug fix (found via a real E2E run): clearDirtyGuard() must be called
// on a successful save, before the modal auto-closes — see
// list-clients.vue's identical fix for the full explanation.
const { clear: clearDirtyGuard } = useModalDirtyGuard('client_modal', () => ({ ...client.value }));

const submit_form4 = () => {
    is_submit_form4.value = true;
    clearErrors();
    if (client.value.priority && client.value.status && client.value.title && client.value.description) {
        if(client.value.id > 0) {
            axios.put(`/api/v1/tasks/${client.value.id}`, client.value)
                .then(() => {
                    showMessage('Task updated successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh();
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error updating task. Please try again.'), 'error');
                });
        } else {
            axios.post('/api/v1/tasks', client.value)
                .then(() => {
                    showMessage('Task added successfully.');
                    clearDirtyGuard();
                    document.getElementById('closebtn').click();
                    tableRef.value?.refresh(1);
                })
                .catch(error => {
                    showMessage(setErrorsFromResponse(error, 'Error adding task. Please try again.'), 'error');
                });
        }

        client.value.id = "";
        client.value.description = "";
        client.value.title = "";
        client.value.assigned_to = "";
        client.value.advocate_name = "";
        client.value.due_date = "";
        client.value.priority = "";
        client.value.status = "";
        modal.value.title = "Add Task";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const openAddModal = () => {
    client.value = {id: "",description:"",title : "", assigned_to: "", advocate_name: "", due_date:"",priority:"",status:""};
    modal.value.title = "Add Task";
    modal.value.button = "Add";
    const modalEl = document.getElementById('client_modal');
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
};

const update_row = (item) => {
    client.value.id = item.id;
    client.value.description = item.description;
    client.value.title = item.title;
    client.value.assigned_to = item.assigned_to;
    client.value.advocate_name = item.advocate;
    client.value.due_date = item.due_date;
    client.value.priority = item.priority;
    client.value.status = item.status;
    modal.value.title = "Update Task";
    modal.value.button = "Update";
};

const delete_row = (item) => {
    confirmDelete({
        url: `/api/v1/tasks/${item.id}`,
        itemLabel: item.title,
        onSuccess: () => { tableRef.value?.refresh(); },
        restoreUrl: `/api/v1/tasks/${item.id}/restore`,
        onRestore: () => { tableRef.value?.refresh(); },
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
}

.btn-add-task:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4);
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
    color: var(--primary-600);
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
    color: var(--primary-600);
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
    color: var(--primary-600);
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
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
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
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
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
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
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
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

.btn-submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 32px;
    background: linear-gradient(135deg, var(--primary-600), var(--primary-800));
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
    align-self: flex-start;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4);
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