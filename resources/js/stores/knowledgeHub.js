import { defineStore } from 'pinia';
import axios from 'axios';

const emptyHubState = () => ({
    spaces: [],
    entries: [],
    bookmarkedIds: [],
    filters: {
        types: [],
        scopes: [],
    },
    suggestedQuestions: [],
});

const emptyAdminState = () => ({
    general: null,
    divisions: [],
    roles: [],
    catalogs: {
        types: [],
        scopes: [],
        source_kinds: [],
        access_modes: [],
        ai_providers: [],
    },
});

const compareConversationSummaries = (left, right) => {
    const leftDate = new Date(left?.last_message_at || left?.updated_at || 0).getTime();
    const rightDate = new Date(right?.last_message_at || right?.updated_at || 0).getTime();

    return rightDate - leftDate;
};

const normalizeConversationId = (conversationId) => {
    if (conversationId === null || conversationId === undefined || conversationId === '') {
        return null;
    }

    const parsed = Number.parseInt(`${conversationId}`, 10);

    return Number.isNaN(parsed) ? null : parsed;
};

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

    if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
        return;
    }

    formData.append(key, value);
};

const buildFormData = (payload) => {
    const formData = new FormData();

    Object.entries(payload || {}).forEach(([key, value]) => {
        appendFormDataValue(formData, key, value);
    });

    return formData;
};

