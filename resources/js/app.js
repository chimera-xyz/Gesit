import './bootstrap';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { createPinia } from 'pinia';
import App from './components/App.vue';
import { useAuthStore } from './stores/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            name: 'dashboard',
            component: () => import('./components/Dashboard.vue'),
            meta: { requiresAuth: true }
        },
        {
            path: '/forms',
            name: 'forms',
            component: () => import('./components/Forms/Index.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view forms' }
        },
        {
            path: '/forms/builder',
            name: 'forms-builder',
            component: () => import('./components/Forms/Builder.vue'),
            meta: { requiresAuth: true, requiresPermission: 'create forms' }
        },
        {
            path: '/forms/:id',
            name: 'form-view',
            component: () => import('./components/Forms/View.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view forms' }
        },
        {
            path: '/submissions/create',
            name: 'submissions-create',
            component: () => import('./components/Submissions/Create.vue'),
            meta: { requiresAuth: true, requiresPermission: 'submit forms' }
        },
        {
            path: '/submissions',
            name: 'submissions',
            component: () => import('./components/Submissions/Index.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view submissions' }
        },
        {
            path: '/submissions/:id',
            name: 'submission-detail',
            component: () => import('./components/Submissions/Detail.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view submissions' }
        },
        {
            path: '/profile',
            name: 'profile',
            component: () => import('./components/Profile.vue'),
            meta: { requiresAuth: true }
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('./components/Auth/Login.vue'),
            meta: { guestOnly: true }
        },
        {
            path: '/register',
            name: 'register',
            component: () => import('./components/Auth/Register.vue'),
            meta: { guestOnly: true }
        },
        {
            path: '/:pathMatch(.*)*',
            redirect: '/'
        },
    ],
});

const pinia = createPinia();
const authStore = useAuthStore(pinia);

const ensureAuthState = async () => {
    if (!authStore.initialized) {
        try {
            await authStore.fetchUser();
        } catch (error) {
            // The route guard handles guest redirects.
        }
    }
};

router.beforeEach(async (to) => {
    await ensureAuthState();

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        return {
            name: 'login',
            query: { redirect: to.fullPath },
        };
    }

    if (to.meta.guestOnly && authStore.isAuthenticated) {
        return { name: 'dashboard' };
    }

    if (to.meta.requiresRole && !authStore.hasRole(to.meta.requiresRole)) {
        return { name: 'dashboard' };
    }

    if (to.meta.requiresPermission && !authStore.hasPermission(to.meta.requiresPermission)) {
        return { name: 'dashboard' };
    }

    return true;
});

const app = createApp(App);
app.use(router);
app.use(pinia);
app.mount('#app');
