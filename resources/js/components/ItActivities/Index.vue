<template>
  <div class="space-y-6 pb-8">
    <section class="rounded-[30px] border border-[#e8dcc9] bg-white p-6 shadow-[0_20px_48px_rgba(41,28,9,0.07)] sm:p-8">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
          <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">IT Activity Center</p>
          <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.1rem]">
            Rekap aktivitas IT lintas modul
          </h1>
          <p v-if="filterSummary" class="mt-4 inline-flex rounded-full bg-[#fbf5ea] px-4 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-[#8f6115]">
            {{ filterSummary }}
          </p>
        </div>

        <div class="flex flex-wrap gap-3 lg:justify-end">
          <button type="button" class="btn-secondary" :disabled="loading" @click="resetFilters">
            Reset Filter
          </button>
          <button type="button" class="btn-primary" :disabled="loading || exporting" @click="exportActivities">
            {{ exporting ? 'Menyiapkan Export...' : 'Export Excel' }}
          </button>
        </div>
      </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <article
        v-for="card in statCards"
        :key="card.id"
        class="rounded-[24px] border border-[#eadfcf] bg-white p-5 shadow-[0_14px_30px_rgba(41,28,9,0.05)]"
      >
        <p class="text-sm font-medium text-[#6b7280]">{{ card.title }}</p>
        <p class="mt-3 text-3xl font-semibold tracking-tight text-[#111827]">{{ card.value }}</p>
        <p class="mt-2 text-xs uppercase tracking-[0.18em] text-[#9ca3af]">{{ card.caption }}</p>
      </article>
    </section>

    <section class="card p-5">
      <div class="grid gap-4 lg:grid-cols-[1.4fr_0.8fr_0.8fr_0.8fr_auto]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Cari Aktivitas</label>
          <input
            v-model="filters.search"
            type="text"
            class="input-field"
            placeholder="Cari aktivitas, user, referensi, objek, atau catatan"
            @keyup.enter="applyFilters"
          >
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Modul</label>
          <select v-model="filters.module" class="select-field">
            <option v-for="option in moduleOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Tanggal Awal</label>
          <input v-model="filters.date_from" type="date" class="input-field">
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Tanggal Akhir</label>
          <input v-model="filters.date_to" type="date" class="input-field">
        </div>

        <div class="flex items-end">
          <button type="button" class="btn-secondary w-full" :disabled="loading" @click="applyFilters">
            Terapkan
          </button>
        </div>
      </div>
    </section>

    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#efe5d7] px-6 py-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">Timeline Aktivitas IT</h2>
          <p class="mt-1 text-sm text-gray-500">{{ pagination.total }} aktivitas cocok dengan filter saat ini.</p>
        </div>
        <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
          {{ pagination.current_page }} / {{ pagination.last_page }} halaman
        </span>
      </div>

      <div v-if="loading" class="flex items-center justify-center py-16">
        <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-[#9b6b17]"></div>
      </div>

      <div v-else-if="error" class="px-6 py-8">
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
          <h3 class="text-base font-semibold text-red-900">Gagal memuat aktivitas IT</h3>
          <p class="mt-2 text-sm text-red-700">{{ error }}</p>
          <button type="button" class="btn-primary mt-4" @click="loadPage">Coba Lagi</button>
        </div>
      </div>

      <div v-else-if="activities.length === 0" class="px-6 py-10">
        <div class="rounded-[24px] border border-dashed border-[#d8c7aa] px-5 py-12 text-center">
          <h3 class="text-base font-semibold text-[#111827]">Belum ada aktivitas yang tampil</h3>
          <p class="mt-2 text-sm leading-6 text-[#6b7280]">Ubah filter periode atau kata kunci untuk melihat aktivitas IT lain.</p>
        </div>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-[#efe5d7]">
          <thead class="bg-[#fffaf1]">
            <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-[#8f6115]">
              <th class="px-6 py-4">Waktu</th>
              <th class="px-6 py-4">Aktivitas</th>
              <th class="px-6 py-4">Referensi</th>
              <th class="px-6 py-4">Aktor</th>
              <th class="px-6 py-4">Pihak Terkait</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4">Ringkasan</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[#f3ecdf] bg-white">
            <tr v-for="activity in activities" :key="activity.id" class="align-top">
              <td class="px-6 py-5 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ formatDate(activity.occurred_at) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ activity.module_label }}</p>
              </td>
              <td class="px-6 py-5">
                <div class="min-w-[16rem]">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-[#8f6115]">
                      {{ activity.module_label }}
                    </span>
                    <span class="rounded-full bg-[#f6f7fb] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-[#52607a]">
                      {{ activity.visibility_label }}
                    </span>
                  </div>
                  <p class="mt-3 text-sm font-semibold text-gray-900">{{ activity.activity_name }}</p>
                  <p class="mt-1 text-sm text-gray-600">{{ activity.item_title }}</p>
                  <p class="mt-2 text-xs text-gray-500">{{ activity.context_label }}</p>
                </div>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ activity.reference_number }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ activity.requester_name }}</p>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ activity.actor_name }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ activity.actor_role }}</p>
                <p class="mt-2 text-xs text-[#8b6316]">PIC IT: {{ activity.it_owner }}</p>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p>{{ activity.related_users }}</p>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p class="font-medium text-gray-900">{{ activity.status_at_event_label || '-' }}</p>
                <p class="mt-1 text-xs text-gray-500">Terkini: {{ activity.current_status_label || '-' }}</p>
              </td>
              <td class="px-6 py-5 text-sm text-gray-600">
                <p class="leading-6 text-gray-700">{{ activity.summary }}</p>
                <p v-if="activity.notes" class="mt-2 rounded-2xl bg-[#fbf7ef] px-3 py-2 text-xs leading-5 text-[#6b5c36]">
                  {{ activity.notes }}
                </p>
              </td>
              <td class="px-6 py-5 text-right">
                <button type="button" class="btn-secondary whitespace-nowrap" @click="openDetail(activity.detail_url)">
                  Buka Detail
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#efe5d7] px-6 py-4">
        <p class="text-sm text-gray-500">
          Menampilkan {{ firstVisibleItem }} - {{ lastVisibleItem }} dari {{ pagination.total }} aktivitas.
        </p>

        <div class="flex flex-wrap gap-3">
          <button
            type="button"
            class="btn-secondary"
            :disabled="loading || pagination.current_page <= 1"
            @click="changePage(pagination.current_page - 1)"
          >
            Sebelumnya
          </button>
          <button
            type="button"
            class="btn-secondary"
            :disabled="loading || pagination.current_page >= pagination.last_page"
            @click="changePage(pagination.current_page + 1)"
          >
            Berikutnya
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useItActivityStore } from '../../stores/itActivities';

