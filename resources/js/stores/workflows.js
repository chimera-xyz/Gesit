import { defineStore } from 'pinia';
import axios from 'axios';

const sortWorkflows = (workflows) => [...workflows].sort((left, right) => {
    if (left.is_active !== right.is_active) {
        return left.is_active ? -1 : 1;
    }

    return (left.name || '').localeCompare(right.name || '');
});

export const useWorkflowStore = defineStore('workflows', {
    state: () => ({
        workflows: [],
        roles: [],
        users: [],
    }),

    actions: {
        async fetchWorkflows() {
            try {
                const response = await axios.get('/api/workflows');
                this.workflows = sortWorkflows(response.data.workflows || []);

                return response.data;
            } catch (error) {
                console.error('Error fetching workflows:', error);
                throw error;
            }
        },

        async fetchCatalog() {
            try {
                const [rolesResponse, usersResponse] = await Promise.all([
                    axios.get('/api/roles'),
                    axios.get('/api/users'),
                ]);

                this.roles = (rolesResponse.data.roles || [])
                    .filter((role) => role.is_active)
                    .map((role) => role.name);
                this.users = (usersResponse.data.users || [])
                    .filter((user) => user.is_active)
                    .map((user) => ({
                        id: user.id,
                        name: user.name,
                        email: user.email,
                        department: user.department,
                        roles: user.roles || [],
                    }))
                    .sort((left, right) => left.name.localeCompare(right.name));

                return {
                    roles: this.roles,
                    users: this.users,
                };
            } catch (error) {
                console.error('Error fetching workflow catalog:', error);
                throw error;
            }
        },

        async createWorkflow(payload) {
            try {
                const response = await axios.post('/api/workflows', payload);
                this.workflows = sortWorkflows([...this.workflows, response.data.workflow]);

                return response.data;
            } catch (error) {
                console.error('Error creating workflow:', error);
                throw error;
            }
        },

        async updateWorkflow(id, payload) {
            try {
                const response = await axios.put(`/api/workflows/${id}`, payload);
                const index = this.workflows.findIndex((workflow) => workflow.id === id);

                if (index !== -1) {
                    this.workflows.splice(index, 1, response.data.workflow);
                    this.workflows = sortWorkflows(this.workflows);
                }

                return response.data;
            } catch (error) {
                console.error('Error updating workflow:', error);
                throw error;
            }
        },

        async deleteWorkflow(id) {
            try {
                await axios.delete(`/api/workflows/${id}`);
                this.workflows = this.workflows.filter((workflow) => workflow.id !== id);
            } catch (error) {
                console.error('Error deleting workflow:', error);
                throw error;
            }
        },
    },
});
