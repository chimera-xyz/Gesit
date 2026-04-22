import { defineStore } from 'pinia';
import axios from 'axios';

export const useMobileAppReleaseStore = defineStore('mobile-app-releases', {
    state: () => ({
        releases: [],
        meta: {
            platforms: ['android'],
            channels: ['production'],
            latest_published_version_code: null,
            next_version_code_suggestion: 1,
            minimum_supported_version_code_suggestion: 1,
        },
    }),

    getters: {
        latestPublishedRelease: (state) => state.releases.find((release) => release.is_published) ?? null,
    },

    actions: {
        sortReleases() {
            this.releases = [...this.releases].sort((left, right) => {
                if (left.version_code !== right.version_code) {
                    return Number(right.version_code) - Number(left.version_code);
                }

                return Number(right.id) - Number(left.id);
            });
        },

        async fetchReleases() {
            const response = await axios.get('/api/mobile-app/releases');
            this.releases = response.data.releases || [];
            this.meta = response.data.meta || this.meta;
            this.sortReleases();
            return response.data;
        },

        async createRelease(formData) {
            const response = await axios.post('/api/mobile-app/releases', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            this.releases.unshift(response.data.release);
            this.sortReleases();
            return response.data;
        },

        async updateRelease(id, formData) {
            formData.append('_method', 'PUT');

            const response = await axios.post(`/api/mobile-app/releases/${id}`, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            const index = this.releases.findIndex((release) => release.id === id);
            if (index !== -1) {
                this.releases[index] = response.data.release;
            }

            this.sortReleases();
            return response.data;
        },

        async publishRelease(id) {
            const response = await axios.post(`/api/mobile-app/releases/${id}/publish`);
            this._replaceRelease(response.data.release);
            return response.data;
        },

        async unpublishRelease(id) {
            const response = await axios.post(`/api/mobile-app/releases/${id}/unpublish`);
            this._replaceRelease(response.data.release);
            return response.data;
        },

        async deleteRelease(id) {
            const response = await axios.delete(`/api/mobile-app/releases/${id}`);
            this.releases = this.releases.filter((release) => release.id !== id);
            return response.data;
        },

        _replaceRelease(release) {
            const index = this.releases.findIndex((item) => item.id === release.id);
            if (index !== -1) {
                this.releases[index] = release;
            } else {
                this.releases.unshift(release);
            }

            this.sortReleases();
        },
    },
});
