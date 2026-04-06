import { defineStore } from 'pinia';
import axios from 'axios';

const emptyPagination = () => ({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});

export const useSubmissionStore = defineStore('submissions', {
    state: () => ({
        submissions: [],
        activeSubmission: null,
        approvalSteps: [],
        pendingCount: 0,
        pagination: emptyPagination(),
    }),

    getters: {
        getSubmissionById: (state) => (id) => {
            return state.submissions.find(submission => submission.id === id);
        },

        getPendingSubmissions: (state) => {
            return state.submissions.filter(submission =>
                ['submitted', 'pending_it', 'pending_director', 'pending_accounting', 'pending_payment'].includes(submission.current_status)
            );
        },

        getActionableSubmissions: (state) => {
            return state.submissions.filter(submission =>
                Array.isArray(submission.available_actions) && submission.available_actions.length > 0
            );
        },

        getApprovedSubmissions: (state) => {
            return state.submissions.filter(submission =>
                ['completed'].includes(submission.current_status)
            );
        },

        getRejectedSubmissions: (state) => {
            return state.submissions.filter(submission =>
                ['rejected'].includes(submission.current_status)
            );
        },
    },

    actions: {
        applyCollectionPayload(payload) {
            this.submissions = payload.submissions || [];
            this.pagination = {
                ...emptyPagination(),
                ...(payload.pagination || {}),
            };
            this.pendingCount = this.getPendingSubmissions.length;
        },

        async fetchSubmissions(params = {}) {
            try {
                const response = await axios.get('/api/form-submissions', { params });
                this.applyCollectionPayload(response.data);
                return response.data;
            } catch (error) {
                console.error('Error fetching submissions:', error);
                throw error;
            }
        },

        async fetchSubmission(id) {
            try {
                const response = await axios.get(`/api/form-submissions/${id}`);
                this.activeSubmission = response.data.submission;
                this.approvalSteps = response.data.submission?.approval_steps || [];
                return response.data;
            } catch (error) {
                console.error('Error fetching submission:', error);
                throw error;
            }
        },

        async createSubmission(formData) {
            try {
                const response = await axios.post('/api/form-submissions', formData);
                this.submissions.unshift(response.data.submission);
                return response.data;
            } catch (error) {
                console.error('Error creating submission:', error);
                throw error;
            }
        },

        async approveSubmission(submissionId, approvalData) {
            try {
                const response = await axios.put(`/api/form-submissions/${submissionId}/approve`, approvalData);
                const index = this.submissions.findIndex(submission => submission.id === submissionId);
                if (index !== -1) {
                    this.submissions[index] = response.data.submission;
                }
                return response.data;
            } catch (error) {
                console.error('Error approving submission:', error);
                throw error;
            }
        },

        async rejectSubmission(submissionId, rejectionData) {
            try {
                const response = await axios.post(`/api/form-submissions/${submissionId}/reject`, rejectionData);
                const index = this.submissions.findIndex(submission => submission.id === submissionId);
                if (index !== -1) {
                    this.submissions[index] = response.data.submission;
                }
                return response.data;
            } catch (error) {
                console.error('Error rejecting submission:', error);
                throw error;
            }
        },

        async fetchApprovalSteps(submissionId) {
            try {
                const response = await axios.get(`/api/form-submissions/${submissionId}/approval-steps`);
                this.approvalSteps = response.data;
                return response.data;
            } catch (error) {
                console.error('Error fetching approval steps:', error);
                throw error;
            }
        },
    },
});
