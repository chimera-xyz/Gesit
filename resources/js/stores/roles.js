import { defineStore } from 'pinia';
import axios from 'axios';

export const useRoleStore = defineStore('roles', {
    state: () => ({
        roles: [],
        permissions: [],
    }),

    actions: {
        sortRoles() {
            this.roles = [...this.roles].sort((left, right) => {
                if (left.is_system !== right.is_system) {
                    return Number(right.is_system) - Number(left.is_system);
                }

                if (left.is_active !== right.is_active) {
                    return Number(right.is_active) - Number(left.is_active);
                }

                return left.name.localeCompare(right.name, 'id');
            });
        },

        async fetchRoles() {
            try {
                const response = await axios.get('/api/roles');
                this.roles = response.data.roles || [];
                this.permissions = response.data.permissions || [];
                this.sortRoles();
                return response.data;
            } catch (error) {
                console.error('Error fetching roles:', error);
                throw error;
            }
        },

        async createRole(payload) {
            try {
                const response = await axios.post('/api/roles', payload);
                this.roles.push(response.data.role);
                this.sortRoles();
                return response.data;
            } catch (error) {
                console.error('Error creating role:', error);
                throw error;
            }
        },

        async updateRole(id, payload) {
            try {
                const response = await axios.put(`/api/roles/${id}`, payload);
                const index = this.roles.findIndex((role) => role.id === id);

                if (index !== -1) {
                    this.roles[index] = response.data.role;
                }

                this.sortRoles();
                return response.data;
            } catch (error) {
                console.error('Error updating role:', error);
                throw error;
            }
        },

        async deleteRole(id) {
            try {
                await axios.delete(`/api/roles/${id}`);
                this.roles = this.roles.filter((role) => role.id !== id);
                return true;
            } catch (error) {
                console.error('Error deleting role:', error);
                throw error;
            }
        },
    },
});