export const useKnowledgeHubStore = defineStore('knowledgeHub', {
    state: () => ({
        hub: emptyHubState(),
        admin: emptyAdminState(),
        conversation: [],
        activeConversationId: null,
        conversations: [],
        conversationMessages: {},
        conversationsLoaded: false,
    }),

    getters: {
        spaces: (state) => state.hub.spaces || [],
        entries: (state) => state.hub.entries || [],
        adminGeneral: (state) => state.admin.general || null,
        adminDivisions: (state) => state.admin.divisions || [],
        adminSpaces: (state) => state.admin.divisions || [],
        adminRoles: (state) => state.admin.roles || [],
        adminCatalogs: (state) => state.admin.catalogs || emptyAdminState().catalogs,
        conversationSummaries: (state) => state.conversations || [],
    },

    actions: {
        resetConversation() {
            this.activeConversationId = null;
            this.conversation = [];
        },

        setConversation(messages) {
            this.conversation = messages || [];
        },

        setActiveConversation(conversationId) {
            this.activeConversationId = normalizeConversationId(conversationId);
        },

        setConversationMessages(conversationId, messages) {
            const normalizedConversationId = normalizeConversationId(conversationId);

            if (!normalizedConversationId) {
                return;
            }

            this.conversationMessages = {
                ...this.conversationMessages,
                [normalizedConversationId]: messages || [],
            };
        },

        upsertConversationSummary(summary) {
            const next = [...(this.conversations || [])];
            const existingIndex = next.findIndex((conversation) => conversation.id === summary.id);

            if (existingIndex >= 0) {
                next.splice(existingIndex, 1, summary);
            } else {
                next.push(summary);
            }

            this.conversations = next.sort(compareConversationSummaries);
        },

        syncConversationRecord(summary, messages) {
            this.upsertConversationSummary(summary);
            this.setActiveConversation(summary.id);
            this.setConversation(messages);
            this.setConversationMessages(summary.id, messages);
        },

        removeConversationRecord(conversationId) {
            const normalizedConversationId = normalizeConversationId(conversationId);

            if (!normalizedConversationId) {
                return;
            }

            this.conversations = (this.conversations || []).filter((conversation) => conversation.id !== normalizedConversationId);

            const nextMessages = { ...this.conversationMessages };
            delete nextMessages[normalizedConversationId];
            this.conversationMessages = nextMessages;

            if (this.activeConversationId === normalizedConversationId) {
                this.resetConversation();
            }
        },

        async fetchHub() {
            try {
                const response = await axios.get('/api/knowledge-hub');
                this.hub = {
                    spaces: response.data.spaces || [],
                    entries: response.data.entries || [],
                    bookmarkedIds: response.data.bookmarked_ids || [],
                    filters: response.data.filters || emptyHubState().filters,
                    suggestedQuestions: response.data.suggested_questions || [],
                };

                return response.data;
            } catch (error) {
                console.error('Error fetching knowledge hub:', error);
                throw error;
            }
        },

        async askQuestion(question) {
            try {
                const response = await axios.post('/api/knowledge-hub/ask', {
                    question,
                    conversation_id: this.activeConversationId,
                });
                return response.data;
            } catch (error) {
                console.error('Error asking knowledge assistant:', error);
                throw error;
            }
        },

        async runConversationAction(conversationId, payload) {
            try {
                const response = await axios.post(`/api/knowledge-hub/conversations/${conversationId}/actions`, payload);
                return response.data;
            } catch (error) {
                console.error('Error running knowledge conversation action:', error);
                throw error;
            }
        },

        async fetchConversations() {
            try {
                const response = await axios.get('/api/knowledge-hub/conversations');
                this.conversations = (response.data.conversations || []).sort(compareConversationSummaries);
                this.conversationsLoaded = true;

                return response.data;
            } catch (error) {
                console.error('Error fetching knowledge conversations:', error);
                throw error;
            }
        },

        async searchConversations(search) {
            try {
                const response = await axios.get('/api/knowledge-hub/conversations', {
                    params: {
                        search,
                    },
                });

                response.data.conversations = (response.data.conversations || []).sort(compareConversationSummaries);

                return response.data;
            } catch (error) {
                console.error('Error searching knowledge conversations:', error);
                throw error;
            }
        },

        async fetchConversation(id, options = {}) {
            const { force = false } = options;
            const normalizedConversationId = normalizeConversationId(id);

            if (!normalizedConversationId) {
                return {
                    conversation: null,
                    messages: [],
                };
            }

            if (!force && this.conversationMessages[normalizedConversationId]) {
                this.activeConversationId = normalizedConversationId;
                this.conversation = this.conversationMessages[normalizedConversationId];

                return {
                    conversation: this.conversations.find((item) => item.id === normalizedConversationId) || null,
                    messages: this.conversationMessages[normalizedConversationId],
                };
            }

            try {
                const response = await axios.get(`/api/knowledge-hub/conversations/${normalizedConversationId}`);
                const messages = response.data.messages || [];
                const conversation = response.data.conversation || null;

                if (conversation) {
                    this.syncConversationRecord(conversation, messages);
                } else {
                    this.activeConversationId = normalizedConversationId;
                    this.conversation = messages;
                    this.setConversationMessages(normalizedConversationId, messages);
                }

                return response.data;
            } catch (error) {
                console.error('Error fetching knowledge conversation:', error);
                throw error;
            }
        },

        async renameConversation(id, title) {
            const normalizedConversationId = normalizeConversationId(id);

            try {
                const response = await axios.patch(`/api/knowledge-hub/conversations/${normalizedConversationId}`, {
                    title,
                });
                const conversation = response.data.conversation || null;

                if (conversation) {
                    this.upsertConversationSummary(conversation);
                }

                return response.data;
            } catch (error) {
                console.error('Error renaming knowledge conversation:', error);
                throw error;
            }
        },

        async deleteConversation(id) {
            const normalizedConversationId = normalizeConversationId(id);

            try {
                const response = await axios.delete(`/api/knowledge-hub/conversations/${normalizedConversationId}`);
                this.removeConversationRecord(normalizedConversationId);

                return response.data;
            } catch (error) {
                console.error('Error deleting knowledge conversation:', error);
                throw error;
            }
        },

        async toggleBookmark(entryId) {
            try {
                const response = await axios.post(`/api/knowledge-hub/entries/${entryId}/bookmark`);
                const { bookmarked } = response.data;

                this.hub.entries = (this.hub.entries || []).map((entry) => (
                    entry.id === entryId
                        ? { ...entry, is_bookmarked: bookmarked }
                        : entry
                ));

                if (bookmarked) {
                    this.hub.bookmarkedIds = [...new Set([...(this.hub.bookmarkedIds || []), entryId])];
                } else {
                    this.hub.bookmarkedIds = (this.hub.bookmarkedIds || []).filter((id) => id !== entryId);
                }

                this.hub.spaces = (this.hub.spaces || []).map((space) => ({
                    ...space,
                    sections: (space.sections || []).map((section) => ({
                        ...section,
                        entries: (section.entries || []).map((entry) => (
                            entry.id === entryId
                                ? { ...entry, is_bookmarked: bookmarked }
                                : entry
                        )),
                    })),
                }));

                return response.data;
            } catch (error) {
                console.error('Error toggling knowledge bookmark:', error);
                throw error;
            }
        },

        async createHubFolder(spaceId, payload) {
            try {
                const response = await axios.post(`/api/knowledge-hub/spaces/${spaceId}/folders`, payload);
                return response.data;
            } catch (error) {
                console.error('Error creating knowledge hub folder:', error);
                throw error;
            }
        },

        async uploadHubEntry(spaceId, payload) {
            const formData = buildFormData(payload);

            try {
                const response = await axios.post(`/api/knowledge-hub/spaces/${spaceId}/entries`, formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                });

                return response.data;
            } catch (error) {
                console.error('Error uploading knowledge hub document:', error);
                throw error;
            }
        },

        async fetchAdmin() {
            try {
                const response = await axios.get('/api/knowledge-admin');
                this.admin = {
                    general: response.data.general || null,
                    divisions: response.data.divisions || [],
                    roles: response.data.roles || [],
                    catalogs: response.data.catalogs || emptyAdminState().catalogs,
                };

                return response.data;
            } catch (error) {
                console.error('Error fetching knowledge admin data:', error);
                throw error;
            }
        },

        async saveGeneral(payload) {
            try {
                return await axios.put('/api/knowledge-admin/general', payload);
            } catch (error) {
                console.error('Error saving general knowledge settings:', error);
                throw error;
            }
        },

        async saveDivision(payload) {
            try {
                if (payload.id) {
                    return await axios.put(`/api/knowledge-admin/spaces/${payload.id}`, payload);
                }

                return await axios.post('/api/knowledge-admin/spaces', payload);
            } catch (error) {
                console.error('Error saving knowledge division:', error);
                throw error;
            }
        },

        async deleteDivision(id) {
            try {
                return await axios.delete(`/api/knowledge-admin/spaces/${id}`);
            } catch (error) {
                console.error('Error deleting knowledge division:', error);
                throw error;
            }
        },

        async saveDocument(payload) {
            const formData = buildFormData(payload);

            try {
                if (payload.id) {
                    formData.append('_method', 'PUT');

                    return await axios.post(`/api/knowledge-admin/entries/${payload.id}`, formData, {
                        headers: {
                            'Content-Type': 'multipart/form-data',
                        },
                    });
                }

                return await axios.post('/api/knowledge-admin/entries', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                });
            } catch (error) {
                console.error('Error saving knowledge document:', error);
                throw error;
            }
        },

        async deleteDocument(id) {
            try {
                return await axios.delete(`/api/knowledge-admin/entries/${id}`);
            } catch (error) {
                console.error('Error deleting knowledge document:', error);
                throw error;
            }
        },

        async saveSpace(payload) {
            return this.saveDivision(payload);
        },

        async deleteSpace(id) {
            return this.deleteDivision(id);
        },

        async saveSection(payload) {
            try {
                if (payload.id) {
                    return await axios.put(`/api/knowledge-admin/sections/${payload.id}`, payload);
                }

                return await axios.post('/api/knowledge-admin/sections', payload);
            } catch (error) {
                console.error('Error saving knowledge section:', error);
                throw error;
            }
        },

        async deleteSection(id) {
            try {
                return await axios.delete(`/api/knowledge-admin/sections/${id}`);
            } catch (error) {
                console.error('Error deleting knowledge section:', error);
                throw error;
            }
        },

        async saveEntry(payload) {
            return this.saveDocument(payload);
        },

        async deleteEntry(id) {
            return this.deleteDocument(id);
        },
    },
});
