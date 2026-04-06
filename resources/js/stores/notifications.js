import { defineStore } from 'pinia';
import axios from 'axios';

const POLL_INTERVAL_MS = 5000;
export const TOAST_DURATION_MS = 4800;
const SURFACED_NOTIFICATION_STORAGE_PREFIX = 'gesit:notification-surfaced';

const normalizeNotification = (notification) => ({
    ...notification,
    is_read: Boolean(notification.is_read),
});

const sortNotificationsByDate = (notifications, direction = 'desc') => {
    const multiplier = direction === 'asc' ? 1 : -1;

    return [...notifications].sort((left, right) => {
        const leftTime = new Date(left.created_at || 0).getTime();
        const rightTime = new Date(right.created_at || 0).getTime();

        return (leftTime - rightTime) * multiplier;
    });
};

const getSurfacedStorageKey = (userId) => `${SURFACED_NOTIFICATION_STORAGE_PREFIX}:${userId}`;

const readSurfacedNotificationIds = (userId) => {
    if (typeof window === 'undefined' || !userId) {
        return [];
    }

    try {
        const storedValue = window.sessionStorage.getItem(getSurfacedStorageKey(userId));
        const parsedValue = storedValue ? JSON.parse(storedValue) : [];

        return Array.isArray(parsedValue) ? parsedValue.map((value) => Number(value)).filter(Number.isFinite) : [];
    } catch (error) {
        console.error('Error reading surfaced notification ids:', error);
        return [];
    }
};

const writeSurfacedNotificationIds = (userId, notificationIds) => {
    if (typeof window === 'undefined' || !userId) {
        return;
    }

    try {
        window.sessionStorage.setItem(
            getSurfacedStorageKey(userId),
            JSON.stringify([...new Set(notificationIds.map((value) => Number(value)).filter(Number.isFinite))]),
        );
    } catch (error) {
        console.error('Error writing surfaced notification ids:', error);
    }
};

const clearSurfacedNotificationIds = (userId) => {
    if (typeof window === 'undefined' || !userId) {
        return;
    }

    try {
        window.sessionStorage.removeItem(getSurfacedStorageKey(userId));
    } catch (error) {
        console.error('Error clearing surfaced notification ids:', error);
    }
};

