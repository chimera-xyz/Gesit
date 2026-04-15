import { defineStore } from 'pinia';
import axios from 'axios';

const emptyPagination = () => ({
    current_page: 1,
    per_page: 25,
    total: 0,
    last_page: 1,
});

const emptyStats = () => ({
    total: 0,
    helpdesk: 0,
    submission: 0,
    internal: 0,
});

const emptyFilters = () => ({
    modules: [],
});

const sanitizeParams = (params = {}) => {
    const searchParams = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        searchParams.set(key, String(value));
    });

    return searchParams.toString();
};

export const useItActivityStore = defineStore('itActivities', {
    state: () => ({
        activities: [],
        pagination: emptyPagination(),
        stats: emptyStats(),
        filters: emptyFilters(),
        appliedFilters: {
            search: '',
            module: 'all',
            date_from: '',
            date_to: '',
        },
        filterSummary: '',
    }),

    actions: {
        applyPayload(payload) {
            this.activities = payload.activities || [];
            this.pagination = {
                ...emptyPagination(),
                ...(payload.pagination || {}),
            };
            this.stats = {
                ...emptyStats(),
                ...(payload.stats || {}),
            };
            this.filters = {
                ...emptyFilters(),
                ...(payload.filters || {}),
            };
            this.appliedFilters = {
                search: '',
                module: 'all',
                date_from: '',
                date_to: '',
                ...(payload.applied_filters || {}),
            };
            this.filterSummary = payload.filter_summary || '';
        },

        async fetchActivities(params = {}) {
            try {
                const response = await axios.get('/api/it-activities', { params });
                this.applyPayload(response.data);
                return response.data;
            } catch (error) {
                console.error('Error fetching IT activities:', error);
                throw error;
            }
        },

        exportUrl(params = {}) {
            const query = sanitizeParams(params);

            return query === ''
                ? '/api/it-activities/export'
                : `/api/it-activities/export?${query}`;
        },
    },
});
