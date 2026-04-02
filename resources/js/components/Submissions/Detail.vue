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
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Detail Workflow</p>
          <h1 class="mt-2 text-3xl font-bold text-gray-900">{{ submission.form?.name || 'Submission' }}</h1>
          <p class="mt-2 text-gray-600">Pantau isi pengajuan, preview PDF, dan proses approval dari halaman yang sama.</p>
        </div>
        <button @click="router.back()" class="btn-secondary">Kembali</button>
      </div>

      <div class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
        <div class="space-y-6">
          <div class="card p-6">
            <h2 class="text-lg font-semibold text-gray-900">Ringkasan</h2>
            <dl class="mt-4 space-y-4 text-sm">
              <div>
                <dt class="text-gray-500">ID Submission</dt>
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
                <dt class="text-gray-500">Langkah Aktif</dt>
                <dd class="font-medium text-gray-900">{{ submission.current_pending_step?.step_name || 'Tidak ada langkah aktif' }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Role Aktif</dt>
                <dd class="font-medium text-gray-900">{{ submission.current_pending_step?.approver_role || 'System' }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Dibuat</dt>
                <dd class="font-medium text-gray-900">{{ formatDate(submission.created_at) }}</dd>
              </div>
            </dl>
          </div>

          <div class="card p-6">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">Isi Form</h2>
                <p class="mt-1 text-sm text-gray-500">
                  <span v-if="canEditForm">Anda sedang berada di tahap review IT, jadi field di bawah bisa direvisi sebelum approval.</span>
                  <span v-else>Field tampil read-only sesuai data pengajuan terakhir.</span>
                </p>
              </div>
              <span v-if="canEditForm" class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">Mode revisi IT</span>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
              <div
                v-for="field in formFields"
                :key="field.id"
                :class="field.type === 'textarea' || field.type === 'checkbox' || field.type === 'radio' ? 'md:col-span-2' : ''"
                class="rounded-2xl border border-gray-200 p-4"
              >
                <label class="mb-2 block text-sm font-medium text-gray-700">{{ field.label }}</label>

                <template v-if="canEditForm">
                  <input
                    v-if="['text', 'email', 'number', 'date'].includes(field.type)"
                    v-model="editableFormData[field.id]"
                    :type="field.type"
                    class="input-field"
                  >

                  <textarea
                    v-else-if="field.type === 'textarea'"
                    v-model="editableFormData[field.id]"
                    class="input-field"
                    rows="4"
                  ></textarea>

                  <select
                    v-else-if="field.type === 'select'"
                    v-model="editableFormData[field.id]"
                    class="select-field"
                  >
                    <option value="">Pilih salah satu</option>
                    <option v-for="option in normalizeOptions(field.options)" :key="option" :value="option">
                      {{ option }}
                    </option>
                  </select>

                  <div v-else-if="field.type === 'radio'" class="grid gap-3 sm:grid-cols-2">
                    <label
                      v-for="option in normalizeOptions(field.options)"
                      :key="option"
                      class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3"
                    >
                      <input v-model="editableFormData[field.id]" :value="option" type="radio">
                      <span class="text-sm text-gray-700">{{ option }}</span>
                    </label>
                  </div>

                  <div v-else-if="field.type === 'checkbox'" class="grid gap-3 sm:grid-cols-2">
                    <label
                      v-for="option in normalizeOptions(field.options)"
                      :key="option"
                      class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3"
                    >
                      <input v-model="editableFormData[field.id]" :value="option" type="checkbox">
                      <span class="text-sm text-gray-700">{{ option }}</span>
                    </label>
                  </div>

                  <p v-else class="text-sm text-gray-500">Field tipe {{ field.type }} belum mendukung revisi langsung.</p>
                </template>

                <template v-else>
                  <p class="text-sm leading-6 text-gray-900">{{ formatValue(displayFormValue(field)) }}</p>
                </template>
              </div>
            </div>
          </div>

          <div class="card p-6">
            <h2 class="text-lg font-semibold text-gray-900">Timeline Approval</h2>
            <div v-if="approvalSteps.length > 0" class="mt-5 space-y-4">
              <div
                v-for="step in approvalSteps"
                :key="step.id"
                class="rounded-2xl border border-gray-200 p-4"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p class="text-sm font-semibold text-gray-900">Langkah {{ step.step_number }}: {{ step.step_name }}</p>
                    <p class="mt-1 text-xs uppercase tracking-[0.18em] text-gray-500">{{ step.approver_role || 'System' }}</p>
                  </div>
                  <span class="status-badge" :class="getStepStatusClass(step.status)">
                    {{ formatStepStatus(step.status) }}
                  </span>
                </div>
                <div class="mt-3 space-y-1 text-sm text-gray-700">
                  <p v-if="step.approver?.name"><span class="font-medium text-gray-900">Diproses oleh:</span> {{ step.approver.name }}</p>
                  <p v-if="step.notes"><span class="font-medium text-gray-900">Catatan:</span> {{ step.notes }}</p>
                  <p v-if="step.approved_at" class="text-xs text-gray-500">Diproses {{ formatDate(step.approved_at) }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-6">
          <div class="card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">Preview PDF Requisition</h2>
                <p class="mt-1 text-sm text-gray-500">PDF akan ter-regenerate otomatis setelah langkah penting diproses.</p>
              </div>
              <a v-if="pdfDownloadUrl" :href="pdfDownloadUrl" class="btn-secondary">Unduh PDF</a>
            </div>

            <div v-if="pdfPreviewUrl" class="mt-5 overflow-hidden rounded-[1.5rem] border border-gray-200">
              <iframe :src="pdfPreviewUrl" class="h-[820px] w-full bg-white"></iframe>
            </div>
            <div v-else class="mt-5 rounded-[1.5rem] border border-dashed border-gray-300 px-6 py-10 text-center text-sm text-gray-500">
              Preview PDF belum tersedia.
            </div>
          </div>

          <div v-if="activeAction" class="card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Action Required</p>
                <h2 class="mt-2 text-lg font-semibold text-gray-900">{{ activeAction.step_name }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                  <span v-if="activeAction.requires_signature">Langkah ini membutuhkan tanda tangan digital sebelum approval selesai.</span>
                  <span v-else>Langkah ini bisa diproses langsung tanpa tanda tangan tambahan.</span>
                </p>
              </div>
              <span class="status-badge status-pending">{{ formatStatus(submission.current_status) }}</span>
            </div>

            <div class="mt-5 space-y-4">
              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Catatan</label>
                <textarea
                  v-model="approvalNotes"
                  class="input-field"
                  rows="5"
                  :placeholder="activeAction.notes_placeholder"
                ></textarea>
              </div>

              <div class="flex flex-wrap justify-between gap-3">
                <button
                  v-if="activeAction.can_reject"
                  type="button"
                  class="btn-danger"
                  :disabled="isRejecting"
                  @click="rejectSubmission"
                >
                  {{ isRejecting ? 'Memproses...' : 'Tolak Pengajuan' }}
                </button>

                <button
                  type="button"
                  class="btn-primary ml-auto"
                  :disabled="isApproving"
                  @click="handleApproveClick"
                >
                  {{ isApproving ? 'Memproses...' : activeAction.label }}
                </button>
              </div>
            </div>
          </div>

          <div v-if="submission.current_status === 'rejected' && submission.rejection_reason" class="card border border-red-200 bg-red-50 p-6">
            <h2 class="text-lg font-semibold text-red-900">Alasan Penolakan</h2>
            <p class="mt-2 text-sm text-red-700">{{ submission.rejection_reason }}</p>
          </div>
        </div>
      </div>

      <SignatureApprovalModal
        :open="showSignatureModal"
        :approval-step-id="currentApprovalStepId"
        :title="activeAction?.label || 'Konfirmasi Tanda Tangan'"
        @close="showSignatureModal = false"
        @signed="handleSignatureSigned"
      />
    </template>
  </div>
</template>

<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSubmissionStore } from '../../stores/submissions';
import SignatureApprovalModal from './SignatureApprovalModal.vue';

const route = useRoute();
const router = useRouter();
const submissionStore = useSubmissionStore();

const loading = ref(false);
const error = ref(null);
const submission = ref(null);
const approvalNotes = ref('');
const rejectionReason = ref('');
const isApproving = ref(false);
const isRejecting = ref(false);
const showSignatureModal = ref(false);
const editableFormData = ref({});
const pdfPreviewUrl = ref('');
const pdfDownloadUrl = ref('');

const formFields = computed(() => submission.value?.form?.form_config?.fields || []);
const approvalSteps = computed(() => [...(submission.value?.approval_steps || [])].sort((a, b) => a.step_number - b.step_number));
const activeAction = computed(() => submission.value?.available_actions?.[0] || null);
const canEditForm = computed(() => Boolean(activeAction.value?.can_edit_form));
const currentApprovalStepId = computed(() => submission.value?.current_pending_step?.id || 0);

const normalizeOptions = (options) => {
  if (Array.isArray(options)) {
    return options;
  }

  if (typeof options === 'string') {
    return options.split(',').map((option) => option.trim()).filter(Boolean);
  }

  return [];
};

const hydrateEditableData = () => {
  const mapped = {};

  formFields.value.forEach((field) => {
    const currentValue = submission.value?.form_data?.[field.id];

    if (field.type === 'checkbox') {
      mapped[field.id] = Array.isArray(currentValue) ? currentValue : [];
      return;
    }

    mapped[field.id] = currentValue ?? '';
  });

  editableFormData.value = mapped;
};

const displayFormValue = (field) => submission.value?.form_data?.[field.id];

const loadPdfPreview = async () => {
  try {
    const response = await axios.get(`/api/pdf/preview/${route.params.id}`);
    pdfPreviewUrl.value = `${response.data.url}?v=${Date.now()}`;
    pdfDownloadUrl.value = response.data.download_url || '';
  } catch (err) {
    console.error('Error loading PDF preview:', err);
    pdfPreviewUrl.value = '';
    pdfDownloadUrl.value = '';
  }
};

const loadSubmission = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await submissionStore.fetchSubmission(route.params.id);
    submission.value = response.submission;
    hydrateEditableData();
    await loadPdfPreview();
  } catch (err) {
    console.error('Error loading submission:', err);
    error.value = err.response?.data?.error || 'Submission tidak dapat dimuat.';
  } finally {
    loading.value = false;
  }
};

const serializeEditableFormData = () => {
  const payload = {};

  formFields.value.forEach((field) => {
    payload[field.id] = editableFormData.value[field.id];
  });

  return payload;
};

const approveSubmission = async (signatureId = null) => {
  if (!activeAction.value) {
    return;
  }

  isApproving.value = true;
  error.value = null;

  try {
    const payload = {
      notes: approvalNotes.value,
    };

    if (signatureId) {
      payload.signature_id = signatureId;
    }

    if (canEditForm.value) {
      payload.form_data = serializeEditableFormData();
    }

    await submissionStore.approveSubmission(route.params.id, payload);
    approvalNotes.value = '';
    await loadSubmission();
  } catch (err) {
    console.error('Error approving submission:', err);
    error.value = err.response?.data?.error || 'Approval gagal diproses.';
  } finally {
    isApproving.value = false;
  }
};

const handleApproveClick = async () => {
  if (!activeAction.value) {
    return;
  }

  if (activeAction.value.requires_signature) {
    showSignatureModal.value = true;
    return;
  }

  await approveSubmission();
};

const handleSignatureSigned = async (signature) => {
  showSignatureModal.value = false;
  await approveSubmission(signature.id);
};

const rejectSubmission = async () => {
  const reason = rejectionReason.value.trim() || approvalNotes.value.trim();

  if (!reason) {
    error.value = 'Isi alasan penolakan terlebih dahulu.';
    return;
  }

  isRejecting.value = true;

  try {
    await submissionStore.rejectSubmission(route.params.id, {
      rejection_reason: reason,
    });
    rejectionReason.value = '';
    approvalNotes.value = '';
    await loadSubmission();
  } catch (err) {
    console.error('Error rejecting submission:', err);
    error.value = err.response?.data?.error || 'Penolakan gagal diproses.';
  } finally {
    isRejecting.value = false;
  }
};

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
    pending_it: 'Review IT',
    pending_director: 'Approval Direktur',
    pending_accounting: 'Proses Accounting',
    pending_payment: 'Konfirmasi Bayar',
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
    pending_payment: 'status-pending',
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
