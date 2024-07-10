<template>
    <div class="layout-px-spacing">
        <teleport to="#breadcrumb">
            <ul class="navbar-nav flex-row">
                <li>
                    <div class="page-header">
                        <nav class="breadcrumb-one" aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="javascript:;">Apps</a></li>
                                <li class="breadcrumb-item active" aria-current="page"><span>Invoice Preview</span></li>
                            </ol>
                        </nav>
                    </div>
                </li>
            </ul>
        </teleport>

        <div class="row invoice layout-top-spacing layout-spacing apps-invoice">
            <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                <div class="doc-container">
                    <div class="row">
                        <div class="col-xl-9">
                            <div class="invoice-container">
                                <div class="invoice-inbox">
                                    <div id="ct" class="">
                                        <div class="invoice-00001">
                                            <div class="content-section">
                                                <div class="inv--head-section inv--detail-section">
                                                    <div class="row">
                                                        <div class="col-sm-6 col-12 me-auto">
                                                            <div class="d-flex">
                                                                <img class="company-logo" src="@/assets/images/cork-logo.png" alt="company" />
                                                                <h3 class="in-heading align-self-center">Ouma Njoga & Co. Advocates.</h3>
                                                            </div>
                                                        </div>

                                                        <div class="col-sm-6 text-sm-end">
                                                            <p class="inv-list-number"><span class="inv-title">Invoice : </span> <span class="inv-number">#{{invoice.number}}</span></p>
                                                        </div>

                                                        <div class="col-sm-6 align-self-center mt-3">
                                                            <p class="inv-street-addr">XYZ Delta Street</p>
                                                            <p class="inv-email-address">info@company.com</p>
                                                            <p class="inv-email-address">(120) 456 789</p>
                                                        </div>
                                                        <div class="col-sm-6 align-self-center mt-3 text-sm-end">
                                                            <p class="inv-created-date"><span class="inv-title">Invoice Date : </span> <span class="inv-date">{{invoice.date}}</span></p>
                                                            <p class="inv-due-date"><span class="inv-title">Due Date : </span> <span class="inv-date">{{ invoice.due_date }}</span></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="inv--detail-section inv--customer-detail-section">
                                                    <div class="row">
                                                        <div class="col-xl-8 col-lg-7 col-md-6 col-sm-4 align-self-center">
                                                            <p class="inv-to">Invoice To</p>
                                                        </div>

                                                        <div class="col-xl-4 col-lg-5 col-md-6 col-sm-8 align-self-center order-sm-0 order-1 inv--payment-info">
                                                            <h6 class="inv-title">Payment Info:</h6>
                                                        </div>

                                                        <div class="col-xl-8 col-lg-7 col-md-6 col-sm-4">
                                                            <p class="inv-customer-name">{{ invoice.client }}</p>
                                                            <p class="inv-customer-name">{{ invoice.client_address}}</p>
                                                        </div>

                                                        <div class="col-xl-4 col-lg-5 col-md-6 col-sm-8 col-12 order-sm-0 order-1">
                                                            <div class="inv--payment-info">
                                                                <p><span class="inv-subtitle">Bank Name:</span> <span>KCB</span></p>
                                                                <p><span class="inv-subtitle">Account Number: </span> <span>1234567890</span></p>
                                                                <p><span class="inv-subtitle">SWIFT code:</span> <span>VS70134</span></p>
                                                                <p><span class="inv-subtitle">Country: </span> <span>KENYA</span></p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="inv--product-table-section">
                                                    <div class="table-responsive">
                                                        <table class="table table-hover">
                                                            <thead>
                                                                <tr>
                                                                    <th v-for="item in columns" :key="item.key" :class="[item.class]">
                                                                        {{ item.label }}
                                                                    </th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr v-for="(item,index) in items" :key="item.id">
                                                                    <td>
                                                                        {{ index+1 }}
                                                                    </td>
                                                                    <td>
                                                                        {{ item.item }}
                                                                    </td>
                                                                    <td>
                                                                        {{ item.quantity }}
                                                                    </td>
                                                                    <td class="text-end">{{ item.price }}</td>
                                                                    <td class="text-end">{{ item.amount }}</td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                <div class="inv--total-amounts">
                                                    <div class="row mt-4">
                                                        <div class="col-sm-5 col-12 order-sm-0 order-1"></div>
                                                        <div class="col-sm-7 col-12 order-sm-1 order-0">
                                                            <div class="text-sm-end">
                                                                <div class="row">
                                                                    <div class="col-sm-8 col-7">
                                                                        <p class="">Sub Total:</p>
                                                                    </div>
                                                                    <div class="col-sm-4 col-5">
                                                                        <p class="">KES{{(summary.totals - summary.total_tax)}}</p>
                                                                    </div>
                                                                    <div class="col-sm-8 col-7">
                                                                        <p class="">Tax Amount:</p>
                                                                    </div>
                                                                    <div class="col-sm-4 col-5">
                                                                        <p class="">KES{{summary.total_tax}}</p>
                                                                    </div>
                                                                    <div class="col-sm-8 col-7 grand-total-title">
                                                                        <h4 class="">Grand Total :</h4>
                                                                    </div>
                                                                    <div class="col-sm-4 col-5 grand-total-amount">
                                                                        <h4 class="">KES{{summary.totals}}</h4>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="inv--note">
                                                    <div class="row mt-4">
                                                        <div class="col-sm-12 col-12 order-sm-0 order-1">
                                                            <p>Note: Thank you for doing Business with us.</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-3">
                            <div class="invoice-actions-btn">
                                <div class="invoice-action-btn">
                                    <div class="row">
                                        <div class="col-xl-12 col-md-3 col-sm-6">
                                            <router-link to="/view-invoice" class="btn btn-dark btn-edit">Back</router-link>
                                        </div>
                                        <div class="col-xl-12 col-md-3 col-sm-6">
                                            <a href="javascript:;" class="btn btn-secondary btn-print action-print" @click="print()">Print</a>
                                        </div>
                                        <div class="col-xl-12 col-md-3 col-sm-6">
                                            <a v-if="invoice.postShow" href="javascript:" class="btn btn-info btn-edit" @click="submit_form6()">
                                                Post Invoice
                                            </a>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
    import { onMounted, ref } from 'vue';
    import '@/assets/sass/apps/invoice-preview.scss';
    import axios from "../../../api";

    import { useMeta } from '@/composables/use-meta';
    useMeta({ title: 'Invoice Preview' });

    const invoice = ref({});
    const summary = ref({});
    const items = ref([]);
    const columns = ref([
        { key: 'id', label: 'S.NO' },
        { key: 'title', label: 'ITEMS' },
        { key: 'quantity', label: 'QTY' },
        { key: 'price', label: 'PRICE(KES)', class: 'text-end' },
        { key: 'amount', label: 'AMOUNT(KES)', class: 'text-end' },
    ]);

    onMounted(() => {
        fetch_data();
    });



    const print = () => {
        window.print();
    };

    const submit_form6 = () => {
        let invoiceId = localStorage.getItem('invoiceId');
        if (confirm('Are you sure want to post the invoice ?')) {
            if (invoiceId) {
                // items.value = items.value.filter((d) => d.id != item.id);
                axios.put(`/api/send-to-admin/${invoiceId}`) // Assuming your API endpoint for client creation is /api/clients
                    .then(response => {
                        showMessage('Invoice sent successfully.');
                        document.getElementById('closebtn').click();
                    })
                    .catch(error => {
                        showMessage('Error posting the invoice. Please try again.');
                        console.error(error);
                    });
            }
        }
    }

    const fetch_data = () => {
        let invoiceId = localStorage.getItem('invoiceId');
        axios.get(`/api/preview-invoice/${invoiceId}`)
            .then(response => {
                items.value = response.data.data[0];
                invoice.value = response.data.data[1];
                summary.value = response.data.data[2];

            })
            .catch(error => {
                console.error('Error fetching preview:', error);
            });
    };
</script>
