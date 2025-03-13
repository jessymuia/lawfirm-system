import axios from 'axios';

const axiosInstance = axios.create({
    // You can add any custom configuration here
    withCredentials: true, // Include credentials with requests
});

axiosInstance.interceptors.request.use((config) => {
    if (config.url !== '/') {
        const accessToken = JSON.parse(localStorage.getItem("user"));
        if (accessToken) {
            config.headers.Authorization = `Bearer ${accessToken.token}`;
        }
    }
    return config;
}, (error) => {
    return Promise.reject(error);
});
// axiosInstance.interceptors.response.use(
//     (response) => response,
//     (error) => {
//         if (error.response.status === 401) {
//             // Redirect the user to the login page
//             localStorage.clear();
//             window.location.href = '/login'; // Change the URL as needed
//         } else if(error.response.status === 403) {
//             window.location.href='/pages/error503';
//             console.log(error)
//         }
//         return Promise.reject(error);
//     }
// );

// Add this flag to prevent multiple redirects
let isRedirecting = false;

axiosInstance.interceptors.response.use(
    (response) => response,
    (error) => {
        // Check if error and error.response exist to prevent additional errors
        if (error && error.response) {
            if (error.response.status === 401 && !isRedirecting) {
                // Set flag to prevent multiple redirects
                isRedirecting = true;

                // Clear user data
                localStorage.clear();

                // Cancel any pending requests
                // Optional: implement with axios cancel tokens if needed

                // Use Vue Router instead of window.location
                router.push('/login').finally(() => {
                    // Reset the flag after navigation completes
                    setTimeout(() => {
                        isRedirecting = false;
                    }, 500);
                });
            } else if (error.response.status === 403) {
                // Also use Vue Router for 403 errors
                router.push('/pages/error503');
                console.log(error);
            }
        }

        // Always reject the promise to allow local error handling
        return Promise.reject(error);
    }
);

export default axiosInstance;

// window.axios.interceptors.response.use(
//     response => response,
//     error => {
//         if (error.response.status === 401) {
//             // Redirect the user to the login page
//             window.location.href = '/login'; // Change the URL as needed
//         }
//         return Promise.reject(error);
//     }
// );
// window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// window.axios.defaults.withCredentials = true;
