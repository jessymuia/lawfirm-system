<template>
    <!--  BEGIN SIDEBAR  -->
    <div class="sidebar-wrapper sidebar-theme">
        <nav ref="menu" id="sidebar">
            <div class="shadow-bottom"></div>

            <perfect-scrollbar class="list-unstyled menu-categories" tag="ul" :options="{ wheelSpeed: 0.5, swipeEasing: !0, minScrollbarLength: 40, maxScrollbarLength: 300, suppressScrollX: true }">

                <!-- Start Dashboard Menu -->
                <li v-if="hasPermission('view-dashboard')" class="menu">
                    <router-link to="/" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-home"
                            >
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                            <span>{{ $t('dashboard') }}</span>
                        </div>
                    </router-link>
                </li>
                <!-- End dashboard Menu -->

                <!-- Start Users Menu -->
                <li v-if="hasPermission('list-users')" class="menu">
                    <router-link to="/users" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-users me-2"
                            >
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            <span>{{ $t('Users') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Users Menu -->

                <!-- Start Clients Menu -->
                <li class="menu" v-if="hasPermission('list-clients')">
                    <router-link to="/clients" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-user me-2"
                            >
                                <path d="M20 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M4 21v-2a4 4 0 0 1 3-3.87"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>{{ $t('Clients') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Clients Menu -->

                <!-- Start Cases Menu -->
                <li class="menu" v-if="hasPermission('list-cases')">
                    <router-link to="/cases" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-file-text me-2"
                            >
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <line x1="10" y1="9" x2="8" y2="9"></line>
                            </svg>
                            <span>{{ $t('Cases') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Cases Menu -->

                <!-- Start Court Type Menu -->
                <li class="menu" v-if="hasPermission('list-court')">
                    <router-link to="/courttypes" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-layers me-2"
                            >
                                <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                                <polyline points="2 17 12 22 22 17"></polyline>
                                <polyline points="2 12 12 17 22 12"></polyline>
                            </svg>
                            <span>{{ $t('Court Types') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Court Type Menu -->

                <!-- Start Hearing Type Menu -->
                <li class="menu" v-if="hasPermission('list-clients')">
                    <router-link to="/hearingtypes" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-message-square me-2"
                            >
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                            <span>{{ $t('Hearing Types') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Hearing Type Menu -->

                <!-- Start Expense Category Menu -->
                <li class="menu"  v-if="hasPermission('list-invoice')">
                    <router-link to="/expense-categories" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-folder me-2"
                            >
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6l2 2h8a2 2 0 0 1 2 2z"></path>
                            </svg>
                            <span>{{ $t('Expense Category') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Expense Category Menu -->

                <!-- Start Courts Menu -->
                <li class="menu"  v-if="hasPermission('list-court')">
                    <router-link to="/courts" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-home me-2"
                            >
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                            <span>{{ $t('Courts') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Courts Menu -->

                <!-- Start Expenses Menu -->
                <li class="menu"  v-if="hasPermission('list-invoice')">
                    <router-link to="/expenses" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-credit-card me-2"
                            >
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                <line x1="1" y1="10" x2="23" y2="10"></line>
                            </svg>
                            <span>{{ $t('Expenses') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Expenses Menu -->

                <!-- Start Task Menu -->
                <li class="menu"  v-if="hasPermission('list-task')">
                    <router-link to="/tasks" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-check-square me-2"
                            >
                                <polyline points="9 11 12 14 22 4"></polyline>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                            </svg>
                            <span>{{ $t('Tasks') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Task Menu -->
                <!-- Start Hearings Menu -->
                <li class="menu"  v-if="hasPermission('list-clients')">
                    <router-link to="/hearings" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-calendar me-2"
                            >
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <span>{{ $t('Hearings') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Hearings Menu -->

                <!-- Start Invoice Menu -->
                <li class="menu"  v-if="hasPermission('list-invoice')">
                    <router-link to="/invoices" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-credit-card me-2"
                            >
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                <line x1="1" y1="10" x2="23" y2="10"></line>
                            </svg>
                            <span>{{ $t('Invoices') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Invoice Menu -->

                <!-- Start Roles Menu -->
                <li class="menu"  v-if="hasPermission('list-roles')">
                    <router-link to="/roles" class="dropdown-toggle" @click="toggleMobileMenu">
                        <div class="d-flex align-items-center">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="feather feather-shield me-2"
                            >
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                            </svg>
                            <span>{{ $t('Roles') }}</span>
                        </div>

                    </router-link>
                </li>
                <!-- End Roles Menu -->

            </perfect-scrollbar>
        </nav>
    </div>
    <!--  END SIDEBAR  -->
</template>

<script setup>
import {onMounted, ref} from 'vue';
import {useStore} from 'vuex';

const store = useStore();

    const menu_collapse = ref('dashboard');
    const isAdmin = ref(false);
    const userPermissions = JSON.parse(localStorage.getItem("permissions") || "[]");

    onMounted(() => {
        const selector = document.querySelector('#sidebar a[href="' + window.location.pathname + '"]');
        if (selector) {
            const ul = selector.closest('ul.collapse');
            if (ul) {
                let ele = ul.closest('li.menu').querySelectorAll('.dropdown-toggle');
                if (ele) {
                    ele = ele[0];
                    setTimeout(() => {
                        ele.click();
                    });
                }
            } else {
                selector.click();
            }
        }

        const user = JSON.parse(localStorage.getItem("user"));
         if(user)
             isAdmin.value = user.isAllowed;

    });
    const hasPermission = (permission) => {
        return userPermissions.includes(permission);
    };
    const toggleMobileMenu = () => {
        if (window.innerWidth < 991) {
            store.commit('toggleSideBar', !store.state.is_show_sidebar);
        }
    };
</script>
