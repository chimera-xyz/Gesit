<template>
  <div class="form-viewer">
    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-900 mx-auto"></div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="card p-6">
      <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <h2 class="text-lg font-medium text-red-900">Error</h2>
        <p class="text-red-800">{{ error }}</p>
        <button
          @click="loadForm"
          class="btn-primary mt-4"
        >
          Coba Lagi
        </button>
      </div>
    </div>

    <!-- Form Content -->
    <div v-else-if="form">
      <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ form.name }}</h1>
        <p class="mt-2 text-gray-600">{{ form.description }}</p>
        <div class="status-badge status-{{ getStatusClass(submission.current_status) }} mb-4">
          {{ formatStatus(submission.current_status) }}
        </div>
      </div>

      <!-- Form Fields -->
      <div v-if="form.form_config && form.form_config.fields" class="card p-6 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-medium text-gray-900 mb-4">Isi Form</h2>
          <p class="text-sm text-gray-500 mb-6">Lengkapi semua field yang diperlukan</p>
        </div>

        <form @submit.prevent="submitForm">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div v-for="(field, index) in form.form_config.fields" :key="field.id">
              <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  {{ field.label }}
                  <span v-if="field.required" class="text-red-500"> *</span>
                </label>

                <!-- Text Input -->
                <input
                  v-if="field.type === 'text'"
                  v-model="formData[field.id]"
                  type="text"
                  :placeholder="field.placeholder"
                  :required="field.required"
                  class="input-field"
                >

                <!-- Textarea -->
                <textarea
                  v-if="field.type === 'textarea'"
                  v-model="formData[field.id]"
                  :placeholder="field.placeholder"
                  :required="field.required"
                  rows="3"
                  class="input-field"
                ></textarea>

                <!-- Number Input -->
                <input
                  v-if="field.type === 'number'"
                  v-model="formData[field.id]"
                  type="number"
                  :placeholder="field.placeholder"
                  :required="field.required"
                  class="input-field"
                >

                <!-- Select Dropdown -->
                <select
                  v-if="field.type === 'select'"
                  v-model="formData[field.id]"
                  :required="field.required"
                  class="select-field"
                >
                  <option value="">Pilih opsi</option>
                  <option
                    v-for="(option, idx) in field.options?.split(',') || []"
                    :key="idx"
                    :value="option.trim()"
                  >
                    {{ option.trim() }}
                  </option>
                </select>

                <!-- Date Picker -->
                <input
                  v-if="field.type === 'date'"
                  v-model="formData[field.id]"
                  type="date"
                  :required="field.required"
                  class="input-field"
                >

                <!-- File Upload -->
                <input
                  v-if="field.type === 'file'"
                  type="file"
                  @change="handleFileUpload(field.id, $event)"
                  :required="field.required"
                  class="input-field"
                >
                <p v-if="formData[field.id + '_file']" class="text-xs text-gray-500 mt-1">
                  File terpilih: {{ formData[field.id + '_file'].name }}
                </p>

                <!-- Select Options -->
                <select
                  v-if="field.options && field.type !== 'select'"
                  v-model="formData[field.id]"
                  :required="field.required"
                  class="select-field"
                >
                  <option value="">Pilih opsi</option>
                  <option
                    v-for="(option, idx) in field.options?.split(',') || []"
                    :key="idx"
                    :value="option.trim()"
                  >
                    {{ option.trim() }}
                  </option>
                </select>
              </div>
            </div>
          </div>

          <!-- Submit Button -->
          <div class="mt-8">
            <button
              type="submit"
              :disabled="isSubmitting"
              class="btn-primary w-full"
            >
              {{ isSubmitting ? 'Mengirim...' : 'Kirim Request' }}
            </button>
          </div>
        </form>

        <!-- Digital Signature Section -->
        <div
          v-if="submission && submission.current_status !== 'rejected'"
          class="card p-6 mb-6"
        >
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Tanda Tangan Digital</h2>
            <p class="text-sm text-gray-500 mb-6">
              Tambahkan tanda tangan digital untuk menyetujui request ini.
              <span v-if="submission.signature" class="text-green-600">* Tanda tangan sudah ditambahkan</span>
            </p>

            <!-- Signature Methods -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Draw Signature -->
              <div class="card p-6">
                <h3 class="text-sm font-medium text-gray-900 mb-4">Draw Signature</h3>
                <div class="signature-container">
                  <canvas
                    ref="signatureCanvas"
                    @mousedown="startDrawing"
                    @mousemove="draw"
                    @mouseup="stopDrawing"
                    @mouseleave="stopDrawing"
                    class="border border-gray-300 rounded-lg cursor-crosshair"
                    style="width: 400px; height: 200px;"
                  ></canvas>
                </div>
                <div class="mt-4 space-y-2">
                  <button
                    @click="clearCanvas"
                    class="btn-secondary"
                  >
                    Hapus Canvas
                  </button>
                  <button
                    @click="saveDrawnSignature"
                    :disabled="!hasDrawnSignature"
                    class="btn-primary"
                  >
                    Simpan Signature
                  </button>
                </div>
              </div>

              <!-- Upload Signature -->
              <div class="card p-6">
                <h3 class="text-sm font-medium text-gray-900 mb-4">Upload Signature</h3>
                <input
                  type="file"
                  @change="handleSignatureUpload"
                  accept="image/png,image/jpeg"
                  class="input-field"
                >
                <p class="text-xs text-gray-500 mt-1">
                  Format yang didukung: PNG, JPEG, JPG
                  Maksimal ukuran: 5MB
                </p>
                <div class="mt-4">
                  <button
                    @click="saveUploadedSignature"
                    :disabled="!uploadedSignature"
                    class="btn-primary"
                  >
                    Simpan Signature
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';
import { useSubmissionStore } from '../../stores/submissions';
import { useAuthStore } from '../../stores/auth';
import axios from 'axios';

