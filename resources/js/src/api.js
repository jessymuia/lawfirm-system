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
axiosInstance.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response.status === 401) {
            // Redirect the user to the login page
            localStorage.clear();
            window.location.href = '/login'; // Change the URL as needed
        } else if(error.response.status === 403) {
            window.location.href='/pages/error503';
            console.log(error)
        }
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
