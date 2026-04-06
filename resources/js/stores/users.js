import { defineStore } from 'pinia';
import axios from 'axios';

export const useUserStore = defineStore('users', {
    state: () => ({
        users: [],
        roles: [],
    }),

    getters: {
        getUserById: (state) => (id) => state.users.find((user) => user.id === id),
    },

    actions: {
        sortUsers() {
            this.users = [...this.users].sort((left, right) => {
                if (left.is_active !== right.is_active) {
                    return Number(right.is_active) - Number(left.is_active);
                }

                return left.name.localeCompare(right.name, 'id');
            });
        },

        async fetchUsers(params = {}) {
            try {
                const response = await axios.get('/api/users', { params });
                this.users = response.data.users || [];
                this.roles = response.data.roles || [];
                this.sortUsers();
                return response.data;
            } catch (error) {
                console.error('Error fetching users:', error);
                throw error;
            }
        },

        async createUser(payload) {
            try {
                const response = await axios.post('/api/users', payload);
                this.users.unshift(response.data.user);
                this.sortUsers();
                return response.data;
            } catch (error) {
                console.error('Error creating user:', error);
                throw error;
            }
        },

        async updateUser(id, payload) {
            try {
                const response = await axios.put(`/api/users/${id}`, payload);
                const index = this.users.findIndex((user) => user.id === id);

                if (index !== -1) {
                    this.users[index] = response.data.user;
                }

                this.sortUsers();

                return response.data;
            } catch (error) {
                console.error('Error updating user:', error);
                throw error;
            }
        },

        async deleteUser(id) {
            try {
                await axios.delete(`/api/users/${id}`);
                this.users = this.users.filter((user) => user.id !== id);
                return true;
            } catch (error) {
                console.error('Error deleting user:', error);
                throw error;
            }
        },
    },
});
