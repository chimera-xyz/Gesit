<template>
  <div id="app" class="min-h-screen bg-white text-secondary-900">
    <nav v-if="showShell" class="sticky top-0 z-40 border-b border-[#efe5d7] bg-white/95 backdrop-blur-xl">
      <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-4">
          <router-link :to="homeLink" class="flex min-w-0 items-center gap-3">
            <img :src="companyLogo" alt="PT Yulie Sekuritas Indonesia Tbk." class="h-11 w-auto sm:h-12">
          </router-link>

          <div class="hidden items-center gap-2 lg:flex">
            <router-link
              v-for="item in navigation"
              :key="item.name"
              :to="item.to"
              :class="[
                'rounded-full px-4 py-2 text-sm font-medium transition-all duration-200',
                isCurrentRoute(item)
                  ? 'bg-[#fbf5ea] text-[#8f6115] shadow-sm'
                  : 'text-secondary-600 hover:bg-[#fbf5ea] hover:text-[#8f6115]',
              ]"
            >
              {{ item.label }}
            </router-link>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <button
            @click="toggleNotifications"
            class="relative inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-[#efe5d7] bg-white text-secondary-600 shadow-sm transition-all duration-200 hover:border-[#d8bc84] hover:text-[#8f6115] focus:outline-none focus:ring-4 focus:ring-[#f3e4bf]"
            type="button"
          >
            <span class="sr-only">Buka notifikasi</span>
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V4.75a2 2 0 10-4 0v.591C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h11Zm0 0a3 3 0 11-6 0h6Z" />
            </svg>
            <span
              v-if="unreadCount > 0"
              class="absolute -right-1.5 -top-1.5 inline-flex min-h-6 min-w-6 items-center justify-center rounded-full bg-[#9b6b17] px-1.5 text-[11px] font-semibold text-white shadow-lg"
            >
              {{ displayedUnreadCount }}
            </span>
          </button>

          <div class="user-menu relative">
            <button
              id="user-menu-button"
              @click="toggleUserMenu"
              class="flex items-center gap-3 rounded-2xl border border-[#efe5d7] bg-white px-3 py-2 text-left shadow-sm transition-all duration-200 hover:border-[#d8bc84] focus:outline-none focus:ring-4 focus:ring-[#f3e4bf]"
              type="button"
            >
              <div class="h-10 w-10 overflow-hidden rounded-2xl bg-[#9b6b17] text-sm font-semibold text-white shadow-lg shadow-[#ead39a]/70">
                <img v-if="userProfilePhotoUrl" :src="userProfilePhotoUrl" alt="" class="h-full w-full object-cover">
                <div v-else class="flex h-full w-full items-center justify-center">
                  {{ userInitials }}
                </div>
              </div>
              <div class="hidden sm:block">
                <p class="max-w-40 truncate text-sm font-semibold text-secondary-900">{{ authStore.user?.name || 'User' }}</p>
                <p class="text-xs text-secondary-500">{{ authStore.roles[0] || 'Internal User' }}</p>
              </div>
            </button>

            <div
              v-if="showUserMenu"
              class="absolute right-0 z-20 mt-3 w-56 overflow-hidden rounded-3xl border border-[#efe5d7] bg-white shadow-2xl"
              role="menu"
              aria-orientation="vertical"
              aria-labelledby="user-menu-button"
            >
              <div class="border-b border-[#efe5d7] px-5 py-4">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Akun</p>
                <p class="mt-2 truncate text-sm font-semibold text-secondary-900">{{ authStore.user?.email }}</p>
              </div>

              <div class="p-2">
                <router-link
                  to="/profile"
                  @click="showUserMenu = false"
                  class="block rounded-2xl px-4 py-3 text-sm text-secondary-700 transition-colors duration-200 hover:bg-[#fbf5ea] hover:text-[#8f6115]"
                  role="menuitem"
                >
                  Profil Saya
                </router-link>
                <router-link
                  v-if="authStore.isAdmin"
                  to="/settings"
                  @click="showUserMenu = false"
                  class="block rounded-2xl px-4 py-3 text-sm text-secondary-700 transition-colors duration-200 hover:bg-[#fbf5ea] hover:text-[#8f6115]"
                  role="menuitem"
                >
                  Setting
                </router-link>
                <button
                  @click="logout"
                  class="block w-full rounded-2xl px-4 py-3 text-left text-sm text-danger-700 transition-colors duration-200 hover:bg-danger-50"
                  role="menuitem"
                  type="button"
                >
                  Keluar
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </nav>

    <div
      v-if="showShell && showNotifications"
      class="fixed inset-0 z-50"
      aria-labelledby="slide-over-title"
      role="dialog"
      aria-modal="true"
    >
      <div class="absolute inset-0 bg-secondary-900/20 backdrop-blur-sm" @click="closeNotifications"></div>

      <div class="absolute inset-y-0 right-0 flex max-w-full pl-4 sm:pl-8">
        <div class="brand-surface m-3 flex h-[calc(100%-1.5rem)] w-screen max-w-md flex-col overflow-hidden sm:m-4 sm:h-[calc(100%-2rem)]">
          <div class="border-b border-primary-100 px-6 py-5">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-primary-700">Pusat Aktivitas</p>
                <h2 id="slide-over-title" class="mt-2 text-2xl font-semibold text-secondary-900">Notifikasi</h2>
                <p class="mt-2 text-sm leading-6 text-secondary-600">Pantau approval, perubahan status.</p>
              </div>

              <button
                @click="closeNotifications"
                class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-primary-100 bg-white text-secondary-500 transition-colors duration-200 hover:text-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-100"
                type="button"
              >
                <span class="sr-only">Tutup panel</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div class="mt-5 flex items-center justify-between gap-3">
              <span class="inline-flex rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700">
                {{ unreadSummary }}
              </span>
              <button
                v-if="unreadCount > 0"
                @click="markAllNotificationsAsRead"
                class="brand-link text-sm"
                type="button"
              >
                Tandai semua dibaca
              </button>
            </div>
          </div>

          <div class="flex-1 overflow-y-auto px-6 py-5">
            <div v-if="notificationLoading" class="flex h-full flex-col items-center justify-center text-center">
              <div class="h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
              <p class="mt-4 text-sm font-medium text-secondary-700">Memuat notifikasi...</p>
            </div>

            <div v-else-if="notificationStore.notifications.length === 0" class="flex h-full flex-col items-center justify-center text-center">
              <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-primary-50 text-primary-700">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 8h10M7 12h6m-9 8h16a2 2 0 002-2V8a2 2 0 00-2-2h-3.586a1 1 0 01-.707-.293l-1.414-1.414A1 1 0 0013.586 4h-3.172a1 1 0 00-.707.293L8.293 5.707A1 1 0 017.586 6H4a2 2 0 00-2 2v10a2 2 0 002 2Z" />
                </svg>
              </div>
              <h3 class="mt-5 text-lg font-semibold text-secondary-900">Belum ada notifikasi</h3>
              <p class="mt-2 max-w-sm text-sm leading-6 text-secondary-600">Saat ada pengajuan baru, approval, atau perubahan status, informasinya akan muncul di sini.</p>
            </div>

            <div v-else class="space-y-3">
              <button
                v-for="notification in notificationStore.notifications"
                :key="notification.id"
                @click="handleNotificationClick(notification)"
                :class="[
                  'w-full rounded-[1.4rem] border p-4 text-left transition-all duration-200',
                  notification.is_read
                    ? 'border-secondary-200 bg-white hover:border-primary-200 hover:bg-primary-50/30'
                    : 'border-primary-200 bg-primary-50/70 shadow-sm',
                ]"
                type="button"
              >
                <div class="flex items-start gap-4">
                  <div
                    :class="[
                      'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl',
                      getNotificationMeta(notification).chipClass,
                    ]"
                  >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8"
                        :d="getNotificationMeta(notification).iconPath"
                      />
                    </svg>
                  </div>

                  <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <p class="text-sm font-semibold text-secondary-900">{{ notification.title }}</p>
                        <p class="mt-1 text-xs font-medium uppercase tracking-[0.2em] text-secondary-400">{{ getNotificationMeta(notification).label }}</p>
                      </div>
                      <span v-if="!notification.is_read" class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-primary-600"></span>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-secondary-600">{{ notification.message }}</p>
                    <p class="mt-3 text-xs font-medium text-secondary-400">{{ formatDate(notification.created_at) }}</p>
                  </div>
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div
      v-if="showShell"
      class="pointer-events-none fixed right-4 top-24 z-[60] w-[min(24rem,calc(100%-2rem))]"
    >
      <transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-3 opacity-0 scale-95"
        enter-to-class="translate-y-0 opacity-100 scale-100"
        leave-active-class="transition duration-300 ease-in"
        leave-from-class="translate-y-0 opacity-100 scale-100"
        leave-to-class="translate-y-2 opacity-0 scale-95"
      >
        <article
          v-if="activeToast"
          :key="activeToast.id"
          class="pointer-events-auto relative overflow-hidden rounded-[28px] border border-[#e8dcc9] bg-white shadow-[0_20px_48px_rgba(41,28,9,0.14)]"
        >
          <div class="absolute inset-x-0 top-0 h-1 overflow-hidden bg-[#f3e4bf]">
            <div
              :key="`toast-progress-${activeToast.id}`"
              class="toast-progress-bar h-full w-full bg-gradient-to-r from-[#d4b06a] via-[#9b6b17] to-[#7e5715]"
              :style="{ animationDuration: `${toastDurationMs}ms` }"
            ></div>
          </div>

          <button
            type="button"
            @click="handleToastClick(activeToast)"
            class="block w-full px-5 pb-5 pt-6 text-left transition hover:bg-[#fffdf9]"
          >
            <div class="flex items-start gap-4 pr-9">
              <div
                :class="[
                  'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl',
                  getNotificationMeta(activeToast).chipClass,
                ]"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    :d="getNotificationMeta(activeToast).iconPath"
                  />
                </svg>
              </div>

              <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="text-sm font-semibold text-secondary-900">{{ activeToast.title }}</p>
                    <p class="mt-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">
                      {{ getNotificationMeta(activeToast).label }}
                    </p>
                  </div>
                  <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-primary-600"></span>
                </div>

                <p class="mt-3 text-sm leading-6 text-secondary-600">{{ activeToast.message }}</p>
                <p class="mt-3 text-xs font-medium text-secondary-400">{{ formatDate(activeToast.created_at) }}</p>
              </div>
            </div>
          </button>

          <button
            type="button"
            @click.stop="dismissActiveToast"
            class="absolute right-3 top-3 inline-flex h-9 w-9 items-center justify-center rounded-2xl text-secondary-400 transition hover:bg-[#fbf5ea] hover:text-[#8f6115]"
          >
            <span class="sr-only">Tutup notifikasi</span>
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </article>
      </transition>
    </div>

    <main :class="showShell ? mainShellClass : ''">
      <div :class="showShell ? mainContentClass : ''">
        <router-view></router-view>
      </div>
    </main>

    <footer v-if="showFooter" class="mt-12 border-t border-[#efe5d7]">
      <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div class="flex items-center gap-4">
            <img :src="companyLogo" alt="PT Yulie Sekuritas Indonesia Tbk." class="h-11 w-auto sm:h-12">
            <div>
              <p class="text-sm font-semibold text-[#111827]">SiGesit</p>
              <p class="text-sm text-secondary-600">Portal pengajuan internal PT Yulie Sekuritas Indonesia Tbk.</p>
            </div>
          </div>

          <div class="text-sm leading-6 text-secondary-600 lg:text-right">
            <p>© 2026 PT Yulie Sekuritas Indonesia Tbk.</p>
          </div>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { TOAST_DURATION_MS, useNotificationStore } from '../stores/notifications';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const notificationStore = useNotificationStore();
