<template>
    <div class="modern-expenses-page">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Expenses</a></li>
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
                        <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <h4>Expenses Management</h4>
                </div>
                <button type="button" class="btn-add-expense" data-bs-toggle="modal" data-bs-target="#client_modal">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 5v14M5 12h14" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    Add New Expense
                </button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-card">
                <v-client-table :data="items" :columns="columns" :options="table_option">
                    <template #case="props">
                        <div class="case-badge">{{ props.row.case }}</div>
                    </template>
                    <template #expense_date="props">
                        <div class="date-cell">{{ props.row.expense_date }}</div>
                    </template>
                    <template #amount="props">
                        <div class="amount-cell">${{ props.row.amount }}</div>
                    </template>
                    <template #category_name="props">
                        <span class="category-badge">{{ props.row.category_name }}</span>
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
                                <h5 class="section-title">Expense Details</h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Case *</label>
                                        <select v-model="client.case_id" class="modern-select" :class="[is_submit_form4 ? (client.case_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Case</option>
                                            <option v-for="data in cases" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the case</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Expense Date * (dd/mm/yyyy)</label>
                                        <input v-model="client.expense_date" type="text" class="modern-input"
                                               :class="[is_submit_form4 ? (client.expense_date ? 'is-valid' : 'is-invalid') : '']"
                                               v-maska="'##/##/####'" placeholder="__/__/____" />
                                        <div class="invalid-feedback">Please provide a valid date</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Amount *</label>
                                        <input type="number" step="0.01" v-model="client.amount" class="modern-input"
                                               :class="[is_submit_form4 ? (client.amount ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="0.00" />
                                        <div class="invalid-feedback">Please fill the amount</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Category *</label>
                                        <select v-model="client.category" class="modern-select" :class="[is_submit_form4 ? (client.category ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select Category</option>
                                            <option v-for="data in types" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the category</div>
                                    </div>

                                    <div class="col-md-12 form-group">
                                        <label class="form-label">Description *</label>
                                        <input type="text" v-model="client.description" class="modern-input"
                                               :class="[is_submit_form4 ? (client.description ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="Enter expense description" />
                                        <div class="invalid-feedback">Please fill the description</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5 class="section-title">Payment Information</h5>
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Vendor *</label>
                                        <input type="text" v-model="client.vendor" class="modern-input"
                                               :class="[is_submit_form4 ? (client.vendor ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="Vendor name" />
                                        <div class="invalid-feedback">Please provide a valid vendor</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Payment Method *</label>
                                        <input type="text" v-model="client.payment_method" class="modern-input"
                                               :class="[is_submit_form4 ? (client.payment_method ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="e.g., Cash, Card, Bank Transfer" />
                                        <div class="invalid-feedback">Please provide a valid payment method</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">Invoice Number *</label>
                                        <input type="text" v-model="client.invoice_number" class="modern-input"
                                               :class="[is_submit_form4 ? (client.invoice_number ? 'is-valid' : 'is-invalid') : '']"
                                               placeholder="INV-000" />
                                        <div class="invalid-feedback">Please provide a valid invoice number</div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label class="form-label">User/Advocate *</label>
                                        <select v-model="client.user_id" class="modern-select" :class="[is_submit_form4 ? (client.user_id ? 'is-valid' : 'is-invalid') : '']">
                                            <option value="">Select User</option>
                                            <option v-for="data in users" :key="data.id" :value="data.id">{{ data.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">Please select the user</div>
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
useMeta({ title: 'Expenses' });

const columns = ref(['case', 'expense_date','amount','category_name','description','vendor','payment_method','invoice_number','user', 'actions']);
const items = ref([]);
const cases = ref([]);
const types = ref([]);
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
    sortable: ['case', 'expense_date','amount','category_name','description','vendor','payment_method','invoice_number','user'],
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
const client = ref({id: "",case_id:"",expense_date : "",amount:"",category:"",description:"",vendor:"",payment_method:"",invoice_number:"",user_id:""});

const submit_form4 = () => {
    is_submit_form4.value = true;
    if (client.value.case_id  && client.value.expense_date && client.value.payment_method && client.value.amount && client.value.payment_method && client.value.invoice_number && client.value.user_id) {
        if(client.value.id > 0) {
            axios.put(`/api/expenses/${client.value.id}`, client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Expense updated successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error updating expense. Please try again.');
                    console.error(error);
                });
        } else {
            axios.post('/api/expenses', client.value)
                .then(response => {
                    items.value = response.data.data;
                    showMessage('Expense added successfully.');
                    document.getElementById('closebtn').click();
                })
                .catch(error => {
                    showMessage('Error adding expense. Please try again.');
                    console.error(error);
                });
        }

        client.value.id = "";
        client.value.case_id = "";
        client.value.expense_date = "";
        client.value.amount = "";
        client.value.category = "";
        client.value.description = "";
        client.value.vendor = "";
        client.value.payment_method = "";
        client.value.invoice_number = "";
        client.value.user_id = "";
        modal.value.title = "Add Expense";
        modal.value.button = "Add";
        is_submit_form4.value = false;
    }
};

const fetchData = () => {
    modal.value.title = "Add Expense";
    modal.value.button = "Add";
    
    axios.get('/api/expenses')
        .then(response => {
            items.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching expenses:', error);
        });

    axios.get('/api/expense-categories')
        .then(response => {
            types.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching categories:', error);
        });

    axios.get('/api/casesDropDown')
        .then(response => {
            cases.value = response.data.data;
        })
        .catch(error => {
            console.error('Error fetching cases:', error);
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
    client.value.case_id = item.case_id;
    client.value.expense_date = item.expense_date;
    client.value.amount = item.amount;
    client.value.category = item.category;
    client.value.description = item.description;
    client.value.vendor = item.vendor;
    client.value.payment_method = item.payment_method;
    client.value.invoice_number = item.invoice_number;
    client.value.user_id = item.user_id;

    modal.value.title = "Update Expense";
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
.modern-expenses-page {
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
    background: linear-gradient(135deg, #f97316, #ea580c);
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

.btn-add-expense {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
}

.btn-add-expense:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.4);
}

.btn-add-expense svg {
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

.case-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.date-cell {
    color: #6b7280;
    font-size: 14px;
}

.amount-cell {
    font-weight: 600;
    color: #059669;
    font-size: 15px;
}

.category-badge {
    display: inline-block;
    padding: 6px 12px;
    background: #fed7aa;
    color: #9a3412;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
}

.btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #f97316, #ea580c);
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

.modern-modal .modal-header {
    background: linear-gradient(135deg, #f97316, #ea580c);
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

.modern-input:focus,
.modern-select:focus {
    outline: none;
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
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
    background: linear-gradient(135deg, #f97316, #ea580c);
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

    .btn-add-expense {
        justify-content: center;
    }

    .modern-modal .modal-body {
        padding: 20px;
    }
}
</style>