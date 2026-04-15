<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Pengajuan</h1>
      <p class="mt-2 text-gray-600">Cari dan pantau pengajuan yang sudah masuk ke sistem.</p>
    </div>

    <div class="card p-6">
      <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr_1fr_auto]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Cari Pengajuan</label>
          <input
            v-model="searchQuery"
            type="text"
            class="input-field"
            placeholder="Cari Pengajuan"
          >
        </div>
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Form</label>
          <select v-model="formFilter" class="select-field">
            <option value="">Semua form</option>
            <option v-for="form in availableForms" :key="form.id" :value="String(form.id)">
              {{ form.name }}
            </option>
          </select>
        </div>
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
          <select v-model="statusFilter" class="select-field">
            <option value="">Semua status</option>
            <option value="submitted">Dikirim</option>
            <option value="pending_it">Pending IT</option>
            <option value="pending_director">Pending Direktur</option>
            <option value="pending_accounting">Pending Accounting</option>
            <option value="pending_payment">Pending Konfirmasi Bayar</option>
            <option value="completed">Selesai</option>
            <option value="rejected">Ditolak</option>
          </select>
        </div>

        <div class="flex items-end">
          <button type="button" class="btn-secondary w-full" @click="resetFilters">Reset</button>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500">
        <p>
          {{ submissions.length }} pengajuan tampil dari {{ totalSubmissions }} total data.
        </p>
        <span v-if="isRefreshing" class="text-[#8f6115]">Memuat hasil terbaru...</span>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <h2 class="text-lg font-semibold text-red-800">Gagal memuat submission</h2>
      <p class="mt-2 text-sm text-red-700">{{ error }}</p>
      <button @click="loadSubmissions()" class="btn-primary mt-4">Coba Lagi</button>
    </div>

    <div v-else-if="submissions.length === 0" class="card p-6 text-center">
      <h2 class="text-lg font-semibold text-gray-900">{{ hasActiveFilters ? 'Tidak ada pengajuan yang cocok' : 'Belum ada submission' }}</h2>
      <p class="mt-2 text-sm text-gray-500">
        {{ hasActiveFilters ? 'Ubah filter atau kata kunci pencarian untuk melihat hasil lain.' : 'Ubah filter atau buat request baru.' }}
      </p>
      <button v-if="hasActiveFilters" type="button" class="btn-secondary mt-4" @click="resetFilters">Reset Filter</button>
    </div>

    <div v-else class="grid gap-4">
      <article
        v-for="item in submissions"
        :key="item.id"
        class="card cursor-pointer p-6 transition-shadow duration-200 hover:shadow-xl"
        @click="openSubmission(item.id)"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ item.form?.name || 'Form tidak diketahui' }}</h2>
            <p class="mt-1 text-sm text-gray-500">
              ID #{{ item.id }} • Dikirim {{ formatDate(item.created_at) }} oleh {{ item.user?.name || 'pengguna' }}
            </p>
          </div>
          <span class="status-badge" :class="getStatusClass(item.current_status)">
            {{ formatStatus(item.current_status) }}
          </span>
        </div>
      </article>
    </div>

    <div v-if="!loading && totalSubmissions > 0" class="card p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500">
          Halaman {{ pagination.current_page }} dari {{ pagination.last_page }}
        </p>

        <div class="flex gap-3">
          <button
            type="button"
            class="btn-secondary"
            :disabled="pagination.current_page <= 1 || isRefreshing"
            @click="goToPage(pagination.current_page - 1)"
          >
            Sebelumnya
          </button>
          <button
            type="button"
            class="btn-secondary"
            :disabled="pagination.current_page >= pagination.last_page || isRefreshing"
            @click="goToPage(pagination.current_page + 1)"
          >
            Berikutnya
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';
import { useSubmissionStore } from '../../stores/submissions';

const router = useRouter();
const formStore = useFormStore();
const submissionStore = useSubmissionStore();

const loading = ref(false);
const isRefreshing = ref(false);
const error = ref(null);
const searchQuery = ref('');
const statusFilter = ref('');
const formFilter = ref('');
const filterDebounce = ref(null);

const submissions = computed(() => submissionStore.submissions);
const availableForms = computed(() => formStore.forms);
const pagination = computed(() => submissionStore.pagination);
const totalSubmissions = computed(() => pagination.value.total || submissions.value.length);
const hasActiveFilters = computed(() => Boolean(
  searchQuery.value.trim()
  || statusFilter.value
  || formFilter.value,
));

const buildParams = (page = 1) => {
  const params = {};

  if (searchQuery.value.trim()) {
    params.search = searchQuery.value.trim();
  }

  if (statusFilter.value) {
    params.status = statusFilter.value;
  }

  if (formFilter.value) {
    params.form_id = formFilter.value;
  }

  if (page > 1) {
    params.page = page;
  }

  return params;
};

const loadSubmissions = async (page = 1) => {
  const isInitialLoad = submissions.value.length === 0 && !loading.value;

  if (isInitialLoad) {
    loading.value = true;
  } else {
    isRefreshing.value = true;
  }

  error.value = null;

  try {
    await submissionStore.fetchSubmissions(buildParams(page));
  } catch (err) {
    console.error('Error loading submissions:', err);
    error.value = err.response?.data?.error || 'Submission tidak dapat dimuat.';
  } finally {
    loading.value = false;
    isRefreshing.value = false;
  }
};

const loadInitialData = async () => {
  loading.value = true;
  error.value = null;

  try {
    await Promise.all([
      submissionStore.fetchSubmissions(buildParams()),
      formStore.fetchForms(),
    ]);
  } catch (err) {
    console.error('Error loading submissions:', err);
    error.value = err.response?.data?.error || 'Submission tidak dapat dimuat.';
  } finally {
    loading.value = false;
  }
};

const scheduleLoadSubmissions = () => {
  if (filterDebounce.value) {
    window.clearTimeout(filterDebounce.value);
  }

  filterDebounce.value = window.setTimeout(() => {
    loadSubmissions(1);
  }, 300);
};

const resetFilters = () => {
  searchQuery.value = '';
  statusFilter.value = '';
  formFilter.value = '';
};

const goToPage = (page) => {
  if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) {
    return;
  }

  loadSubmissions(page);
};

const openSubmission = (id) => {
  router.push(`/submissions/${id}`);
};

const formatDate = (value) => {
  if (!value) {
    return '-';
  }

  return new Date(value).toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

const formatStatus = (status) => {
  const labels = {
    submitted: 'Dikirim',
    pending_it: 'Pending IT',
    pending_director: 'Pending Direktur',
    pending_accounting: 'Proses Accounting',
    pending_payment: 'Konfirmasi Bayar',
    completed: 'Selesai',
    rejected: 'Ditolak',
  };

  return labels[status] || humanizeToken(status);
};

const getStatusClass = (status) => {
  const map = {
    submitted: 'status-submitted',
    pending_it: 'status-pending',
    pending_director: 'status-pending',
    pending_accounting: 'status-pending',
    pending_payment: 'status-pending',
    completed: 'status-completed',
    rejected: 'status-rejected',
  };

  return map[status] || 'status-pending';
};

const humanizeToken = (value) => {
  if (!value) {
    return '-';
  }

  return String(value)
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/\b\w/g, (character) => character.toUpperCase());
};

watch([searchQuery, statusFilter, formFilter], () => {
  scheduleLoadSubmissions();
});

onMounted(() => {
  loadInitialData();
});

onUnmounted(() => {
  if (filterDebounce.value) {
    window.clearTimeout(filterDebounce.value);
  }
});
</script>
