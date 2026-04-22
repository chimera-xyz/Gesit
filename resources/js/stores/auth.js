import { defineStore } from 'pinia';
import axios from 'axios';

const emptyPortalState = () => ({
    apps: [],
    allowedApps: [],
    homeApp: 'gesit',
    launcherPath: '/portal',
    postLoginPath: '/',
});

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        isAuthenticated: false,
        roles: [],
        permissions: [],
        initialized: false,
        portal: emptyPortalState(),
    }),

    getters: {
        hasRole: (state) => (role) => state.roles.includes(role),

        hasAnyRole: (state) => (roles) => roles.some((role) => state.roles.includes(role)),

        hasPermission: (state) => (permission) => state.permissions.includes(permission),

        isAdmin: (state) => state.roles.includes('Admin'),

        canApprove: (state) => ['IT Staff', 'Operational Director', 'Accounting'].some((role) =>
            state.roles.includes(role)
        ),

        portalApps: (state) => state.portal.apps || [],

        hasAppAccess: (state) => (appKey) => (state.portal.allowedApps || []).includes(appKey),

        hasMultipleApps: (state) => (state.portal.apps || []).length > 1,

        launcherVisible() {
            return this.hasMultipleApps || !this.hasAppAccess('gesit');
        },

        shouldAutoLaunchHomeApp() {
            const apps = this.portal.apps || [];
            const postLoginPath = this.portal.postLoginPath || '/';

            if (this.hasAppAccess('gesit')) {
                return false;
            }

            if (apps.length !== 1) {
                return false;
            }

            return postLoginPath !== '' && postLoginPath !== '/' && postLoginPath !== this.portal.launcherPath;
        },

        guestRedirectPath() {
            if (this.shouldAutoLaunchHomeApp) {
                return this.portal.postLoginPath || '/';
            }

            if (!this.hasAppAccess('gesit')) {
                return this.portal.launcherPath || '/portal';
            }

            if (this.hasMultipleApps && this.portal.homeApp !== 'gesit') {
                return this.portal.launcherPath || '/portal';
            }

            return '/';
        },
    },

    actions: {
        hydratePortal(portal = {}) {
            this.portal = {
                apps: Array.isArray(portal.apps) ? portal.apps : [],
                allowedApps: Array.isArray(portal.allowed_apps) ? portal.allowed_apps : [],
                homeApp: typeof portal.home_app === 'string' && portal.home_app !== ''
                    ? portal.home_app
                    : 'gesit',
                launcherPath: typeof portal.launcher_path === 'string' && portal.launcher_path !== ''
                    ? portal.launcher_path
                    : '/portal',
                postLoginPath: typeof portal.post_login_path === 'string' && portal.post_login_path !== ''
                    ? portal.post_login_path
                    : '/',
            };
        },

        hydrate(payload) {
            this.user = payload.user;
            this.roles = payload.roles || [];
            this.permissions = payload.permissions || [];
            this.hydratePortal(payload.portal || {});
            this.isAuthenticated = Boolean(payload.user);
            this.initialized = true;
        },

        setUser(user, roles = [], permissions = [], portal = {}) {
            this.user = user;
            this.roles = roles;
            this.permissions = permissions;
            this.hydratePortal(portal);
            this.isAuthenticated = Boolean(user);
            this.initialized = true;
        },

        clearAuth() {
            this.user = null;
            this.isAuthenticated = false;
            this.roles = [];
            this.permissions = [];
            this.portal = emptyPortalState();
            this.initialized = true;
        },

        resolvePostLoginTarget(redirectTarget = null) {
            const requestedTarget = typeof redirectTarget === 'string'
                ? redirectTarget.trim()
                : '';

            const target = this.shouldIgnoreRedirectTarget(requestedTarget)
                ? (this.portal.postLoginPath || '/')
                : (requestedTarget || this.portal.postLoginPath || '/');

            return {
                target,
                useLocation: this.shouldUseLocation(target),
            };
        },

        shouldIgnoreRedirectTarget(target) {
            if (!this.shouldAutoLaunchHomeApp) {
                return false;
            }

            if (target === '') {
                return true;
            }

            if (/^https?:\/\//.test(target)) {
                return false;
            }

            if (target.startsWith('/portal/apps/') || target.startsWith('/portal/authorize')) {
                return false;
            }

            return true;
        },

        shouldUseLocation(target) {
            return /^https?:\/\//.test(target)
                || target.startsWith('/portal/apps/')
                || target.startsWith('/portal/authorize');
        },

        async logout() {
            try {
                await axios.post('/api/auth/logout');
            } catch (error) {
                console.error('Logout error:', error);
            } finally {
                this.clearAuth();
            }
        },

        async fetchUser() {
            try {
                const response = await axios.get('/api/user');
                this.hydrate(response.data);
                return response.data;
            } catch (error) {
                if (error.response?.status !== 401) {
                    console.error('Error fetching user:', error);
                }

                this.clearAuth();
                throw error;
            }
        },
    },
});