const companyLogo = '/logoyulie.png';

const showNotifications = ref(false);
const showUserMenu = ref(false);
const notificationLoading = ref(false);
const toastDurationMs = TOAST_DURATION_MS;

const showShell = computed(() => authStore.isAuthenticated && !route.meta.guestOnly);
const isKnowledgeWorkspace = computed(() => route.path.startsWith('/knowledge-hub'));
const mainShellClass = computed(() => (
  isKnowledgeWorkspace.value
    ? 'h-[calc(100dvh-5.5rem)] overflow-hidden pb-0 pt-5 sm:pt-6'
    : 'pb-4 pt-8 sm:pt-10'
));
const mainContentClass = computed(() => (
  isKnowledgeWorkspace.value
    ? 'h-full w-full px-4 sm:px-6 lg:px-8'
    : 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8'
));
const showFooter = computed(() => showShell.value && !isKnowledgeWorkspace.value);
const activeToast = computed(() => notificationStore.activeToast);
const homeLink = computed(() => (authStore.hasAppAccess('gesit') ? '/' : '/portal'));

const navigation = computed(() => {
  const items = [];

  if (authStore.launcherVisible) {
    items.push({ name: 'portal', label: 'Portal', to: '/portal' });
  }

  if (!authStore.hasAppAccess('gesit')) {
    return items;
  }

  items.push({ name: 'dashboard', label: 'Beranda', to: '/' });

  if (authStore.hasPermission('view forms')) {
    items.push({ name: 'forms', label: 'Form', to: '/forms' });
  }

  if (authStore.hasPermission('view submissions')) {
    items.push({ name: 'submissions', label: 'Pengajuan Saya', to: '/submissions' });
  }

  if (authStore.hasPermission('view helpdesk tickets')) {
    items.push({
      name: 'helpdesk',
      label: authStore.hasRole('IT Staff') ? 'Panel IT' : 'Bantuan IT',
      to: '/helpdesk',
    });
  }

  if (authStore.hasPermission('view knowledge hub')) {
    items.push({
      name: 'knowledge-hub',
      label: 'Knowledge Hub',
      to: '/knowledge-hub',
    });
  }

  if (authStore.hasPermission('view it activities')) {
    items.push({
      name: 'it-activities',
      label: 'Aktivitas IT',
      to: '/it-activities',
    });
  }

  return items;
});

