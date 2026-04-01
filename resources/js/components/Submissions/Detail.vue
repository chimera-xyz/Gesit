<template>
  <div class="space-y-6">
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <h2 class="text-lg font-semibold text-red-800">Gagal memuat submission</h2>
      <p class="mt-2 text-sm text-red-700">{{ error }}</p>
      <button @click="loadSubmission" class="btn-primary mt-4">Coba Lagi</button>
    </div>

    <template v-else-if="submission">
      <div class="flex items-center justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-gray-900">Detail Submission</h1>
          <p class="mt-2 text-gray-600">{{ submission.form?.name || 'Form tidak diketahui' }}</p>
        </div>
        <button @click="router.back()" class="btn-secondary">Kembali</button>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <div class="card p-6 lg:col-span-1">
          <h2 class="text-lg font-semibold text-gray-900">Ringkasan</h2>
          <dl class="mt-4 space-y-4 text-sm">
            <div>
              <dt class="text-gray-500">ID</dt>
              <dd class="font-medium text-gray-900">#{{ submission.id }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Pemohon</dt>
              <dd class="font-medium text-gray-900">{{ submission.user?.name || '-' }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Status</dt>
              <dd>
                <span class="status-badge" :class="getStatusClass(submission.current_status)">
                  {{ formatStatus(submission.current_status) }}
                </span>
              </dd>
            </div>
            <div>
              <dt class="text-gray-500">Dibuat</dt>
              <dd class="font-medium text-gray-900">{{ formatDate(submission.created_at) }}</dd>
            </div>
          </dl>
        </div>

        <div class="card p-6 lg:col-span-2">
          <h2 class="text-lg font-semibold text-gray-900">Data Form</h2>
          <div v-if="formEntries.length > 0" class="mt-4 grid gap-4 md:grid-cols-2">
            <div v-for="[key, value] in formEntries" :key="key" class="rounded-lg border border-gray-200 p-4">
              <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ prettifyKey(key) }}</p>
              <p class="mt-2 text-sm text-gray-900">{{ formatValue(value) }}</p>
            </div>
          </div>
          <p v-else class="mt-4 text-sm text-gray-500">Belum ada data form yang tersimpan.</p>
        </div>
      </div>

      <div class="card p-6">
        <h2 class="text-lg font-semibold text-gray-900">Timeline Approval</h2>
        <div v-if="approvalSteps.length > 0" class="mt-4 space-y-4">
          <div
            v-for="step in approvalSteps"
            :key="step.id"
            class="rounded-lg border border-gray-200 p-4"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-gray-900">
                  Langkah {{ step.step_number }}: {{ step.step_name }}
                </p>
                <p class="mt-1 text-xs text-gray-500">Role approver: {{ step.approver_role || '-' }}</p>
              </div>
              <span class="status-badge" :class="getStepStatusClass(step.status)">
                {{ formatStepStatus(step.status) }}
              </span>
            </div>
            <p v-if="step.notes" class="mt-3 text-sm text-gray-700">{{ step.notes }}</p>
            <p v-if="step.approved_at" class="mt-2 text-xs text-gray-500">
              Diproses {{ formatDate(step.approved_at) }}
            </p>
          </div>
        </div>
        <p v-else class="mt-4 text-sm text-gray-500">Belum ada langkah approval.</p>
      </div>

      <div v-if="canApprove" class="card p-6">
        <h2 class="text-lg font-semibold text-gray-900">Approval</h2>
        <div class="mt-4 grid gap-6 lg:grid-cols-2">
          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Catatan Approval</label>
            <textarea
              v-model="approvalNotes"
              class="input-field"
              rows="4"
              placeholder="Tambahkan catatan approval jika diperlukan"
            ></textarea>
            <button
              @click="approveSubmission"
              :disabled="isApproving"
              class="btn-primary mt-4"
            >
              {{ isApproving ? 'Memproses...' : 'Setujui' }}
            </button>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Alasan Penolakan</label>
            <textarea
              v-model="rejectionReason"
              class="input-field"
              rows="4"
              placeholder="Wajib diisi jika submission ditolak"
            ></textarea>
            <button
              @click="rejectSubmission"
              :disabled="isRejecting"
              class="btn-danger mt-4"
            >
              {{ isRejecting ? 'Memproses...' : 'Tolak' }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="submission.current_status === 'rejected' && submission.rejection_reason"
        class="card border border-red-200 bg-red-50 p-6"
      >
        <h2 class="text-lg font-semibold text-red-900">Alasan Penolakan</h2>
        <p class="mt-2 text-sm text-red-700">{{ submission.rejection_reason }}</p>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSubmissionStore } from '../../stores/submissions';
import { useAuthStore } from '../../stores/auth';

const route = useRoute();
const router = useRouter();
const submissionStore = useSubmissionStore();
const authStore = useAuthStore();

const loading = ref(false);
const error = ref(null);
const submission = ref(null);
const approvalNotes = ref('');
const rejectionReason = ref('');
const isApproving = ref(false);
const isRejecting = ref(false);

const formEntries = computed(() => Object.entries(submission.value?.form_data || {}));

const approvalSteps = computed(() =>
  [...(submission.value?.approval_steps || [])].sort((a, b) => a.step_number - b.step_number)
);

const canApprove = computed(() => {
  if (!submission.value) {
    return false;
  }

  return authStore.canApprove && !['completed', 'rejected'].includes(submission.value.current_status);
});

const loadSubmission = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await submissionStore.fetchSubmission(route.params.id);
    submission.value = response.submission;
  } catch (err) {
    console.error('Error loading submission:', err);
    error.value = err.response?.data?.error || 'Submission tidak dapat dimuat.';
  } finally {
    loading.value = false;
  }
};

const approveSubmission = async () => {
  isApproving.value = true;

  try {
    await submissionStore.approveSubmission(route.params.id, {
      notes: approvalNotes.value,
    });
    await loadSubmission();
    approvalNotes.value = '';
  } catch (err) {
    console.error('Error approving submission:', err);
    error.value = err.response?.data?.error || 'Approval gagal diproses.';
  } finally {
    isApproving.value = false;
  }
};

const rejectSubmission = async () => {
  if (!rejectionReason.value.trim()) {
    error.value = 'Alasan penolakan wajib diisi.';
    return;
  }

  isRejecting.value = true;

  try {
    await submissionStore.rejectSubmission(route.params.id, {
      rejection_reason: rejectionReason.value,
    });
    await loadSubmission();
  } catch (err) {
    console.error('Error rejecting submission:', err);
    error.value = err.response?.data?.error || 'Penolakan gagal diproses.';
  } finally {
    isRejecting.value = false;
  }
};

const prettifyKey = (key) =>
  key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

const formatValue = (value) => {
  if (Array.isArray(value)) {
    return value.join(', ');
  }

  if (value && typeof value === 'object') {
    return JSON.stringify(value);
  }

  return value ?? '-';
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

const formatStatus = (status) => {
  const labels = {
    submitted: 'Dikirim',
    pending_it: 'Menunggu IT',
    pending_director: 'Menunggu Direktur',
    pending_accounting: 'Menunggu Accounting',
    completed: 'Selesai',
    rejected: 'Ditolak',
  };

  return labels[status] || status;
};

const formatStepStatus = (status) => {
  const labels = {
    pending: 'Menunggu',
    approved: 'Disetujui',
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

const getStepStatusClass = (status) => {
  const map = {
    pending: 'status-pending',
    approved: 'status-approved',
    rejected: 'status-rejected',
  };

  return map[status] || 'status-pending';
};

onMounted(() => {
  loadSubmission();
});
</script>
