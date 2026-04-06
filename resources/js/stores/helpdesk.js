import { defineStore } from 'pinia';
import axios from 'axios';

const emptyFilters = () => ({
    categories: [],
    priorities: [],
    statuses: [],
    channels: [],
});

const emptyStats = () => ({
    all: 0,
    open: 0,
    in_progress: 0,
    waiting_user: 0,
    resolved: 0,
    closed: 0,
});

const appendFormDataValue = (formData, key, value) => {
    if (value === undefined || value === null || value === '') {
        return;
    }

    if (value instanceof File) {
        formData.append(key, value);
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item, index) => {
            appendFormDataValue(formData, `${key}[${index}]`, item);
        });
        return;
    }

    if (typeof value === 'object') {
        Object.entries(value).forEach(([nestedKey, nestedValue]) => {
            appendFormDataValue(formData, `${key}[${nestedKey}]`, nestedValue);
        });
        return;
    }

    if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
        return;
    }

    formData.append(key, value);
};

const buildTicketFormData = (payload) => {
    const formData = new FormData();

    Object.entries(payload || {}).forEach(([key, value]) => {
        appendFormDataValue(formData, key, value);
    });

    return formData;
};

export const useHelpdeskStore = defineStore('helpdesk', {
    state: () => ({
        tickets: [],
        activeTicket: null,
        stats: emptyStats(),
        filters: emptyFilters(),
        requesters: [],
        assignees: [],
        canManage: false,
    }),

    getters: {
        getTicketById: (state) => (id) => state.tickets.find((ticket) => ticket.id === Number(id)),
        hasTickets: (state) => state.tickets.length > 0,
    },

    actions: {
        applyCollectionPayload(payload) {
            this.tickets = payload.tickets || [];
            this.stats = {
                ...emptyStats(),
                ...(payload.stats || {}),
            };
            this.filters = {
                ...emptyFilters(),
                ...(payload.filters || {}),
            };
            this.requesters = payload.requesters || [];
            this.assignees = payload.assignees || [];
            this.canManage = Boolean(payload.can_manage);
        },

        applyDetailPayload(payload) {
            this.activeTicket = payload.ticket || null;
            this.filters = {
                ...emptyFilters(),
                ...(payload.filters || this.filters),
            };
            this.requesters = payload.requesters || this.requesters;
            this.assignees = payload.assignees || this.assignees;
            this.canManage = Boolean(payload.can_manage ?? this.canManage);

            if (this.activeTicket) {
                this.upsertTicket(this.activeTicket);
            }
        },

        upsertTicket(ticket) {
            const index = this.tickets.findIndex((item) => item.id === ticket.id);

            if (index === -1) {
                this.tickets.unshift(ticket);
                return;
            }

            this.tickets[index] = ticket;
        },

        async fetchTickets(params = {}) {
            try {
                const response = await axios.get('/api/helpdesk/tickets', { params });
                this.applyCollectionPayload(response.data);
                return response.data;
            } catch (error) {
                console.error('Error fetching helpdesk tickets:', error);
                throw error;
            }
        },

        async fetchTicket(id) {
            try {
                const response = await axios.get(`/api/helpdesk/tickets/${id}`);
                this.applyDetailPayload(response.data);
                return response.data;
            } catch (error) {
                console.error('Error fetching helpdesk ticket:', error);
                throw error;
            }
        },

        async createTicket(payload) {
            try {
                const response = await axios.post('/api/helpdesk/tickets', buildTicketFormData(payload), {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                });

                if (response.data.ticket) {
                    this.upsertTicket(response.data.ticket);
                }

                return response.data;
            } catch (error) {
                console.error('Error creating helpdesk ticket:', error);
                throw error;
            }
        },

        async updateTicket(id, payload) {
            try {
                const response = await axios.put(`/api/helpdesk/tickets/${id}`, payload);

                if (response.data.ticket) {
                    this.activeTicket = response.data.ticket;
                    this.upsertTicket(response.data.ticket);
                }

                return response.data;
            } catch (error) {
                console.error('Error updating helpdesk ticket:', error);
                throw error;
            }
        },

        async addUpdate(id, payload) {
            try {
                const response = await axios.post(`/api/helpdesk/tickets/${id}/updates`, payload);

                if (response.data.ticket) {
                    this.activeTicket = response.data.ticket;
                    this.upsertTicket(response.data.ticket);
                }

                return response.data;
            } catch (error) {
                console.error('Error adding helpdesk update:', error);
                throw error;
            }
        },

        resetActiveTicket() {
            this.activeTicket = null;
        },
    },
});
