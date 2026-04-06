import { defineStore } from 'pinia';
import axios from 'axios';

export const useFormStore = defineStore('forms', {
    state: () => ({
        forms: [],
        formBuilders: [],
        activeForm: null,
        builderFields: [], // For drag-and-drop form builder
        draggedField: null,
    }),

    getters: {
        getFormById: (state) => (id) => {
            return state.forms.find(form => form.id === id);
        },

        getFormsByWorkflow: (state) => (workflowId) => {
            return state.forms.filter(form => form.workflow_id === workflowId);
        },
    },

    actions: {
        async fetchForms() {
            try {
                const response = await axios.get('/api/forms');
                this.forms = response.data.forms;
                return response.data;
            } catch (error) {
                console.error('Error fetching forms:', error);
                throw error;
            }
        },

        async fetchForm(id) {
            try {
                const response = await axios.get(`/api/forms/${id}`);
                this.activeForm = response.data.form;
                return response.data;
            } catch (error) {
                console.error('Error fetching form:', error);
                throw error;
            }
        },

        async createForm(formData) {
            try {
                const response = await axios.post('/api/forms', formData);
                this.forms.push(response.data.form);
                this.activeForm = response.data.form;
                return response.data;
            } catch (error) {
                console.error('Error creating form:', error);
                throw error;
            }
        },

        async updateForm(id, formData) {
            try {
                const response = await axios.put(`/api/forms/${id}`, formData);
                const index = this.forms.findIndex(form => form.id === id);
                if (index !== -1) {
                    this.forms[index] = response.data.form;
                }
                if (this.activeForm?.id === id) {
                    this.activeForm = response.data.form;
                }
                return response.data;
            } catch (error) {
                console.error('Error updating form:', error);
                throw error;
            }
        },

        async deleteForm(id) {
            try {
                await axios.delete(`/api/forms/${id}`);
                this.forms = this.forms.filter(form => form.id !== id);
                if (this.activeForm?.id === id) {
                    this.activeForm = null;
                }
                return true;
            } catch (error) {
                console.error('Error deleting form:', error);
                throw error;
            }
        },

        // Form Builder Actions
        addField(field) {
            this.builderFields.push({
                ...field,
                id: `field_${Date.now()}`,
            });
        },

        removeField(fieldId) {
            this.builderFields = this.builderFields.filter(field => field.id !== fieldId);
        },

        updateField(fieldId, updates) {
            const index = this.builderFields.findIndex(field => field.id === fieldId);
            if (index !== -1) {
                this.builderFields[index] = { ...this.builderFields[index], ...updates };
            }
        },

        moveField(fromIndex, toIndex) {
            const field = this.builderFields.splice(fromIndex, 1)[0];
            this.builderFields.splice(toIndex, 0, field);
        },

        setBuilderFields(fields) {
            this.builderFields = fields;
        },

        resetBuilder() {
            this.builderFields = [];
            this.draggedField = null;
        },
    },
});