const userInitials = computed(() => {
  const name = authStore.user?.name || 'User';

  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
});

const userProfilePhotoUrl = computed(() => authStore.user?.profile_photo_url || '');

const unreadCount = computed(() => notificationStore.unreadCount);

const displayedUnreadCount = computed(() => {
  if (unreadCount.value > 9) {
    return '9+';
  }

  return unreadCount.value;
});

const unreadSummary = computed(() => {
  if (unreadCount.value === 0) {
    return 'Semua notifikasi sudah dibaca';
  }

  return `${unreadCount.value} notifikasi belum dibaca`;
});

const isCurrentRoute = (item) => {
  if (item.to === '/') {
    return route.path === '/';
  }

  return route.path.startsWith(item.to);
};

const getNotificationMeta = (notificationOrType) => {
  const type = typeof notificationOrType === 'string'
    ? notificationOrType
    : notificationOrType?.type;
  const link = typeof notificationOrType === 'object'
    ? notificationOrType?.link
    : null;

  const config = {
    form_submitted: {
      label: 'Pengajuan Baru',
      chipClass: 'bg-primary-100 text-primary-700',
      iconPath: 'M9 12h6m-6 4h6m1 5H8a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414A1 1 0 0118 7.414V19a2 2 0 01-2 2Z',
    },
    submission: {
      label: 'Pengajuan Baru',
      chipClass: 'bg-primary-100 text-primary-700',
      iconPath: 'M9 12h6m-6 4h6m1 5H8a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414A1 1 0 0118 7.414V19a2 2 0 01-2 2Z',
    },
    approval_needed: {
      label: 'Butuh Persetujuan',
      chipClass: 'bg-amber-100 text-amber-700',
      iconPath: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0Z',
    },
    approval: {
      label: 'Butuh Persetujuan',
      chipClass: 'bg-amber-100 text-amber-700',
      iconPath: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0Z',
    },
    status_changed: {
      label: 'Status Berubah',
      chipClass: 'bg-emerald-100 text-emerald-700',
      iconPath: 'M7 7h10m0 0-3-3m3 3-3 3M17 17H7m0 0 3-3m-3 3 3 3',
    },
    signature_required: {
      label: 'Tanda Tangan',
      chipClass: 'bg-secondary-100 text-secondary-700',
      iconPath: 'M16.862 4.487a2.1 2.1 0 113.033 2.906L9.82 17.91 6 18l.09-3.82 10.772-9.693Z',
    },
    rejection: {
      label: 'Ditolak',
      chipClass: 'bg-red-100 text-red-700',
      iconPath: 'M9.172 9.172 14.828 14.828M14.828 9.172l-5.656 5.656M21 12a9 9 0 11-18 0 9 9 0 0118 0Z',
    },
    helpdesk: {
      label: 'Helpdesk',
      chipClass: 'bg-[#eef4ff] text-[#315ea8]',
      iconPath: 'M18 10c0 3.866-3.134 7-7 7a6.98 6.98 0 0 1-4.285-1.464L3 16l.464-3.715A6.98 6.98 0 0 1 2 8c0-3.866 3.134-7 7-7s7 3.134 7 7Zm-7-2v2m0 4h.008V14H11v-.008Z',
    },
    general: {
      label: 'Umum',
      chipClass: 'bg-secondary-100 text-secondary-700',
      iconPath: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0Z',
    },
    system: {
      label: 'Sistem',
      chipClass: 'bg-secondary-100 text-secondary-700',
      iconPath: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0Z',
    },
  };

  if ((type === 'general' || !type) && typeof link === 'string' && link.startsWith('/helpdesk/')) {
    return config.helpdesk;
  }

  return config[type] || config.general;
};

