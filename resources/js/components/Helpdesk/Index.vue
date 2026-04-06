<template>
  <div class="space-y-6 pb-8">
    <section class="rounded-[30px] border border-[#e8dcc9] bg-white p-6 shadow-[0_20px_48px_rgba(41,28,9,0.07)] sm:p-8">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
          <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">{{ heroEyebrow }}</p>
          <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.1rem]">
            {{ heroTitle }}
          </h1>
          <p class="mt-3 max-w-2xl text-sm leading-7 text-[#6b7280] sm:text-[0.96rem]">
            {{ heroDescription }}
          </p>
        </div>

        <div class="flex flex-wrap gap-3 lg:justify-end">
          <button
            v-if="canManage"
            type="button"
            class="btn-secondary"
            @click="openQuickModal('call')"
          >
            Log Panggilan
          </button>
          <button
            type="button"
            class="btn-primary"
            @click="openQuickModal('request')"
          >
            {{ canManage ? 'Buat Ticket Baru' : 'Butuh Bantuan IT' }}
          </button>
        </div>
      </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article
        v-for="stat in statCards"
        :key="stat.id"
        class="rounded-[24px] border border-[#eadfcf] bg-white p-5 shadow-[0_14px_30px_rgba(41,28,9,0.05)]"
      >
        <p class="text-sm font-medium text-[#6b7280]">{{ stat.title }}</p>
        <p class="mt-3 text-3xl font-semibold tracking-tight text-[#111827]">{{ stat.value }}</p>
        <p class="mt-2 text-xs uppercase tracking-[0.18em] text-[#9ca3af]">{{ stat.caption }}</p>
      </article>
    </section>

    <section class="card p-5">
      <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr_1fr_1fr_auto]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Cari Ticket</label>
          <input
            v-model="filters.search"
            type="text"
            class="input-field"
            placeholder="Cari nomor ticket, judul, deskripsi, atau requester"
          >
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
          <select v-model="filters.status" class="select-field">
            <option value="">Semua status</option>
            <option v-for="option in filterOptions.statuses" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Kategori</label>
          <select v-model="filters.category" class="select-field">
            <option value="">Semua kategori</option>
            <option v-for="option in filterOptions.categories" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Prioritas</label>
          <select v-model="filters.priority" class="select-field">
            <option value="">Semua prioritas</option>
            <option v-for="option in filterOptions.priorities" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div v-if="canManage">
          <label class="mb-2 block text-sm font-medium text-gray-700">Assignment</label>
          <select v-model="filters.assignment" class="select-field">
            <option value="">Semua ticket</option>
            <option value="assigned_to_me">Assigned to me</option>
            <option value="unassigned">Belum di-assign</option>
          </select>
        </div>

        <div class="flex items-end">
          <button type="button" class="btn-secondary w-full" @click="resetFilters">Reset</button>
        </div>
      </div>
    </section>

    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#efe5d7] px-6 py-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">{{ canManage ? 'Antrian Ticket' : 'Ticket Bantuan Saya' }}</h2>
          <p class="mt-1 text-sm text-gray-500">{{ tickets.length }} ticket tampil pada antrian saat ini.</p>
        </div>
        <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
          {{ canManage ? 'IT Queue' : 'Requester View' }}
        </span>
      </div>

      <div v-if="loading" class="flex items-center justify-center py-16">
        <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-[#9b6b17]"></div>
      </div>

      <div v-else-if="error" class="px-6 py-8">
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
          <h3 class="text-base font-semibold text-red-900">Gagal memuat ticket helpdesk</h3>
          <p class="mt-2 text-sm text-red-700">{{ error }}</p>
          <button type="button" class="btn-primary mt-4" @click="loadTickets">Coba Lagi</button>
        </div>
      </div>

      <div v-else-if="tickets.length === 0" class="px-6 py-14 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[#faf5ec] text-[#a57e3a]">
          <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM10.33 3.674 1.82 18A2 2 0 0 0 3.538 21h16.924a2 2 0 0 0 1.718-3l-8.512-14.326a2 2 0 0 0-3.436 0Z" />
          </svg>
        </div>
        <h3 class="mt-4 text-lg font-semibold text-gray-900">Belum ada ticket yang cocok</h3>
        <p class="mt-2 text-sm text-gray-500">
          {{ canManage ? 'Belum ada ticket bantuan pada filter ini.' : 'Anda belum punya laporan bantuan pada filter ini.' }}
        </p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-[#efe5d7]">
          <thead class="bg-[#fffaf1]">
            <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-[#8f6115]">
              <th class="px-6 py-4">Ticket</th>
              <th class="px-6 py-4">Kategori</th>
              <th class="px-6 py-4">Prioritas</th>
              <th v-if="canManage" class="px-6 py-4">Requester</th>
              <th class="px-6 py-4">Assignee</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4">Aktivitas Terakhir</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[#f3ecdf] bg-white">
            <tr v-for="ticket in tickets" :key="ticket.id" class="align-top">
              <td class="px-6 py-5">
                <div class="min-w-[18rem]">
                  <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">{{ ticket.ticket_number }}</p>
                  <p class="mt-2 text-sm font-semibold text-gray-900">{{ ticket.subject }}</p>
                  <p class="mt-2 line-clamp-2 text-sm leading-6 text-gray-600">{{ ticket.description }}</p>
                  <p class="mt-2 text-xs text-gray-500">Masuk via {{ ticket.channel_label }}</p>
                </div>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                {{ ticket.category_label }}
              </td>
              <td class="px-6 py-5">
                <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="getPriorityClass(ticket.priority)">
                  {{ ticket.priority_label }}
                </span>
              </td>
              <td v-if="canManage" class="px-6 py-5 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ ticket.requester?.name || '-' }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ ticket.requester?.department || ticket.requester?.email || '-' }}</p>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ ticket.assignee?.name || 'Belum diambil' }}</p>
                <p class="mt-1 text-xs text-gray-500">
                  {{ ticket.is_assigned_to_me ? 'Ticket Anda' : (ticket.assignee?.id ? 'Assigned' : 'Open queue') }}
                </p>
              </td>
              <td class="px-6 py-5">
                <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="getStatusClass(ticket.status)">
                  {{ ticket.status_label }}
                </span>
              </td>
              <td class="px-6 py-5 text-sm text-gray-600">
                <p>{{ formatDate(ticket.last_activity_at || ticket.updated_at) }}</p>
                <p class="mt-1 text-xs text-gray-500">Dibuat {{ formatDate(ticket.created_at) }}</p>
              </td>
              <td class="px-6 py-5 text-right">
                <button type="button" class="btn-secondary whitespace-nowrap" @click="openDetail(ticket.id)">
                  Buka Ticket
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <QuickTicketModal
      :open="showModal"
      :manager-mode="canManage"
      :mode="modalMode"
      :filters="filterOptions"
      :requesters="requesters"
      :assignees="assignees"
      :saving="saving"
      :errors="formErrors"
      :context-page="route.fullPath"
      @close="closeModal"
      @submit="submitTicket"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpdeskStore } from '../../stores/helpdesk';
import QuickTicketModal from './QuickTicketModal.vue';

const router = useRouter();
const route = useRoute();
const helpdeskStore = useHelpdeskStore();

const loading = ref(false);
const error = ref(null);
const saving = ref(false);
const showModal = ref(false);
const modalMode = ref('request');
const formErrors = ref({});
const filterDebounce = ref(null);

const filters = ref({
  search: '',
  status: '',
  category: '',
  priority: '',
  assignment: '',
});

const tickets = computed(() => helpdeskStore.tickets);
const filterOptions = computed(() => helpdeskStore.filters);
const requesters = computed(() => helpdeskStore.requesters);
const assignees = computed(() => helpdeskStore.assignees);
const canManage = computed(() => helpdeskStore.canManage);

const heroEyebrow = computed(() => canManage.value ? 'IT Helpdesk Center' : 'Bantuan IT');
const heroTitle = computed(() => canManage.value ? 'Antrian bantuan internal' : 'Laporkan kendala ke tim IT');
const heroDescription = computed(() => canManage.value
  ? 'Pantau ticket yang baru masuk.'
  : 'Kalau ada kendala perangkat, akses akun, internet, atau aplikasi, request dari sini. Progres penanganannya bisa Anda pantau tanpa perlu follow up manual.'
);

const statCards = computed(() => [
  {
    id: 1,
    title: 'Total Ticket',
    value: helpdeskStore.stats.all,
    caption: 'seluruh antrian',
  },
  {
    id: 2,
    title: 'Open',
    value: helpdeskStore.stats.open,
    caption: 'baru masuk',
  },
  {
    id: 3,
    title: 'In Progress',
    value: helpdeskStore.stats.in_progress,
    caption: 'sedang dikerjakan',
  },
  {
    id: 4,
    title: canManage.value ? 'Waiting User' : 'Resolved',
    value: canManage.value ? helpdeskStore.stats.waiting_user : helpdeskStore.stats.resolved,
    caption: canManage.value ? 'menunggu respons user' : 'menunggu konfirmasi',
  },
]);

const buildParams = () => {
  const params = {};

  Object.entries(filters.value).forEach(([key, value]) => {
    if (value) {
      params[key] = value;
    }
  });

  return params;
};

const loadTickets = async () => {
  loading.value = true;
  error.value = null;

  try {
    await helpdeskStore.fetchTickets(buildParams());
  } catch (err) {
    error.value = err.response?.data?.error || 'Terjadi kesalahan saat memuat ticket helpdesk.';
  } finally {
    loading.value = false;
  }
};

const scheduleLoadTickets = () => {
  if (filterDebounce.value) {
    window.clearTimeout(filterDebounce.value);
  }

  filterDebounce.value = window.setTimeout(() => {
    loadTickets();
  }, 250);
};

watch(
  filters,
  () => {
    scheduleLoadTickets();
  },
  { deep: true }
);

const resetFilters = () => {
  filters.value = {
    search: '',
    status: '',
    category: '',
    priority: '',
    assignment: '',
  };
};

const openQuickModal = (mode = 'request') => {
  modalMode.value = mode;
  formErrors.value = {};
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  formErrors.value = {};

  if (route.query.compose || route.query.log_call) {
    router.replace({ query: { ...route.query, compose: undefined, log_call: undefined } });
  }
};

const submitTicket = async (payload) => {
  saving.value = true;
  formErrors.value = {};

  try {
    const response = await helpdeskStore.createTicket(payload);
    await loadTickets();
    closeModal();

    if (response.ticket?.id) {
      router.push(`/helpdesk/${response.ticket.id}`);
    }
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      formErrors.value = normalizeErrors(err.response.data.errors);
      return;
    }

    formErrors.value = {
      general: err.response?.data?.error || 'Ticket helpdesk gagal dibuat.',
    };
  } finally {
    saving.value = false;
  }
};

const normalizeErrors = (errors) => {
  return Object.fromEntries(
    Object.entries(errors || {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]),
  );
};

const getStatusClass = (status) => {
  const classes = {
    open: 'bg-[#fff4dd] text-[#8f6115]',
    in_progress: 'bg-[#eef4ff] text-[#315ea8]',
    waiting_user: 'bg-[#f3ecff] text-[#6b46c1]',
    resolved: 'bg-[#edf8f1] text-[#1f8f51]',
    closed: 'bg-[#f3f4f6] text-[#4b5563]',
  };

  return classes[status] || 'bg-[#f3f4f6] text-[#4b5563]';
};

const getPriorityClass = (priority) => {
  const classes = {
    low: 'bg-[#f3f4f6] text-[#4b5563]',
    normal: 'bg-[#fff4dd] text-[#8f6115]',
    high: 'bg-[#fff1e8] text-[#c05621]',
    critical: 'bg-[#fff3f3] text-[#c24141]',
  };

  return classes[priority] || 'bg-[#f3f4f6] text-[#4b5563]';
};

const formatDate = (value) => {
  if (!value) {
    return '-';
  }

  return new Date(value).toLocaleString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const openDetail = (id) => {
  router.push(`/helpdesk/${id}`);
};

onMounted(async () => {
  await loadTickets();

  if (route.query.compose) {
    openQuickModal('request');
  }

  if (route.query.log_call && canManage.value) {
    openQuickModal('call');
  }
});

onUnmounted(() => {
  if (filterDebounce.value) {
    window.clearTimeout(filterDebounce.value);
  }
});
</script>
