import { useHead } from '@vueuse/head';
import { unref, computed } from 'vue';

let siteTitle = 'hello';
let separator = '|';

export const usePageTitle = (pageTitle) =>
    useHead(
        computed(() => ({
            title: `${unref(pageTitle)} ${separator} ${siteTitle}`,
        }))
    );

// The tab shows the system's own name, never a firm's: "Matters | Law Management System".
const productName = () => document.getElementById('app')?.dataset.productName || 'Law Management System';

export const useMeta = (data) => {
    return useHead(computed(() => ({ ...data, title: `${data.title} | ${productName()}` })));
};