const router = useRouter();
const itActivityStore = useItActivityStore();

const loading = ref(false);
const exporting = ref(false);
const error = ref(null);
const filters = ref({
  search: '',
  module: 'all',
  date_from: '',
  date_to: '',
});

const activities = computed(() => itActivityStore.activities);
const pagination = computed(() => itActivityStore.pagination);
const filterSummary = computed(() => itActivityStore.filterSummary);
const moduleOptions = computed(() => {
  const options = itActivityStore.filters.modules || [];

  return options.length > 0
    ? options
    : [
      { value: 'all', label: 'Semua Modul' },
      { value: 'helpdesk', label: 'Helpdesk' },
      { value: 'submission', label: 'Pengajuan' },
    ];
});

const statCards = computed(() => [
  {
    id: 1,
    title: 'Total Aktivitas',
    value: itActivityStore.stats.total,
    caption: 'event tampil',
  },
  {
    id: 2,
    title: 'Aktivitas Helpdesk',
    value: itActivityStore.stats.helpdesk,
    caption: 'timeline bantuan IT',
  },
  {
    id: 3,
    title: 'Aktivitas Pengajuan',
    value: itActivityStore.stats.submission,
    caption: 'alur workflow terkait IT',
  },
  {
    id: 4,
    title: 'Catatan Internal',
    value: itActivityStore.stats.internal,
    caption: 'khusus visibilitas IT',
  },
]);

const firstVisibleItem = computed(() => {
  if (pagination.value.total === 0) {
    return 0;
  }

  return ((pagination.value.current_page - 1) * pagination.value.per_page) + 1;
});

const lastVisibleItem = computed(() => {
  if (pagination.value.total === 0) {
    return 0;
  }

  return Math.min(
    pagination.value.current_page * pagination.value.per_page,
    pagination.value.total,
  );
});

const currentParams = (page = 1) => ({
  search: filters.value.search,
  module: filters.value.module,
  date_from: filters.value.date_from,
  date_to: filters.value.date_to,
  page,
  per_page: pagination.value.per_page || 25,
});

const loadPage = async (page = 1) => {
  loading.value = true;
  error.value = null;

  try {
    await itActivityStore.fetchActivities(currentParams(page));
  } catch (requestError) {
    error.value = requestError.response?.data?.error || 'Aktivitas IT gagal dimuat.';
  } finally {
    loading.value = false;
  }
};

const applyFilters = async () => {
  await loadPage(1);
};

const resetFilters = async () => {
  filters.value = {
    search: '',
    module: 'all',
    date_from: '',
    date_to: '',
  };

  await loadPage(1);
};

const changePage = async (page) => {
  await loadPage(page);
};

const openDetail = (detailUrl) => {
  if (!detailUrl) {
    return;
  }

  router.push(detailUrl);
};

const exportActivities = () => {
  exporting.value = true;

  try {
    window.location.assign(itActivityStore.exportUrl({
      search: filters.value.search,
      module: filters.value.module,
      date_from: filters.value.date_from,
      date_to: filters.value.date_to,
    }));
  } finally {
    window.setTimeout(() => {
      exporting.value = false;
    }, 900);
  }
};

const formatDate = (value) => {
  if (!value) {
    return '-';
  }

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
};

onMounted(async () => {
  await loadPage(1);
});
</script>
