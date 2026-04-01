import { defineStore } from 'pinia';
import axios from 'axios';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        isAuthenticated: false,
        roles: [],
        permissions: [],
        initialized: false,
    }),

    getters: {
        hasRole: (state) => (role) => {
            return state.roles.includes(role);
        },

        hasAnyRole: (state) => (roles) => {
            return roles.some(role => state.roles.includes(role));
        },

        hasPermission: (state) => (permission) => {
            return state.permissions.includes(permission);
        },

        isAdmin: (state) => {
            return state.roles.includes('Admin');
        },

        canApprove: (state) => {
            return ['IT Staff', 'Operational Director', 'Accounting'].some(role =>
                state.roles.includes(role)
            );
        },
    },

    actions: {
        hydrate(payload) {
            this.user = payload.user;
            this.roles = payload.roles || [];
            this.permissions = payload.permissions || [];
            this.isAuthenticated = Boolean(payload.user);
            this.initialized = true;
        },

        setUser(user, roles = [], permissions = []) {
            this.user = user;
            this.roles = roles;
            this.permissions = permissions;
            this.isAuthenticated = Boolean(user);
            this.initialized = true;
        },

        clearAuth() {
            this.user = null;
            this.isAuthenticated = false;
            this.roles = [];
            this.permissions = [];
            this.initialized = true;
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