const closeNotifications = () => {
  showNotifications.value = false;
};

const toggleNotifications = async () => {
  if (showNotifications.value) {
    closeNotifications();
    return;
  }

  showUserMenu.value = false;
  showNotifications.value = true;
  notificationLoading.value = true;

  try {
    await notificationStore.fetchNotifications({ perPage: 20 });
  } finally {
    notificationLoading.value = false;
  }
};

const markAllNotificationsAsRead = async () => {
  await notificationStore.markAllAsRead();
};

const toggleUserMenu = () => {
  showUserMenu.value = !showUserMenu.value;
};

const formatDate = (dateString) => {
  const date = new Date(dateString);

  return date.toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const handleNotificationClick = async (notification) => {
  if (!notification.is_read) {
    await notificationStore.markAsRead(notification.id);
  }

  if (notification.link) {
    router.push(notification.link);
  }

  closeNotifications();
};

const dismissActiveToast = () => {
  notificationStore.dismissActiveToast();
};

const handleToastClick = async (notification) => {
  if (!notification) {
    return;
  }

  try {
    if (!notification.is_read) {
      await notificationStore.markAsRead(notification.id);
    } else {
      notificationStore.removeNotificationFromToastState(notification.id);
    }
  } finally {
    notificationStore.dismissActiveToast();
  }

  if (notification.link) {
    router.push(notification.link);
  }
};

const logout = async () => {
  await authStore.logout();
  showUserMenu.value = false;
  closeNotifications();
  router.replace('/login');
};

const handleDocumentClick = (event) => {
  if (!event.target.closest('.user-menu')) {
    showUserMenu.value = false;
  }
};

const syncNotifications = async (options = {}) => {
  if (!authStore.isAuthenticated) {
    return;
  }

  try {
    await notificationStore.syncLatest(options);
  } catch (error) {
    console.error('Error syncing notifications:', error);
  }
};

const bootstrapNotifications = async (userId) => {
  try {
    await notificationStore.bootstrap(userId);
  } catch (error) {
    console.error('Error bootstrapping notifications:', error);
  }
};

const handleVisibilityChange = () => {
  if (!document.hidden) {
    syncNotifications({ enqueueToasts: true }).catch(() => {});
  }
};

const handleWindowFocus = () => {
  syncNotifications({ enqueueToasts: true }).catch(() => {});
};

watch(
  () => authStore.user?.id ?? null,
  async (userId, previousUserId) => {
    if (!userId) {
      notificationStore.resetForUserChange(previousUserId, {
        clearSurfaced: Boolean(previousUserId),
      });
      return;
    }

    if (userId !== previousUserId) {
      await bootstrapNotifications(userId);
      return;
    }

    await syncNotifications({ enqueueToasts: true });
  },
  { immediate: true }
);

onMounted(() => {
  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('visibilitychange', handleVisibilityChange);
  window.addEventListener('focus', handleWindowFocus);
});

onUnmounted(() => {
  document.removeEventListener('click', handleDocumentClick);
  document.removeEventListener('visibilitychange', handleVisibilityChange);
  window.removeEventListener('focus', handleWindowFocus);
  notificationStore.stopPolling();
});
</script>

<style>
.toast-progress-bar {
  transform-origin: left center;
  animation-name: toast-progress-shrink;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}

@keyframes toast-progress-shrink {
  from {
    transform: scaleX(1);
    opacity: 1;
  }

  to {
    transform: scaleX(0);
    opacity: 0.78;
  }
}
</style>