const route = useRoute();
const router = useRouter();
const formStore = useFormStore();
const submissionStore = useSubmissionStore();
const authStore = useAuthStore();

const loading = ref(false);
const error = ref(null);
const form = ref(null);
const submission = ref(null);
const formData = ref({});

// Signature Canvas
const signatureCanvas = ref(null);
const isDrawing = ref(false);
const lastX = ref(0);
const lastY = ref(0);
const hasDrawnSignature = ref(false);
const uploadedSignature = ref(null);

// Check if current user can sign
const canSign = computed(() => {
  if (!submission.value || !form.value) return false;
  if (submission.value?.current_status === 'rejected') return false;
  if (!authStore.canApprove) return false;
  return true;
});

const formatStatus = (status) => {
  const labels = {
    'submitted': 'Dikirim',
    'pending_it': 'Menunggu IT Review',
    'pending_director': 'Menunggu Director Approval',
    'pending_accounting': 'Menunggu Accounting Process',
    'approved': 'Disetujui',
    'completed': 'Selesai',
    'rejected': 'Ditolak',
  };
  return labels[status] || status;
};

const getStatusClass = (status) => {
  const classes = {
    'submitted': 'status-submitted',
    'pending_it': 'status-pending',
    'pending_director': 'status-pending',
    'pending_accounting': 'status-pending',
    'approved': 'status-approved',
    'completed': 'status-completed',
    'rejected': 'status-rejected',
  };
  return classes[status] || 'status-pending';
};

const loadForm = async () => {
  loading.value = true;
  error.value = null;

  try {
    await formStore.fetchForm(route.params.id);
    form.value = formStore.activeForm;

    // Load existing submission if available
    if (form.value.workflow_id) {
      const response = await axios.get(`/api/form-submissions?form_id=${route.params.id}&user_id=${authStore.user?.id}`);
      if (response.data.length > 0) {
        submission.value = response.data[0];
        // Populate form data
        formData.value = { ...submission.value.form_data };
      }
    }
  } catch (err) {
    console.error('Error loading form:', err);
    error.value = 'Gagal memuat form. Silakan coba lagi.';
  } finally {
    loading.value = false;
  }
};

