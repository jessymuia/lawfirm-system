<template>
    <div class="modern-footer">
        <div class="footer-content">
            <div class="footer-left">
                <p class="copyright-text">
                    © {{ currentYear }} 
                    <span class="company-name">Ouma Njoga & Co. Advocates</span>
                    <span class="separator">•</span>
                    <span class="rights">All rights reserved</span>
                </p>
            </div>
            
            <div class="footer-right">
                <div class="footer-links">
                    <a href="/pages/privacy-policy" class="footer-link">Privacy</a>
                    <span class="link-separator">•</span>
                    <a href="/pages/helpdesk" class="footer-link">Support</a>
                    <span class="link-separator">•</span>
                    <span class="made-with">
                        Made with
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" class="heart-icon">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                        </svg>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const currentYear = computed(() => new Date().getFullYear());
</script>

<style lang="scss" scoped>
.modern-footer {
    background: var(--card-bg);
    border-top: 1px solid var(--border-light);
    padding: 1.25rem 1.5rem;
    margin-top: 30px;
    transition: all var(--transition-base);

    @media (max-width: 768px) {
        padding: 1rem;
    }
}

.footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 100%;
    gap: 1.5rem;

    @media (max-width: 768px) {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
}

.footer-left {
    .copyright-text {
        margin: 0;
        font-size: var(--font-size-sm);
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;

        @media (max-width: 768px) {
            justify-content: center;
        }

        .company-name {
            font-weight: var(--font-weight-semibold);
            color: var(--text-primary);
        }

        .separator {
            color: var(--text-tertiary);
        }

        .rights {
            color: var(--text-tertiary);
        }
    }
}

.footer-right {
    .footer-links {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: var(--font-size-sm);

        @media (max-width: 768px) {
            flex-wrap: wrap;
            justify-content: center;
        }

        .footer-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: var(--font-weight-medium);
            transition: color var(--transition-fast);

            &:hover {
                color: var(--primary-600);
            }
        }

        .link-separator {
            color: var(--text-tertiary);
        }

        .made-with {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            color: var(--text-secondary);
            font-weight: var(--font-weight-medium);

            .heart-icon {
                color: var(--error-500);
                animation: heartbeat 1.5s ease-in-out infinite;
            }
        }
    }
}

// Heartbeat animation
@keyframes heartbeat {
    0%, 100% {
        transform: scale(1);
    }
    10%, 30% {
        transform: scale(1.1);
    }
    20%, 40% {
        transform: scale(1);
    }
}

// Dark mode adjustments
body.dark {
    .modern-footer {
        background: var(--surface);
        border-color: var(--border-color);
    }
}
</style>