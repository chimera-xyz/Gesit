<template>
  <div class="space-y-6 pb-8">
    <section class="rounded-[30px] border border-[#e8dcc9] bg-white p-6 shadow-[0_20px_48px_rgba(41,28,9,0.07)] sm:p-8">
      <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
        <div class="max-w-3xl">
          <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">SiGesit Dashboard</p>
          <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.1rem]">
            Selamat datang, {{ firstName }}.
          </h1>
          <p class="mt-3 max-w-2xl text-sm leading-7 text-[#6b7280] sm:text-[0.96rem]">
            Pantau pengajuan internal dalam satu tampilan yang lebih ringkas.
          </p>

          <div class="mt-6 flex flex-wrap gap-3">
            <button
              v-if="primaryAction"
              type="button"
              @click="navigateToAction(primaryAction.to)"
              class="inline-flex h-11 items-center justify-center rounded-2xl bg-[#9b6b17] px-5 text-sm font-semibold text-white transition hover:bg-[#865b12]"
            >
              {{ primaryAction.title }}
            </button>

            <button
              v-if="secondaryAction"
              type="button"
              @click="navigateToAction(secondaryAction.to)"
              class="inline-flex h-11 items-center justify-center rounded-2xl border border-[#e2d3b8] bg-white px-5 text-sm font-medium text-[#7b5a24] transition hover:border-[#cfae72] hover:text-[#946815]"
            >
              {{ secondaryAction.title }}
            </button>

            <button
              v-if="canCreateHelpdesk"
              type="button"
              @click="handleHelpdeskDashboardAction"
              class="inline-flex h-11 items-center justify-center rounded-2xl border border-[#e2d3b8] bg-[#fffaf1] px-5 text-sm font-medium text-[#7b5a24] transition hover:border-[#cfae72] hover:bg-white hover:text-[#946815]"
            >
              {{ helpdeskDashboardLabel }}
            </button>
          </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3 xl:w-[23rem] xl:grid-cols-1">
          <div class="rounded-[24px] border border-[#eadfcf] bg-[#fffdf9] px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Role</p>
            <p class="mt-2 text-base font-semibold text-[#111827]">{{ userRole }}</p>
            <p class="mt-1 text-sm text-[#6b7280]">Hak akses mengikuti peran akun Anda saat ini.</p>
          </div>

          <div class="rounded-[24px] border border-[#eadfcf] bg-white px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Pengajuan Aktif</p>
            <p class="mt-2 text-2xl font-semibold text-[#111827]">{{ pendingCount }}</p>
            <p class="mt-1 text-sm text-[#6b7280]">Permintaan yang masih menunggu tindak lanjut.</p>
          </div>

          <div class="rounded-[24px] border border-[#eadfcf] bg-white px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Terselesaikan</p>
            <p class="mt-2 text-2xl font-semibold text-[#111827]">{{ completedCount }}</p>
            <p class="mt-1 text-sm text-[#6b7280]">Pengajuan yang sudah selesai diproses.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article
        v-for="stat in stats"
        :key="stat.id"
        class="rounded-[24px] border border-[#eadfcf] bg-white p-5 shadow-[0_14px_30px_rgba(41,28,9,0.05)]"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-medium text-[#6b7280]">{{ stat.title }}</p>
            <p class="mt-3 text-3xl font-semibold tracking-tight text-[#111827]">{{ stat.value }}</p>
            <p class="mt-2 text-xs uppercase tracking-[0.18em] text-[#9ca3af]">{{ stat.caption }}</p>
          </div>

          <div :class="['flex h-11 w-11 items-center justify-center rounded-2xl', stat.iconClass]">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                :d="stat.iconPath"
              />
            </svg>
          </div>
        </div>
      </article>
    </section>

    <section class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
      <article class="rounded-[28px] border border-[#e8dcc9] bg-white shadow-[0_16px_36px_rgba(41,28,9,0.06)]">
        <template v-if="canApprove">
          <div class="flex items-center justify-between border-b border-[#f0e6d7] px-6 py-5 sm:px-7">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Approval</p>
              <h2 class="mt-2 text-xl font-semibold text-[#111827]">Menunggu persetujuan</h2>
            </div>
            <router-link to="/submissions" class="text-sm font-medium text-[#8b6316] transition hover:text-[#704d10]">
              Lihat semua
            </router-link>
          </div>

          <div class="px-6 py-5">
            <div v-if="pendingApprovals.length === 0" class="rounded-[24px] border border-dashed border-[#e8dcc9] bg-white px-5 py-10 text-center">
              <h3 class="text-base font-semibold text-[#111827]">Tidak ada approval tertunda</h3>
              <p class="mt-2 text-sm leading-6 text-[#6b7280]">Semua pengajuan yang perlu Anda cek sudah diproses.</p>
            </div>

            <div v-else class="space-y-3">
              <button
                v-for="approval in pendingApprovals"
                :key="approval.id"
                type="button"
                @click="navigateToSubmission(approval.id)"
                class="flex w-full items-start justify-between gap-4 rounded-[22px] border border-[#eee3d4] px-4 py-4 text-left transition hover:border-[#d8bc84] hover:bg-[#fffdf9]"
              >
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-[#111827]">{{ approval.form?.name || 'Form tidak diketahui' }}</p>
                  <p class="mt-1 text-sm text-[#6b7280]">{{ approval.user?.name || 'Pengguna internal' }}</p>
                  <p class="mt-2 text-xs uppercase tracking-[0.18em] text-[#9ca3af]">
                    {{ formatStatus(approval.current_status) }}
                  </p>
                </div>

                <span class="mt-1 shrink-0 text-sm font-medium text-[#8b6316]">Review</span>
              </button>
            </div>
          </div>
        </template>

        <template v-else>
          <div class="flex items-center justify-between border-b border-[#f0e6d7] px-6 py-5 sm:px-7">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Aktivitas</p>
              <h2 class="mt-2 text-xl font-semibold text-[#111827]">Pengajuan terbaru</h2>
            </div>
            <router-link to="/submissions" class="text-sm font-medium text-[#8b6316] transition hover:text-[#704d10]">
              Lihat semua
            </router-link>
          </div>

          <div class="px-6 py-5 sm:px-7">
            <div v-if="recentSubmissions.length === 0" class="rounded-[24px] border border-dashed border-[#e8dcc9] bg-white px-6 py-12 text-center">
              <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[#faf5ec] text-[#a57e3a]">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m1 5H8a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414A1 1 0 0118 7.414V19a2 2 0 01-2 2Z" />
                </svg>
              </div>
              <h3 class="mt-4 text-base font-semibold text-[#111827]">Belum ada aktivitas terbaru</h3>
              <p class="mt-2 text-sm leading-6 text-[#6b7280]">Mulai dari membuat pengajuan baru atau buka daftar form yang tersedia.</p>
            </div>

            <div v-else class="space-y-3">
              <p v-if="recentSubmissions.length > 3" class="text-xs font-medium text-[#9ca3af]">
                Menampilkan 3 aktivitas teratas, scroll untuk melihat sisanya.
              </p>

              <div class="max-h-[22.5rem] space-y-3 overflow-y-auto pr-2">
                <button
                  v-for="submission in recentSubmissions"
                  :key="submission.id"
                  type="button"
                  @click="navigateToSubmission(submission.id)"
                  class="flex w-full items-start justify-between gap-4 rounded-[22px] border border-[#eee3d4] px-4 py-4 text-left transition hover:border-[#d8bc84] hover:bg-[#fffdf9]"
                >
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                      <span class="status-badge" :class="getStatusClass(submission.current_status)">
                        {{ formatStatus(submission.current_status) }}
                      </span>
                      <p class="text-xs font-medium uppercase tracking-[0.18em] text-[#9ca3af]">
                        {{ submission.user?.name || userName }}
                      </p>
                    </div>

                    <p class="mt-3 text-sm font-semibold text-[#111827]">
                      {{ submission.form?.name || 'Form tidak diketahui' }}
                    </p>
                    <p class="mt-1 text-sm text-[#6b7280]">
                      Diajukan {{ formatDate(submission.created_at) }}
                    </p>
                  </div>

                  <span class="mt-1 shrink-0 text-sm font-medium text-[#8b6316]">Detail</span>
                </button>
              </div>
            </div>
          </div>
        </template>
      </article>

      <div class="space-y-6">
        <article class="rounded-[28px] border border-[#e8dcc9] bg-white p-6 shadow-[0_16px_36px_rgba(41,28,9,0.06)]">
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Akses Cepat</p>
          <h2 class="mt-2 text-xl font-semibold text-[#111827]">Tindakan utama</h2>

          <div class="mt-5 space-y-3">
            <button
              v-for="action in quickActions"
              :key="action.id"
              type="button"
              @click="navigateToAction(action.to)"
              class="flex w-full items-start gap-4 rounded-[22px] border border-[#eee3d4] px-4 py-4 text-left transition hover:border-[#d8bc84] hover:bg-[#fffdf9]"
            >
              <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#fbf5ea] text-[#9b6b17]">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    v-if="action.icon === 'form'"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M9 12h6m-6 4h6m1 5H8a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414A1 1 0 0118 7.414V19a2 2 0 01-2 2Z"
                  />
                  <path
                    v-else-if="action.icon === 'list'"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"
                  />
                  <path
                    v-else-if="action.icon === 'approve'"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0Z"
                  />
                  <path
                    v-else-if="action.icon === 'settings'"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M10.325 4.317a1.724 1.724 0 013.35 0 1.724 1.724 0 002.573 1.066 1.724 1.724 0 012.875 1.925 1.724 1.724 0 001.066 2.573 1.724 1.724 0 010 3.35 1.724 1.724 0 00-1.066 2.573 1.724 1.724 0 01-2.875 1.925 1.724 1.724 0 00-2.573 1.066 1.724 1.724 0 01-3.35 0 1.724 1.724 0 00-2.573-1.066 1.724 1.724 0 01-2.875-1.925 1.724 1.724 0 00-1.066-2.573 1.724 1.724 0 010-3.35 1.724 1.724 0 001.066-2.573 1.724 1.724 0 012.875-1.925 1.724 1.724 0 002.573-1.066ZM12 15.25a3.25 3.25 0 100-6.5 3.25 3.25 0 000 6.5Z"
                  />
                  <path
                    v-else
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    d="M4 19h16M7 16V8m5 8V5m5 11v-6"
                  />
                </svg>
              </div>

              <div class="min-w-0">
                <p class="text-sm font-semibold text-[#111827]">{{ action.title }}</p>
                <p class="mt-1 text-sm leading-6 text-[#6b7280]">{{ action.description }}</p>
              </div>
            </button>
          </div>
        </article>

        <article
          v-if="canApprove"
          class="rounded-[28px] border border-[#e8dcc9] bg-white shadow-[0_16px_36px_rgba(41,28,9,0.06)]"
        >
          <div class="flex items-center justify-between border-b border-[#f0e6d7] px-6 py-5">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Aktivitas</p>
              <h2 class="mt-2 text-xl font-semibold text-[#111827]">Pengajuan terbaru</h2>
            </div>
            <router-link to="/submissions" class="text-sm font-medium text-[#8b6316] transition hover:text-[#704d10]">
              Lihat semua
            </router-link>
          </div>

          <div class="px-6 py-5">
            <div v-if="recentSubmissions.length === 0" class="rounded-[24px] border border-dashed border-[#e8dcc9] bg-white px-5 py-10 text-center">
              <h3 class="text-base font-semibold text-[#111827]">Belum ada aktivitas terbaru</h3>
              <p class="mt-2 text-sm leading-6 text-[#6b7280]">Belum ada pengajuan baru yang perlu Anda lihat.</p>
            </div>

            <div v-else class="space-y-3">
              <div class="max-h-[18rem] space-y-3 overflow-y-auto pr-2">
                <button
                  v-for="submission in recentSubmissions"
                  :key="submission.id"
                  type="button"
                  @click="navigateToSubmission(submission.id)"
                  class="flex w-full items-start justify-between gap-4 rounded-[22px] border border-[#eee3d4] px-4 py-4 text-left transition hover:border-[#d8bc84] hover:bg-[#fffdf9]"
                >
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                      <span class="status-badge" :class="getStatusClass(submission.current_status)">
                        {{ formatStatus(submission.current_status) }}
                      </span>
                      <p class="text-xs font-medium uppercase tracking-[0.18em] text-[#9ca3af]">
                        {{ submission.user?.name || userName }}
                      </p>
                    </div>

                    <p class="mt-3 text-sm font-semibold text-[#111827]">
                      {{ submission.form?.name || 'Form tidak diketahui' }}
                    </p>
                    <p class="mt-1 text-sm text-[#6b7280]">
                      Diajukan {{ formatDate(submission.created_at) }}
                    </p>
                  </div>

                  <span class="mt-1 shrink-0 text-sm font-medium text-[#8b6316]">Detail</span>
                </button>
              </div>
            </div>
          </div>
        </article>
      </div>
    </section>

    <QuickTicketModal
      :open="showHelpdeskModal"
      :filters="helpdeskFilters"
      :saving="helpdeskSaving"
      :errors="helpdeskErrors"
      :context-page="route.fullPath"
      @close="closeHelpdeskModal"
      @submit="submitHelpdeskTicket"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useSubmissionStore } from '../stores/submissions';
import { useHelpdeskStore } from '../stores/helpdesk';
import QuickTicketModal from './Helpdesk/QuickTicketModal.vue';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const submissionStore = useSubmissionStore();
const helpdeskStore = useHelpdeskStore();

const showHelpdeskModal = ref(false);
const helpdeskSaving = ref(false);
const helpdeskErrors = ref({});

const userName = computed(() => authStore.user?.name || 'User');

const firstName = computed(() => {
  return userName.value.split(' ').filter(Boolean)[0] || 'User';
});

const userRole = computed(() => authStore.roles[0] || 'Internal User');

const canApprove = computed(() => authStore.canApprove);
const canCreateHelpdesk = computed(() => authStore.hasPermission('create helpdesk tickets'));
const isItStaff = computed(() => authStore.hasRole('IT Staff'));
const helpdeskDashboardLabel = computed(() => isItStaff.value ? 'Panel IT' : 'Butuh Bantuan IT');
const helpdeskFilters = computed(() => helpdeskStore.filters);

const quickActions = computed(() => {
  if (authStore.isAdmin) {
    return [
      { id: 1, title: 'Buat form baru', description: 'Susun form internal baru untuk kebutuhan tim.', to: '/forms/builder', icon: 'form' },
      { id: 2, title: 'Kelola form', description: 'Lihat dan rapikan daftar form yang tersedia.', to: '/forms', icon: 'list' },
      { id: 3, title: 'Setting aplikasi', description: 'Kelola user, role, dan pengaturan akses dari satu tempat.', to: '/settings', icon: 'settings' },
    ];
  }

  if (authStore.canApprove) {
    return [
      { id: 1, title: 'Lihat approval saya', description: 'Buka daftar pengajuan yang perlu Anda review.', to: '/submissions', icon: 'approve' },
      { id: 2, title: 'Lihat form', description: 'Buka form yang tersedia untuk diajukan atau dicek.', to: '/forms', icon: 'list' },
      { id: 3, title: 'Buka profil', description: 'Periksa informasi akun internal Anda.', to: '/profile', icon: 'chart' },
    ];
  }

  return [
    { id: 1, title: 'Buat pengajuan', description: 'Ajukan kebutuhan internal baru melalui form yang tersedia.', to: '/forms', icon: 'form' },
    { id: 2, title: 'Pengajuan saya', description: 'Pantau status dan riwayat permintaan Anda.', to: '/submissions', icon: 'list' },
    { id: 3, title: 'Buka profil', description: 'Lihat informasi akun dan akses Anda.', to: '/profile', icon: 'chart' },
  ];
});

const primaryAction = computed(() => quickActions.value[0] || null);
const secondaryAction = computed(() => quickActions.value[1] || null);

const recentSubmissions = computed(() => submissionStore.submissions.slice(0, 8));
const pendingApprovals = computed(() => submissionStore.getActionableSubmissions.slice(0, 4));

const pendingCount = computed(() => submissionStore.getPendingSubmissions.length);
const completedCount = computed(() => submissionStore.getApprovedSubmissions.length);
const rejectedCount = computed(() => submissionStore.getRejectedSubmissions.length);
const totalCount = computed(() => submissionStore.submissions.length);

const stats = computed(() => [
  {
    id: 1,
    title: 'Total Pengajuan',
    value: totalCount.value,
    caption: 'semua permintaan',
    iconClass: 'bg-[#fbf5ea] text-[#9b6b17]',
    iconPath: 'M9 12h6m-6 4h6m1 5H8a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414A1 1 0 0118 7.414V19a2 2 0 01-2 2Z',
  },
  {
    id: 2,
    title: 'Butuh Tindak Lanjut',
    value: pendingCount.value,
    caption: 'masih berjalan',
    iconClass: 'bg-[#fff6e6] text-[#b7791f]',
    iconPath: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0Z',
  },
  {
    id: 3,
    title: 'Selesai',
    value: completedCount.value,
    caption: 'sudah diproses',
    iconClass: 'bg-[#effaf3] text-[#1f8f51]',
    iconPath: 'M5 13l4 4L19 7',
  },
  {
    id: 4,
    title: 'Ditolak',
    value: rejectedCount.value,
    caption: 'perlu evaluasi',
    iconClass: 'bg-[#fff3f3] text-[#c24141]',
    iconPath: 'M9.172 9.172 14.828 14.828M14.828 9.172l-5.656 5.656M21 12a9 9 0 11-18 0 9 9 0 0118 0Z',
  },
]);

const getStatusClass = (status) => {
  const classes = {
    submitted: 'status-submitted',
    pending_it: 'status-pending',
    pending_director: 'status-pending',
    pending_accounting: 'status-pending',
    pending_payment: 'status-pending',
    approved: 'status-approved',
    completed: 'status-completed',
    rejected: 'status-rejected',
  };

  return classes[status] || 'status-pending';
};

const formatStatus = (status) => {
  const labels = {
    submitted: 'Diajukan',
    pending_it: 'Review IT',
    pending_director: 'Approval Direktur',
    pending_accounting: 'Proses Accounting',
    pending_payment: 'Konfirmasi Pembayaran',
    approved: 'Disetujui',
    completed: 'Selesai',
    rejected: 'Ditolak',
  };

  return labels[status] || status;
};

const formatDate = (dateString) => {
  const date = new Date(dateString);
  const now = new Date();
  const diffTime = Math.abs(now - date);
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

  if (diffDays === 0) return 'hari ini';
  if (diffDays === 1) return 'kemarin';
  if (diffDays < 7) return `${diffDays} hari lalu`;

  return date.toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
};

const navigateToAction = (to) => {
  router.push(to);
};

const navigateToSubmission = (id) => {
  router.push(`/submissions/${id}`);
};

const normalizeErrors = (errors) => {
  return Object.fromEntries(
    Object.entries(errors || {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]),
  );
};

const openHelpdeskModal = async () => {
  helpdeskErrors.value = {};

  if (!helpdeskStore.filters.categories.length) {
    try {
      await helpdeskStore.fetchTickets();
    } catch (error) {
      helpdeskErrors.value = {
        general: error.response?.data?.error || 'Form bantuan IT belum bisa dimuat.',
      };
    }
  }

  showHelpdeskModal.value = true;
};

const handleHelpdeskDashboardAction = async () => {
  if (isItStaff.value) {
    router.push('/helpdesk');
    return;
  }

  await openHelpdeskModal();
};

const closeHelpdeskModal = () => {
  showHelpdeskModal.value = false;
  helpdeskErrors.value = {};
};

const submitHelpdeskTicket = async (payload) => {
  helpdeskSaving.value = true;
  helpdeskErrors.value = {};

  try {
    const response = await helpdeskStore.createTicket(payload);
    closeHelpdeskModal();

    if (response.ticket?.id) {
      router.push(`/helpdesk/${response.ticket.id}`);
    }
  } catch (error) {
    if (error.response?.status === 422 && error.response?.data?.errors) {
      helpdeskErrors.value = normalizeErrors(error.response.data.errors);
      return;
    }

    helpdeskErrors.value = {
      general: error.response?.data?.error || 'Ticket bantuan gagal dikirim ke IT.',
    };
  } finally {
    helpdeskSaving.value = false;
  }
};

const loadData = async () => {
  try {
    await submissionStore.fetchSubmissions();
  } catch (error) {
    console.error('Error loading dashboard data:', error);
  }
};

onMounted(() => {
  loadData();
});
</script>
