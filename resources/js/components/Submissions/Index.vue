<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Submissions</h1>
      <p class="mt-2 text-gray-600">Pantau request yang sudah masuk ke sistem.</p>
    </div>

    <div class="card p-6">
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
          <select v-model="statusFilter" class="select-field">
            <option value="">Semua status</option>
            <option value="submitted">Dikirim</option>
            <option value="pending_it">Pending IT</option>
            <option value="pending_director">Pending Direktur</option>
            <option value="pending_accounting">Pending Accounting</option>
            <option value="completed">Selesai</option>
            <option value="rejected">Ditolak</option>
          </select>
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
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <h2 class="text-lg font-semibold text-red-800">Gagal memuat submission</h2>
      <p class="mt-2 text-sm text-red-700">{{ error }}</p>
      <button @click="loadSubmissions" class="btn-primary mt-4">Coba Lagi</button>
    </div>

    <div v-else-if="filteredSubmissions.length === 0" class="card p-6 text-center">
      <h2 class="text-lg font-semibold text-gray-900">Belum ada submission</h2>
      <p class="mt-2 text-sm text-gray-500">Ubah filter atau buat request baru.</p>
    </div>

    <div v-else class="grid gap-4">
      <article
        v-for="item in filteredSubmissions"
        :key="item.id"
        class="card cursor-pointer p-6 transition-shadow duration-200 hover:shadow-xl"
        @click="openSubmission(item.id)"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ item.form?.name || 'Form tidak diketahui' }}</h2>
            <p class="mt-1 text-sm text-gray-500">
              Dikirim {{ formatDate(item.created_at) }} oleh {{ item.user?.name || 'pengguna' }}
            </p>
          </div>
          <span class="status-badge" :class="getStatusClass(item.current_status)">
            {{ formatStatus(item.current_status) }}
          </span>
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';
import { useSubmissionStore } from '../../stores/submissions';

const router = useRouter();
const formStore = useFormStore();
const submissionStore = useSubmissionStore();

const loading = ref(false);
const error = ref(null);
const statusFilter = ref('');
const formFilter = ref('');
const submissions = ref([]);
const availableForms = ref([]);

const filteredSubmissions = computed(() =>
  submissions.value.filter((submission) => {
    const statusMatch = !statusFilter.value || submission.current_status === statusFilter.value;
    const formMatch = !formFilter.value || String(submission.form_id) === formFilter.value;
    return statusMatch && formMatch;
  })
);

const loadSubmissions = async () => {
  loading.value = true;
  error.value = null;

  try {
    await Promise.all([
      submissionStore.fetchSubmissions(),
      formStore.fetchForms(),
    ]);

    submissions.value = submissionStore.submissions;
    availableForms.value = formStore.forms;
  } catch (err) {
    console.error('Error loading submissions:', err);
    error.value = err.response?.data?.error || 'Submission tidak dapat dimuat.';
  } finally {
    loading.value = false;
  }
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
    pending_accounting: 'Pending Accounting',
    completed: 'Selesai',
    rejected: 'Ditolak',
  };

  return labels[status] || status;
};

const getStatusClass = (status) => {
  const map = {
    submitted: 'status-submitted',
    pending_it: 'status-pending',
    pending_director: 'status-pending',
    pending_accounting: 'status-pending',
    completed: 'status-completed',
    rejected: 'status-rejected',
  };

  return map[status] || 'status-pending';
};

onMounted(() => {
  loadSubmissions();
});
</script>
