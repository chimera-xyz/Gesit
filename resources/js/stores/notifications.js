import { defineStore } from 'pinia';
import axios from 'axios';

export const useNotificationStore = defineStore('notifications', {
    state: () => ({
        notifications: [],
        unreadCount: 0,
        isLoading: false,
        error: null,
    }),

    getters: {
        unreadNotifications: (state) => {
            return state.notifications.filter(notification => !notification.is_read);
        },

        readNotifications: (state) => {
            return state.notifications.filter(notification => notification.is_read);
        },

        hasUnreadNotifications: (state) => {
            return state.unreadCount > 0;
        },
    },

    actions: {
        async fetchNotifications() {
            this.isLoading = true;
            this.error = null;

            try {
                const response = await axios.get('/api/notifications');
                this.notifications = response.data.notifications;
                this.unreadCount = response.data.unread_count;
                return response.data;
            } catch (error) {
                console.error('Error fetching notifications:', error);
                this.error = 'Gagal memuat notifikasi';
                throw error;
            } finally {
                this.isLoading = false;
            }
        },

        async markAsRead(notificationId) {
            try {
                const response = await axios.post(`/api/notifications/${notificationId}/read`);

                // Update local notification state
                const notification = this.notifications.find(n => n.id === notificationId);
                if (notification) {
                    notification.is_read = true;
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                }

                return response.data;
            } catch (error) {
                console.error('Error marking notification as read:', error);
                throw error;
            }
        },

        async markAllAsRead() {
            try {
                const response = await axios.post('/api/notifications/read-all');

                // Update local state
                this.notifications.forEach(notification => {
                    notification.is_read = true;
                });
                this.unreadCount = 0;

                return response.data;
            } catch (error) {
                console.error('Error marking all notifications as read:', error);
                throw error;
            }
        },

        async deleteNotification(notificationId) {
            try {
                await axios.delete(`/api/notifications/${notificationId}`);

                const deletedNotification = this.notifications.find(n => n.id === notificationId);

                // Remove from local state
                this.notifications = this.notifications.filter(n => n.id !== notificationId);

                // Update unread count if needed
                if (deletedNotification && !deletedNotification.is_read) {
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                }

                return true;
            } catch (error) {
                console.error('Error deleting notification:', error);
                throw error;
            }
        },

        async fetchUnreadCount() {
            try {
                const response = await axios.get('/api/notifications/unread-count');
                this.unreadCount = response.data.unread_count;
                return response.data;
            } catch (error) {
                console.error('Error fetching unread count:', error);
                throw error;
            }
        },

        addNotification(notification) {
            this.notifications.unshift(notification);
            if (!notification.is_read) {
                this.unreadCount++;
            }
        },

        clearNotifications() {
            this.notifications = [];
            this.unreadCount = 0;
        },
    },
});