export const useNotificationStore = defineStore('notifications', {
    state: () => ({
        notifications: [],
        unreadCount: 0,
        isLoading: false,
        error: null,
        activeToast: null,
        toastQueue: [],
        currentUserId: null,
    }),

    getters: {
        unreadNotifications: (state) => {
            return state.notifications.filter((notification) => !notification.is_read);
        },

        readNotifications: (state) => {
            return state.notifications.filter((notification) => notification.is_read);
        },

        hasUnreadNotifications: (state) => {
            return state.unreadCount > 0;
        },
    },

    actions: {
        async fetchNotifications(options = {}) {
            const {
                perPage = 20,
                unreadOnly = false,
                silent = false,
            } = options;

            if (!silent) {
                this.isLoading = true;
            }

            this.error = null;

            try {
                const response = await axios.get('/api/notifications', {
                    params: {
                        per_page: perPage,
                        unread_only: unreadOnly ? 1 : 0,
                    },
                });

                const notifications = (response.data.notifications || []).map(normalizeNotification);

                if (!unreadOnly) {
                    this.notifications = notifications;
                }

                this.unreadCount = Number(response.data.unread_count || 0);

                return {
                    ...response.data,
                    notifications,
                };
            } catch (error) {
                console.error('Error fetching notifications:', error);
                this.error = 'Gagal memuat notifikasi';
                throw error;
            } finally {
                if (!silent) {
                    this.isLoading = false;
                }
            }
        },

        async fetchUnreadFeed() {
            try {
                const response = await axios.get('/api/notifications/unread-feed');
                const notifications = (response.data.notifications || []).map(normalizeNotification);

                this.unreadCount = Number(response.data.unread_count || this.unreadCount);

                return notifications;
            } catch (error) {
                console.error('Error fetching unread notification feed:', error);
                throw error;
            }
        },

        async bootstrap(userId) {
            this.stopPolling();
            this.resetRuntimeState();
            this.currentUserId = userId;

            await this.fetchNotifications({ perPage: 20 });

            const unreadNotifications = await this.fetchUnreadFeed();
            this.enqueueToastBatch(unreadNotifications);
            this.startPolling();
        },

        async syncLatest(options = {}) {
            if (!this.currentUserId) {
                return null;
            }

            const { silent = true, enqueueToasts = true } = options;
            const response = await this.fetchNotifications({ perPage: 20, silent });

            if (enqueueToasts) {
                const unreadLatestNotifications = response.notifications.filter((notification) => !notification.is_read);
                this.enqueueToastBatch(unreadLatestNotifications);
            }

            return response;
        },

        startPolling() {
            if (typeof window === 'undefined' || this.pollingHandle || !this.currentUserId) {
                return;
            }

            this.pollingHandle = window.setInterval(() => {
                this.syncLatest({ enqueueToasts: true }).catch((error) => {
                    if (error.response?.status === 401) {
                        this.stopPolling();
                        this.resetRuntimeState();
                    }
                });
            }, POLL_INTERVAL_MS);
        },

        stopPolling() {
            if (this.pollingHandle) {
                window.clearInterval(this.pollingHandle);
                this.pollingHandle = null;
            }
        },

        enqueueToastBatch(notifications) {
            const sortedNotifications = sortNotificationsByDate(
                notifications.filter((notification) => !notification.is_read),
                'asc',
            );

            sortedNotifications.forEach((notification) => {
                this.enqueueToast(notification);
            });
        },

        enqueueToast(notification) {
            const normalizedNotification = normalizeNotification(notification);

            if (normalizedNotification.is_read || !this.currentUserId) {
                return;
            }

            if (this.wasNotificationSurfaced(normalizedNotification.id)) {
                return;
            }

            if (this.activeToast?.id === normalizedNotification.id) {
                return;
            }

            if (this.toastQueue.some((queuedNotification) => queuedNotification.id === normalizedNotification.id)) {
                return;
            }

            this.toastQueue.push(normalizedNotification);

            if (!this.activeToast) {
                this.showNextToast();
            }
        },

        showNextToast() {
            if (this.activeToast || this.toastQueue.length === 0) {
                return;
            }

            const nextToast = this.toastQueue.shift();

            if (!nextToast) {
                return;
            }

            this.activeToast = nextToast;
            this.rememberSurfacedNotification(nextToast.id);

            if (this.toastTimeoutHandle) {
                window.clearTimeout(this.toastTimeoutHandle);
            }

            this.toastTimeoutHandle = window.setTimeout(() => {
                this.dismissActiveToast();
            }, TOAST_DURATION_MS);
        },

        dismissActiveToast() {
            if (this.toastTimeoutHandle) {
                window.clearTimeout(this.toastTimeoutHandle);
                this.toastTimeoutHandle = null;
            }

            this.activeToast = null;

            if (this.toastQueue.length > 0) {
                this.toastTimeoutHandle = window.setTimeout(() => {
                    this.toastTimeoutHandle = null;
                    this.showNextToast();
                }, 220);
            }
        },

        wasNotificationSurfaced(notificationId) {
            return readSurfacedNotificationIds(this.currentUserId).includes(Number(notificationId));
        },

        rememberSurfacedNotification(notificationId) {
            const surfacedNotificationIds = readSurfacedNotificationIds(this.currentUserId);
            surfacedNotificationIds.push(Number(notificationId));
            writeSurfacedNotificationIds(this.currentUserId, surfacedNotificationIds);
        },

        removeNotificationFromToastState(notificationId) {
            this.toastQueue = this.toastQueue.filter((notification) => notification.id !== notificationId);

            if (this.activeToast?.id === notificationId) {
                this.dismissActiveToast();
            }
        },

        async markAsRead(notificationId) {
            try {
                const response = await axios.post(`/api/notifications/${notificationId}/read`);
                const updatedNotification = normalizeNotification(response.data.notification);

                this.notifications = this.notifications.map((notification) => {
                    if (notification.id !== notificationId) {
                        return notification;
                    }

                    return updatedNotification;
                });

                this.unreadCount = Number(response.data.unread_count ?? this.unreadCount);
                this.removeNotificationFromToastState(notificationId);

                return response.data;
            } catch (error) {
                console.error('Error marking notification as read:', error);
                throw error;
            }
        },

        async markAllAsRead() {
            try {
                const response = await axios.post('/api/notifications/read-all');

                this.notifications = this.notifications.map((notification) => ({
                    ...notification,
                    is_read: true,
                }));
                this.unreadCount = Number(response.data.unread_count ?? 0);
                this.toastQueue = [];
                this.dismissActiveToast();

                return response.data;
            } catch (error) {
                console.error('Error marking all notifications as read:', error);
                throw error;
            }
        },

        async deleteNotification(notificationId) {
            try {
                const response = await axios.delete(`/api/notifications/${notificationId}`);

                this.notifications = this.notifications.filter((notification) => notification.id !== notificationId);
                this.unreadCount = Number(response.data.unread_count ?? this.unreadCount);
                this.removeNotificationFromToastState(notificationId);

                return true;
            } catch (error) {
                console.error('Error deleting notification:', error);
                throw error;
            }
        },

        async fetchUnreadCount() {
            try {
                const response = await axios.get('/api/notifications/unread-count');
                this.unreadCount = Number(response.data.unread_count || 0);
                return response.data;
            } catch (error) {
                console.error('Error fetching unread count:', error);
                throw error;
            }
        },

        addNotification(notification) {
            const normalizedNotification = normalizeNotification(notification);

            this.notifications = [
                normalizedNotification,
                ...this.notifications.filter((item) => item.id !== normalizedNotification.id),
            ];

            if (!normalizedNotification.is_read) {
                this.unreadCount += 1;
                this.enqueueToast(normalizedNotification);
            }
        },

        resetRuntimeState() {
            this.notifications = [];
            this.unreadCount = 0;
            this.error = null;
            this.activeToast = null;
            this.toastQueue = [];

            if (this.toastTimeoutHandle) {
                window.clearTimeout(this.toastTimeoutHandle);
                this.toastTimeoutHandle = null;
            }
        },

        clearNotifications() {
            this.resetRuntimeState();
        },

        resetForUserChange(previousUserId, options = {}) {
            const { clearSurfaced = false } = options;

            this.stopPolling();
            this.resetRuntimeState();

            if (clearSurfaced && previousUserId) {
                clearSurfacedNotificationIds(previousUserId);
            }

            this.currentUserId = null;
        },
    },
});
