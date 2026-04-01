<template>
  <div class="notifications-dropdown">
    <!-- Notification Button -->
    <button
      @click="toggleDropdown"
      class="relative p-2 text-gray-600 hover:text-gray-900 focus:outline-none"
    >
      <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
      </svg>
      <span
        v-if="notificationStore.hasUnreadNotifications"
        class="absolute top-0 right-0 h-4 w-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center"
      >
        {{ notificationStore.unreadCount }}
      </span>
    </button>

    <!-- Dropdown Menu -->
    <div
      v-if="isOpen"
      class="absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-lg border border-gray-200 z-50"
    >
      <!-- Header -->
      <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-sm font-medium text-gray-900">Notifikasi</h3>
        <button
          v-if="notificationStore.hasUnreadNotifications"
          @click="markAllAsRead"
          class="text-sm text-primary-700 hover:text-primary-800"
        >
          Tandai Semua Dibaca
        </button>
      </div>

      <!-- Loading State -->
      <div v-if="isLoading" class="p-4 text-center">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 mx-auto"></div>
      </div>

      <!-- Empty State -->
      <div v-else-if="notificationStore.notifications.length === 0" class="p-8 text-center">
        <svg class="h-12 w-12 text-gray-400 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
        </svg>
        <p class="text-sm text-gray-500">Tidak ada notifikasi</p>
      </div>

      <!-- Notifications List -->
      <div v-else class="max-h-96 overflow-y-auto">
        <div
          v-for="notification in notificationStore.notifications"
          :key="notification.id"
          @click="handleNotificationClick(notification)"
          :class="[
            'p-4 border-b border-gray-100 cursor-pointer hover:bg-gray-50 transition-colors',
            { 'bg-primary-50': !notification.is_read }
          ]"
        >
          <div class="flex items-start">
            <!-- Notification Icon -->
            <div class="flex-shrink-0 mr-3">
              <div :class="[
                'h-10 w-10 rounded-full flex items-center justify-center',
                getNotificationIconClass(notification.type)
              ]">
                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" clip-rule="evenodd" />
                </svg>
              </div>
            </div>

            <!-- Notification Content -->
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between">
                <p class="text-sm font-medium text-gray-900">{{ notification.title }}</p>
                <button
                  @click.stop="deleteNotification(notification.id)"
                  class="ml-2 text-gray-400 hover:text-red-600"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <p class="text-sm text-gray-600 mt-1">{{ notification.message }}</p>
              <p class="text-xs text-gray-400 mt-2">{{ formatDate(notification.created_at) }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-4 py-2 border-t border-gray-200 bg-gray-50">
        <button
          @click="viewAllNotifications"
          class="w-full text-center text-sm text-primary-700 hover:text-primary-800"
        >
          Lihat Semua Notifikasi
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useNotificationStore } from '../../stores/notifications';
import { useRouter } from 'vue-router';

const router = useRouter();
const notificationStore = useNotificationStore();

const isOpen = ref(false);
const isLoading = ref(false);

const toggleDropdown = async () => {
  isOpen.value = !isOpen.value;

  if (isOpen.value) {
    isLoading.value = true;
    await notificationStore.fetchNotifications();
    isLoading.value = false;
  }
};

const handleNotificationClick = async (notification) => {
  await notificationStore.markAsRead(notification.id);

  if (notification.link) {
    router.push(notification.link);
  }

  isOpen.value = false;
};

const markAllAsRead = async () => {
  await notificationStore.markAllAsRead();
};

const deleteNotification = async (id) => {
  await notificationStore.deleteNotification(id);
};

const viewAllNotifications = () => {
  router.push('/notifications');
  isOpen.value = false;
};

const formatDate = (dateString) => {
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;

  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);

  if (minutes < 1) return 'Baru saja';
  if (minutes < 60) return `${minutes} menit yang lalu`;
  if (hours < 24) return `${hours} jam yang lalu`;
  if (days < 7) return `${days} hari yang lalu`;

  return date.toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  });
};

const getNotificationIconClass = (type) => {
  const classes = {
    'submission': 'bg-primary-100 text-primary-700',
    'approval': 'bg-green-100 text-green-600',
    'rejection': 'bg-red-100 text-red-600',
    'system': 'bg-gray-100 text-gray-600',
  };
  return classes[type] || 'bg-gray-100 text-gray-600';
};

// Close dropdown when clicking outside
const handleClickOutside = (event) => {
  if (!event.target.closest('.notifications-dropdown')) {
    isOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
