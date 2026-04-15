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
            path: '/forms/:id/edit',
            name: 'forms-edit',
            component: () => import('./components/Forms/Builder.vue'),
            meta: { requiresAuth: true, requiresPermission: 'edit forms' }
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
            path: '/helpdesk',
            name: 'helpdesk',
            component: () => import('./components/Helpdesk/Index.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view helpdesk tickets' }
        },
        {
            path: '/helpdesk/:id',
            name: 'helpdesk-detail',
            component: () => import('./components/Helpdesk/Detail.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view helpdesk tickets' }
        },
        {
            path: '/it-activities',
            name: 'it-activities',
            component: () => import('./components/ItActivities/Index.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view it activities' }
        },
        {
            path: '/knowledge-hub',
            component: () => import('./components/Knowledge/Hub.vue'),
            meta: { requiresAuth: true, requiresPermission: 'view knowledge hub' },
            children: [
                {
                    path: '',
                    redirect: { name: 'knowledge-hub-chat' },
                },
                {
                    path: 'chat',
                    name: 'knowledge-hub-chat',
                    component: () => import('./components/Knowledge/HubChatView.vue'),
                },
                {
                    path: 'documents',
                    name: 'knowledge-hub-documents',
                    component: () => import('./components/Knowledge/HubDocumentsView.vue'),
                },
            ],
        },
        {
            path: '/settings/knowledge-ai',
            component: () => import('./components/Knowledge/Admin.vue'),
            meta: { requiresAuth: true, requiresPermission: 'manage knowledge hub' },
            children: [
                {
                    path: '',
                    redirect: { name: 'knowledge-admin-overview' },
                },
                {
                    path: 'overview',
                    name: 'knowledge-admin-overview',
                    component: () => import('./components/Knowledge/AdminOverviewView.vue'),
                },
                {
                    path: 'general',
                    name: 'knowledge-admin-general',
                    component: () => import('./components/Knowledge/AdminGeneralView.vue'),
                },
                {
                    path: 'divisions',
                    name: 'knowledge-admin-divisions',
                    component: () => import('./components/Knowledge/AdminDivisionsView.vue'),
                },
                {
                    path: 'spaces',
                    redirect: { name: 'knowledge-admin-divisions' },
                },
                {
                    path: 'sections',
                    redirect: { name: 'knowledge-admin-divisions' },
                },
                {
                    path: 'entries',
                    redirect: { name: 'knowledge-admin-divisions' },
                },
            ],
        },
        {
            path: '/profile',
            name: 'profile',
            component: () => import('./components/Profile.vue'),
            meta: { requiresAuth: true }
        },
        {
            path: '/settings',
            name: 'settings',
            component: () => import('./components/Settings.vue'),
            meta: { requiresAuth: true, requiresRole: 'Admin' }
        },
        {
            path: '/users',
            name: 'users',
            component: () => import('./components/Users/Index.vue'),
            meta: { requiresAuth: true, requiresRole: 'Admin' }
        },
        {
            path: '/roles',
            name: 'roles',
            component: () => import('./components/Roles/Index.vue'),
            meta: { requiresAuth: true, requiresRole: 'Admin' }
        },
        {
            path: '/workflows',
            name: 'workflows',
            component: () => import('./components/Workflows/Index.vue'),
            meta: { requiresAuth: true, requiresPermission: 'manage workflows' }
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
