<template>
    <div class="modern-view-client">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;" @click="$router.push('/clients')">Clients</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Client Details</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <!-- Header with Actions -->
        <div class="client-header">
            <button class="btn-back" @click="$router.push('/clients')">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Clients
            </button>
            <div class="header-actions">
                <button class="btn-action-header btn-edit" @click="editClient">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit Client
                </button>
                <button class="btn-action-header btn-delete" @click="deleteClient">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                    Delete
                </button>
            </div>
        </div>

        <div class="client-content">
            <!-- Client Profile Card -->
            <div class="client-profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        {{ clientData.name ? clientData.name.charAt(0).toUpperCase() : 'C' }}
                    </div>
                    <div class="profile-info">
                        <h1 class="client-name">{{ clientData.name || 'Client Name' }}</h1>
                        <div class="client-meta">
                            <span class="meta-item">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                Client since {{ formatDate(clientData.created_at) }}
                            </span>
                            <span class="meta-item status-active">
                                <span class="status-dot"></span>
                                Active Client
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="details-grid">
                <!-- Contact Information -->
                <div class="info-card">
                    <div class="card-header">
                        <div class="card-icon contact-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                            </svg>
                        </div>
                        <h3 class="card-title">Contact Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Primary Phone</span>
                            <span class="info-value">{{ clientData.phone_number || 'Not provided' }}</span>
                        </div>
                        <div class="info-row" v-if="clientData.extra_phone_number">
                            <span class="info-label">Alternative Phone</span>
                            <span class="info-value">{{ clientData.extra_phone_number }}</span>
                        </div>
                        <div class="info-row" v-if="clientData.email">
                            <span class="info-label">Email Address</span>
                            <span class="info-value email">{{ clientData.email }}</span>
                        </div>
                    </div>
                </div>

                <!-- Location Information -->
                <div class="info-card">
                    <div class="card-header">
                        <div class="card-icon location-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                        </div>
                        <h3 class="card-title">Location Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-row full-width">
                            <span class="info-label">Address</span>
                            <span class="info-value">{{ clientData.address || 'Not provided' }}</span>
                        </div>
                        <div class="info-row" v-if="clientData.city">
                            <span class="info-label">City</span>
                            <span class="info-value">{{ clientData.city }}</span>
                        </div>
                    </div>
                </div>

                <!-- Advocate Information -->
                <div class="info-card">
                    <div class="card-header">
                        <div class="card-icon advocate-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <h3 class="card-title">Assigned Advocate</h3>
                    </div>
                    <div class="card-body">
                        <div class="advocate-profile">
                            <div class="advocate-avatar">
                                {{ clientData.advocate ? clientData.advocate.charAt(0).toUpperCase() : 'A' }}
                            </div>
                            <div class="advocate-details">
                                <h4 class="advocate-name">{{ clientData.advocate || 'Not assigned' }}</h4>
                                <span class="advocate-role">Lead Advocate</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="info-card">
                    <div class="card-header">
                        <div class="card-icon info-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                        </div>
                        <h3 class="card-title">Additional Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Client ID</span>
                            <span class="info-value">#{{ clientData.id || '000' }}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Registration Date</span>
                            <span class="info-value">{{ formatDate(clientData.created_at) }}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Updated</span>
                            <span class="info-value">{{ formatDate(clientData.updated_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cases/Matters Section -->
            <div class="cases-section">
                <div class="section-header">
                    <h2 class="section-title">Associated Cases</h2>
                </div>
                
                <div class="cases-grid">
                    <template v-if="clientData.cases && clientData.cases.length > 0">
                        <div class="case-card cross-link" v-for="case_item in clientData.cases" :key="case_item.id" @click="goToCase(case_item.id)">
                            <div class="case-header">
                                <span class="case-number">Case #{{ case_item.case_number }}</span>
                                <span class="case-status" :class="case_item.status">{{ case_item.status }}</span>
                            </div>
                            <h4 class="case-title">{{ case_item.title }}</h4>
                            <p class="case-description">{{ case_item.description }}</p>
                            <div class="case-footer">
                                <span class="case-date">{{ formatDate(case_item.created_at) }}</span>
                                <button type="button" class="btn-view-case" @click.stop="goToCase(case_item.id)">View Details →</button>
                            </div>
                        </div>
                    </template>

                    <div class="empty-state" v-else>
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <line x1="9" y1="15" x2="15" y2="15"></line>
                        </svg>
                        <h3>No cases yet</h3>
                        <p>This client doesn't have any associated cases.</p>
                    </div>
                </div>
            </div>

            <!-- Trust Ledger Section (FUN-2) -->
            <div class="trust-section">
                <div class="section-header">
                    <h2 class="section-title">Trust Account</h2>
                    <div class="trust-balance-badge" :class="{ 'zero-balance': trustBalance <= 0 }">
                        Balance: {{ formatMoney(trustBalance) }}
                    </div>
                </div>

                <div class="trust-grid">
                    <!-- New transaction form -->
                    <div class="trust-form-card">
                        <h4>Record a Transaction</h4>
                        <form @submit.prevent="submitTrustTransaction">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select v-model="trustForm.type" class="form-select modern-select">
                                    <option value="deposit">Deposit</option>
                                    <option value="disbursement">Disbursement</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Amount</label>
                                <input type="number" step="0.01" min="0.01" v-model="trustForm.amount" class="form-control modern-input" placeholder="0.00" />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Related case (optional)</label>
                                <select v-model="trustForm.case_id" class="form-select modern-select">
                                    <option value="">None</option>
                                    <option v-for="c in clientData.cases" :key="c.id" :value="c.id">{{ c.case_number }}</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <input type="text" v-model="trustForm.description" class="form-control modern-input" placeholder="e.g. Initial retainer" />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reference number (optional)</label>
                                <input type="text" v-model="trustForm.reference_number" class="form-control modern-input" placeholder="e.g. receipt/cheque number" />
                            </div>
                            <button type="submit" class="btn-submit-trust" :disabled="trustSubmitting">
                                {{ trustSubmitting ? 'Saving...' : 'Record Transaction' }}
                            </button>
                        </form>
                    </div>

                    <!-- Transaction history -->
                    <div class="trust-history-card">
                        <h4>Transaction History</h4>
                        <div v-if="trustLoading" class="trust-loading">Loading...</div>
                        <div v-else-if="!trustTransactions.length" class="empty-state small">
                            <p>No trust transactions yet.</p>
                        </div>
                        <ul v-else class="trust-list">
                            <li v-for="t in trustTransactions" :key="t.id" :class="{ voided: t.voided }">
                                <div class="trust-list-main">
                                    <span class="trust-type" :class="t.type">{{ t.type === 'deposit' ? 'Deposit' : 'Disbursement' }}</span>
                                    <span class="trust-desc">{{ t.description }}</span>
                                    <span class="trust-date">{{ t.date }}{{ t.voided ? ' — VOIDED' : '' }}</span>
                                </div>
                                <div class="trust-list-side">
                                    <span class="trust-amount" :class="t.type">
                                        {{ t.type === 'deposit' ? '+' : '-' }}{{ formatMoney(t.amount) }}
                                    </span>
                                    <button v-if="!t.voided" type="button" class="btn-void" @click="voidTrustTransaction(t)" title="Void this transaction">
                                        Void
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import axios, { fetchAllPages } from "../../../api";
import { useMeta } from '@/composables/use-meta';
import { useToast } from '@/composables/use-toast';
import { useConfirmDelete } from '@/composables/use-confirm-delete';
import { useRecentlyViewed } from '@/composables/use-recently-viewed';
import AppDatePicker from '@/components/ui/app-date-picker.vue';

useMeta({ title: 'View Client' });

const { addRecentItem } = useRecentlyViewed();
const { showMessage } = useToast();
const { confirmDelete } = useConfirmDelete();

const router = useRouter();
const route = useRoute();

// UI-9: the case-details route has no id URL param — it reads
// localStorage (see cases/list-cases.vue's view_row) — so a cross-link
// to a case follows that same pattern rather than a plain router-link.
const goToCase = (caseId) => {
    if (!caseId) return;
    localStorage.setItem('caseId', caseId);
    router.push('/view-case');
};

const clientData = ref({
    id: '',
    name: '',
    phone_number: '',
    extra_phone_number: '',
    email: '',
    address: '',
    city: '',
    advocate: '',
    created_at: '',
    updated_at: '',
    cases: []
});

// Trust ledger (FUN-2)
const trustBalance = ref(0);
const trustTransactions = ref([]);
const trustLoading = ref(true);
const trustForm = ref({ type: 'deposit', amount: '', description: '', reference_number: '', case_id: '' });
const trustSubmitting = ref(false);

onMounted(() => {
    // DATA-9: the URL carries the client's public id; the ledger calls need the record loaded first.
    fetchClientData().then(fetchTrustLedger);
});

const fetchClientData = () => {
    const clientId = route.params.id;

    return axios.get(`/api/v1/clients/${clientId}`)
        .then(response => {
            clientData.value = response.data.data;
            addRecentItem({ type: 'client', id: clientId, title: clientData.value.name, subtitle: 'Client' });
        })
        .catch(error => {
            console.error('Error fetching client data:', error);
            showMessage('Error loading client data', 'error');
        });
};

const fetchTrustLedger = () => {
    trustLoading.value = true;
    if (!clientData.value.id) { trustLoading.value = false; return; }
    fetchAllPages('/api/v1/trust-transactions', { client_id: clientData.value.id }, { pick: (body) => body.data.transactions })
        .then(({ rows, body }) => {
            trustBalance.value = body.balance;
            trustTransactions.value = rows;
        })
        .catch(error => {
            // A 403 here just means this user lacks trust-ledger
            // permissions — the rest of the client page still works, so
            // don't surface an error toast for that, only log it.
            console.error('Error fetching trust ledger:', error);
        })
        .finally(() => {
            trustLoading.value = false;
        });
};

const submitTrustTransaction = () => {
    if (!trustForm.value.amount || !trustForm.value.description) return;

    trustSubmitting.value = true;
    axios.post('/api/v1/trust-transactions', {
        client_id: clientData.value.id,
        case_id: trustForm.value.case_id || null,
        type: trustForm.value.type,
        amount: trustForm.value.amount,
        description: trustForm.value.description,
        reference_number: trustForm.value.reference_number || null,
    })
        .then(() => {
            showMessage(`${trustForm.value.type === 'deposit' ? 'Deposit' : 'Disbursement'} recorded successfully.`);
            trustForm.value = { type: 'deposit', amount: '', description: '', reference_number: '', case_id: '' };
            fetchTrustLedger();
        })
        .catch(error => {
            showMessage(error.response?.data?.message || 'Error recording transaction.', 'error');
        })
        .finally(() => {
            trustSubmitting.value = false;
        });
};

const voidTrustTransaction = (transaction) => {
    window.Swal.fire({
        title: 'Void this transaction?',
        text: 'This cannot be undone. The entry stays on the record but is excluded from the balance.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--color-danger)',
        confirmButtonText: 'Void it',
    }).then((result) => {
        if (!result.isConfirmed) return;

        axios.put(`/api/v1/trust-transactions/${transaction.id}/void`)
            .then(() => {
                showMessage('Transaction voided.');
                fetchTrustLedger();
            })
            .catch(error => {
                showMessage(error.response?.data?.message || 'Error voiding transaction.', 'error');
            });
    });
};

const formatMoney = (value) => {
    const n = Number(value) || 0;
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(date).toLocaleDateString('en-US', options);
};

const editClient = () => {
    router.push({ name: 'clients', query: { edit: clientData.value.id } });
};

const deleteClient = () => {
    confirmDelete({
        url: `/api/v1/clients/${clientData.value.id}`,
        itemLabel: clientData.value.name,
        onSuccess: () => {
            router.push('/clients');
        },
    });
};
</script>

<style scoped>
/* The client page: header bar, profile banner, a two-column grid of detail cards, cases, then the trust ledger.
   All layout lives here (it used to rely on rules that were never written, which is why it looked broken). */
.modern-view-client { max-width: 1180px; margin: 0 auto; padding: 4px 0 40px; font-size: 15px; color: var(--text-primary, #18181b); }

.client-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-back, .btn-action-header {
    display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 10px; font-size: 14.5px; font-weight: 600;
    border: 1px solid var(--border-color, #e4e4e7); background: var(--card-bg, #fff); color: var(--text-secondary, #52525b); cursor: pointer; transition: all 0.15s;
}
.btn-back:hover, .btn-action-header:hover { border-color: var(--primary-400); color: var(--primary-700); }
.btn-action-header.btn-edit { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
.btn-action-header.btn-edit:hover { background: var(--primary-700); color: #fff; }
.btn-action-header.btn-delete { color: #b42318; border-color: rgba(231, 81, 90, 0.4); }
.btn-action-header.btn-delete:hover { background: #e7515a; border-color: #e7515a; color: #fff; }

.client-content { display: flex; flex-direction: column; gap: 22px; }

/* profile banner */
.client-profile-card {
    border-radius: 16px; padding: 26px 28px; color: #fff;
    background: linear-gradient(120deg, var(--primary-700), var(--primary-500));
    box-shadow: 0 8px 24px rgba(2, 95, 144, 0.18);
}
.profile-header { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
.profile-avatar {
    width: 72px; height: 72px; flex: none; border-radius: 50%; display: grid; place-items: center; font-size: 30px; font-weight: 700;
    background: rgba(255, 255, 255, 0.2); border: 2px solid rgba(255, 255, 255, 0.45); color: #fff;
}
.client-name { margin: 0 0 8px; font-size: 26px; font-weight: 700; color: #fff; line-height: 1.2; }
.client-meta { display: flex; gap: 10px 22px; flex-wrap: wrap; font-size: 14.5px; }
.meta-item { display: inline-flex; align-items: center; gap: 7px; color: rgba(255, 255, 255, 0.92); }
.status-dot { width: 9px; height: 9px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.3); }

/* detail cards */
.details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.info-card, .cases-section, .trust-section {
    background: var(--card-bg, #fff); border: 1px solid var(--border-color, #e4e4e7); border-radius: 14px; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.info-card .card-header { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border-light, #f4f4f5); }
.card-icon { width: 38px; height: 38px; flex: none; border-radius: 10px; display: grid; place-items: center; background: var(--primary-50); color: var(--primary-700); }
.card-icon.location-icon { background: #ecfdf5; color: #047857; }
.card-icon.advocate-icon { background: #fff7ed; color: #c2410c; }
.card-icon.info-icon { background: #f1f5f9; color: #475569; }
.info-card .card-title { margin: 0; font-size: 17px; font-weight: 700; }
.info-card .card-body { padding: 8px 20px 14px; }
.info-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 11px 0; border-bottom: 1px dashed var(--border-color, #e4e4e7); }
.info-row:last-child { border-bottom: 0; }
.info-label { flex: none; font-size: 13.5px; color: var(--text-muted, #626779); }
.info-value { font-weight: 600; text-align: right; word-break: break-word; }
.info-value.email { color: var(--primary-700); }
.info-row.full-width { flex-direction: column; align-items: flex-start; gap: 4px; }
.info-row.full-width .info-value { text-align: left; }
.advocate-profile { display: flex; align-items: center; gap: 14px; padding: 8px 0 4px; }
.advocate-avatar { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 19px; background: var(--primary-100); color: var(--primary-800); }
.advocate-name { margin: 0; font-size: 16.5px; font-weight: 700; }
.advocate-role { font-size: 13.5px; color: var(--text-muted, #626779); }

/* cases */
.cases-section, .trust-section { padding: 20px 22px 22px; }
.section-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.section-title { margin: 0; font-size: 19px; font-weight: 700; }
.cases-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.case-card { padding: 16px 18px; border: 1px solid var(--border-color, #e4e4e7); border-radius: 12px; background: var(--card-bg, #fff); transition: box-shadow 0.15s, border-color 0.15s; }
.case-card.cross-link { cursor: pointer; }
.case-card.cross-link:hover { border-color: var(--primary-300); box-shadow: 0 6px 18px rgba(2, 95, 144, 0.1); }
.case-header { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
.case-number { font-size: 13px; font-weight: 700; color: var(--primary-700); }
.case-status { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #e2e8f0; color: #475569; text-transform: capitalize; }
.case-status.open, .case-status.active, .case-status.ongoing { background: #dcfce7; color: #166534; }
.case-status.closed { background: #f1f5f9; color: #64748b; }
.case-title { margin: 0 0 6px; font-size: 16px; font-weight: 700; }
.case-description { margin: 0 0 12px; font-size: 14px; color: var(--text-muted, #626779); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.case-footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.case-date { font-size: 13px; color: var(--text-muted, #626779); }
.btn-view-case { border: 0; background: none; color: var(--primary-700); font-weight: 700; font-size: 14px; cursor: pointer; padding: 0; }
.empty-state { grid-column: 1 / -1; text-align: center; padding: 28px 12px; color: var(--text-muted, #626779); }
.empty-state svg { color: var(--neutral-300); margin-bottom: 8px; }
.empty-state h3 { margin: 0 0 4px; font-size: 17px; color: var(--text-primary, #18181b); }
.empty-state p { margin: 0; }

/* trust ledger form fields */
.trust-section .form-group { margin-bottom: 12px; }
.trust-section .form-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 5px; }
.trust-section h4 { font-size: 16.5px; font-weight: 700; margin: 0 0 12px; }

@media (max-width: 760px) {
    .details-grid { grid-template-columns: 1fr; }
    .client-profile-card { padding: 20px; }
    .client-name { font-size: 22px; }
    .info-row { flex-direction: column; align-items: flex-start; gap: 2px; }
    .info-value { text-align: left; }
}
</style>