const submitForm = async () => {
  loading.value = true;
  error.value = null;

  try {
    // Create submission
    const response = await axios.post('/api/form-submissions', {
      form_id: form.value.id,
      form_data: formData.value,
    });

    submission.value = response.data;
    router.push(`/submissions/${response.data.id}`);

    // Create notification for approvers
    await createNotification('form_submitted', `Form submission ${form.value.name} membutuhkan approval Anda`, `/submissions/${response.data.id}`);
  } catch (err) {
    console.error('Error submitting form:', err);
    error.value = 'Gagal mengirim form. ' . err.response?.data?.message || 'Silakan coba lagi.';
  } finally {
    loading.value = false;
  }
};

// Canvas Drawing Functions
const startDrawing = (e) => {
  isDrawing.value = true;
  const rect = signatureCanvas.value.getBoundingClientRect();
  lastX.value = e.offsetX - rect.left;
  lastY.value = e.offsetY - rect.top;
};

const draw = (e) => {
  if (!isDrawing.value) return;

  const ctx = signatureCanvas.value.getContext('2d');
  ctx.beginPath();
  ctx.moveTo(lastX.value, lastY.value);
  ctx.lineTo(e.offsetX - signatureCanvas.value.getBoundingClientRect().left, e.offsetY - signatureCanvas.value.getBoundingClientRect().top);
  ctx.stroke();
  lastX.value = e.offsetX - signatureCanvas.value.getBoundingClientRect().left;
  lastY.value = e.offsetY - signatureCanvas.value.getBoundingClientRect().top;
};

const stopDrawing = () => {
  isDrawing.value = false;
};

const clearCanvas = () => {
  const ctx = signatureCanvas.value.getContext('2d');
  ctx.clearRect(0, 0, signatureCanvas.value.width, signatureCanvas.value.height);
  hasDrawnSignature.value = false;
};

const saveDrawnSignature = async () => {
  try {
    const imageData = signatureCanvas.value.toDataURL();

    const response = await axios.post('/api/signatures/draw', {
      signature_data: imageData,
      approval_step_id: getCurrentApprovalStepId(),
    });

    hasDrawnSignature.value = true;
    alert('Signature berhasil disimpan!');
  } catch (err) {
    console.error('Error saving signature:', err);
    alert('Gagal menyimpan signature. Silakan coba lagi.');
  }
};

// Signature Upload Functions
const handleSignatureUpload = (fieldId, event) => {
  const file = event.target.files[0];
  uploadedSignature.value = file;
  formData.value[fieldId + '_file'] = file;
};

const saveUploadedSignature = async () => {
  try {
    const formData = new FormData();
    formData.append('signature', uploadedSignature.value);
    formData.append('approval_step_id', getCurrentApprovalStepId());

    const response = await axios.post('/api/signatures/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    uploadedSignature.value = response.data.signature;
    hasDrawnSignature.value = true;
    alert('Signature berhasil disimpan!');
  } catch (err) {
    console.error('Error uploading signature:', err);
    alert('Gagal mengunggah signature. Silakan coba lagi.');
  }
};

const getCurrentApprovalStepId = () => {
  if (submission.value?.approval_steps) {
    const pendingSteps = submission.value.approval_steps.filter(step => step.status === 'pending');
    return pendingSteps.length > 0 ? pendingSteps[0].id : null;
  }
  return null;
};

const createNotification = async (type, message, link) => {
  try {
    await axios.post('/api/notifications', {
      title: 'Status Changed',
      type: type,
      message: message,
      link: link,
    });
  } catch (err) {
    console.error('Error creating notification:', err);
  }
};

onMounted(() => {
  loadForm();

  // Setup canvas
  if (signatureCanvas.value) {
    const ctx = signatureCanvas.value.getContext('2d');
    ctx.strokeStyle = '#000000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
  }
});

onUnmounted(() => {
  // Clean up canvas
  if (signatureCanvas.value) {
    const ctx = signatureCanvas.value.getContext('2d');
    ctx.clearRect(0, 0, signatureCanvas.value.width, signatureCanvas.value.height);
  }
});
</script>