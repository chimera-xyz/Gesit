<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-[#1f2937]/45 px-4 py-6">
    <div class="card w-full max-w-3xl p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Tanda Tangan Digital</p>
          <h2 class="mt-2 text-xl font-semibold text-gray-900">{{ title }}</h2>
          <p class="mt-2 text-sm text-gray-600">Pilih metode tanda tangan sebelum approval diproses.</p>
        </div>
        <button type="button" class="text-sm font-medium text-gray-500" @click="closeModal">Tutup</button>
      </div>

      <div class="mt-5 flex flex-wrap gap-3">
        <button
          type="button"
          class="rounded-full px-4 py-2 text-sm font-medium"
          :class="mode === 'draw' ? 'bg-[#9b6b17] text-white' : 'bg-gray-100 text-gray-700'"
          @click="mode = 'draw'"
        >
          Draw Signature
        </button>
        <button
          type="button"
          class="rounded-full px-4 py-2 text-sm font-medium"
          :class="mode === 'upload' ? 'bg-[#9b6b17] text-white' : 'bg-gray-100 text-gray-700'"
          @click="mode = 'upload'"
        >
          Upload PNG/JPG
        </button>
      </div>

      <div class="mt-6">
        <div v-if="mode === 'draw'" class="space-y-4">
          <div class="rounded-[1.5rem] border border-dashed border-gray-300 bg-[#fffdf9] p-4">
            <canvas
              ref="signatureCanvas"
              class="h-[220px] w-full rounded-[1rem] bg-white"
              @pointerdown="startDrawing"
              @pointermove="draw"
              @pointerup="stopDrawing"
              @pointerleave="stopDrawing"
            ></canvas>
          </div>
          <div class="flex flex-wrap justify-between gap-3">
            <p class="text-sm text-gray-500">Gores tanda tangan Anda di area putih.</p>
            <button type="button" class="btn-secondary" @click="clearCanvas">Hapus Canvas</button>
          </div>
        </div>

        <div v-else class="space-y-4">
          <div class="rounded-[1.5rem] border border-dashed border-gray-300 bg-[#fffdf9] p-5">
            <input
              type="file"
              accept="image/png,image/jpeg"
              class="block w-full text-sm text-gray-700"
              @change="handleFileChange"
            >
            <p class="mt-3 text-sm text-gray-500">Gunakan file PNG/JPG dengan background bersih untuk hasil preview yang rapi.</p>
            <p v-if="uploadedFile" class="mt-2 text-sm font-medium text-gray-700">
              File dipilih: {{ uploadedFile.name }}
            </p>
          </div>
        </div>
      </div>

      <p v-if="error" class="mt-4 text-sm text-red-700">{{ error }}</p>

      <div class="mt-6 flex flex-wrap justify-end gap-3">
        <button type="button" class="btn-secondary" @click="closeModal">Batal</button>
        <button type="button" class="btn-primary" :disabled="isSubmitting" @click="submitSignature">
          {{ isSubmitting ? 'Memproses...' : 'Gunakan Tanda Tangan Ini' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import axios from 'axios';
import { nextTick, ref, watch } from 'vue';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  approvalStepId: {
    type: Number,
    required: true,
  },
  title: {
    type: String,
    default: 'Konfirmasi Tanda Tangan',
  },
});

const emit = defineEmits(['close', 'signed']);

const mode = ref('draw');
const error = ref('');
const isSubmitting = ref(false);
const uploadedFile = ref(null);
const signatureCanvas = ref(null);
const isDrawing = ref(false);
const hasStroke = ref(false);

const prepareCanvas = async () => {
  await nextTick();

  if (!signatureCanvas.value) {
    return;
  }

  const canvas = signatureCanvas.value;
  const ratio = window.devicePixelRatio || 1;
  const width = canvas.clientWidth || 520;
  const height = canvas.clientHeight || 220;

  canvas.width = width * ratio;
  canvas.height = height * ratio;

  const context = canvas.getContext('2d');
  context.scale(ratio, ratio);
  context.lineWidth = 2.2;
  context.lineCap = 'round';
  context.lineJoin = 'round';
  context.strokeStyle = '#111827';
  context.clearRect(0, 0, width, height);
};

watch(
  () => props.open,
  async (value) => {
    if (!value) {
      return;
    }

    error.value = '';
    uploadedFile.value = null;
    mode.value = 'draw';
    hasStroke.value = false;
    await prepareCanvas();
  }
);

const getPointerPosition = (event) => {
  const rect = signatureCanvas.value.getBoundingClientRect();

  return {
    x: event.clientX - rect.left,
    y: event.clientY - rect.top,
  };
};

const startDrawing = (event) => {
  if (mode.value !== 'draw' || !signatureCanvas.value) {
    return;
  }

  isDrawing.value = true;
  const context = signatureCanvas.value.getContext('2d');
  const { x, y } = getPointerPosition(event);
  context.beginPath();
  context.moveTo(x, y);
  hasStroke.value = true;
};

const draw = (event) => {
  if (!isDrawing.value || !signatureCanvas.value) {
    return;
  }

  const context = signatureCanvas.value.getContext('2d');
  const { x, y } = getPointerPosition(event);
  context.lineTo(x, y);
  context.stroke();
};

const stopDrawing = () => {
  isDrawing.value = false;
};

const clearCanvas = async () => {
  hasStroke.value = false;
  await prepareCanvas();
};

const handleFileChange = (event) => {
  uploadedFile.value = event.target.files?.[0] || null;
};

const closeModal = () => {
  if (isSubmitting.value) {
    return;
  }

  emit('close');
};

const submitSignature = async () => {
  error.value = '';
  isSubmitting.value = true;

  try {
    let response;

    if (mode.value === 'draw') {
      if (!hasStroke.value || !signatureCanvas.value) {
        error.value = 'Tanda tangan belum digambar.';
        return;
      }

      response = await axios.post('/api/signature/draw', {
        approval_step_id: props.approvalStepId,
        signature_data: signatureCanvas.value.toDataURL('image/png'),
      });
    } else {
      if (!uploadedFile.value) {
        error.value = 'Pilih file tanda tangan terlebih dahulu.';
        return;
      }

      const formData = new FormData();
      formData.append('approval_step_id', props.approvalStepId);
      formData.append('signature', uploadedFile.value);
      response = await axios.post('/api/signature/upload', formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });
    }

    emit('signed', response.data.signature);
  } catch (err) {
    console.error('Error submitting signature:', err);
    error.value = err.response?.data?.error || 'Tanda tangan gagal disimpan.';
  } finally {
    isSubmitting.value = false;
  }
};
</script>
