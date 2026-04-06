<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-[#1f2937]/45 px-4 py-6">
    <div class="card max-h-[92vh] w-full max-w-4xl overflow-y-auto p-6 sm:p-7">
      <div class="flex items-start justify-between gap-4">
        <div class="max-w-2xl">
          <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Helpdesk IT</p>
          <h2 class="mt-2 text-2xl font-semibold text-gray-900">{{ modalTitle }}</h2>
          <p class="mt-2 text-sm leading-6 text-gray-600">{{ modalDescription }}</p>
        </div>

        <button type="button" class="text-sm font-medium text-gray-500" :disabled="saving" @click="$emit('close')">
          Tutup
        </button>
      </div>

      <div v-if="errors.general" class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errors.general }}
      </div>

      <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <div v-if="managerMode">
          <label class="mb-2 block text-sm font-medium text-gray-700">Requester</label>
          <select v-model="form.requester_id" class="select-field">
            <option value="">Pilih user</option>
            <option v-for="requester in requesters" :key="requester.id" :value="String(requester.id)">
              {{ requester.name }}{{ requester.department ? ` · ${requester.department}` : '' }}
            </option>
          </select>
          <p v-if="errors.requester_id" class="mt-2 text-sm text-red-700">{{ errors.requester_id }}</p>
        </div>

        <div v-if="managerMode">
          <label class="mb-2 block text-sm font-medium text-gray-700">Sumber Ticket</label>
          <div class="grid grid-cols-2 gap-3">
            <button
              v-for="option in channelOptions"
              :key="option.value"
              type="button"
              class="rounded-[1rem] border px-4 py-3 text-sm font-medium transition"
              :class="form.channel === option.value ? 'border-primary-300 bg-primary-50 text-primary-700' : 'border-gray-200 bg-white text-gray-700'"
              @click="form.channel = option.value"
            >
              {{ option.label }}
            </button>
          </div>
          <p v-if="errors.channel" class="mt-2 text-sm text-red-700">{{ errors.channel }}</p>
        </div>

        <div class="lg:col-span-2">
          <div class="flex items-center justify-between gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Jenis Kendala</label>
              <p class="mt-1 text-xs text-gray-500">Pilih kategori yang paling mendekati supaya ticket mudah diarahkan.</p>
            </div>
            <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
              Quick pick
            </span>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
              v-for="option in categoryOptions"
              :key="option.value"
              type="button"
              class="rounded-[1.1rem] border px-4 py-3 text-left text-sm font-medium transition"
              :class="form.category === option.value ? 'border-primary-300 bg-primary-50 text-primary-700' : 'border-gray-200 bg-white text-gray-700 hover:border-[#d8bc84] hover:bg-[#fffdf9]'"
              @click="form.category = option.value"
            >
              {{ option.label }}
            </button>
          </div>
          <p v-if="errors.category" class="mt-2 text-sm text-red-700">{{ errors.category }}</p>
        </div>

        <div class="lg:col-span-2">
          <label class="mb-2 block text-sm font-medium text-gray-700">Judul Singkat</label>
          <input
            v-model="form.subject"
            type="text"
            class="input-field"
            placeholder="Contoh: Mouse tidak terdeteksi / Outlook tidak bisa dibuka"
          >
          <p class="mt-2 text-xs text-gray-500">Opsional. Kalau dikosongkan, sistem akan ambil ringkasan dari isi kendala.</p>
          <p v-if="errors.subject" class="mt-2 text-sm text-red-700">{{ errors.subject }}</p>
        </div>

        <div class="lg:col-span-2">
          <label class="mb-2 block text-sm font-medium text-gray-700">Apa kendalanya?</label>
          <textarea
            v-model="form.description"
            class="input-field min-h-[9rem] resize-y"
            placeholder="Jelaskan singkat kendala yang sedang terjadi."
          ></textarea>
          <p v-if="errors.description" class="mt-2 text-sm text-red-700">{{ errors.description }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Lampiran</label>
          <input
            type="file"
            class="input-field cursor-pointer"
            accept=".jpg,.jpeg,.png,.webp,.pdf,.txt"
            @change="handleAttachmentChange"
          >
          <p class="mt-2 text-xs text-gray-500">Opsional. Bisa screenshot, foto perangkat, atau file pendukung lain.</p>
          <p v-if="form.attachmentName" class="mt-2 text-sm font-medium text-gray-700">{{ form.attachmentName }}</p>
          <p v-if="errors.attachment" class="mt-2 text-sm text-red-700">{{ errors.attachment }}</p>
        </div>

        <div class="rounded-[1.25rem] border border-gray-200 bg-[#fffdf9] px-4 py-4">
          <label class="flex items-start gap-3">
            <input v-model="form.is_blocking" type="checkbox" class="mt-1">
            <span>
              <span class="block text-sm font-medium text-gray-800">Saya tidak bisa bekerja</span>
              <span class="mt-1 block text-xs leading-5 text-gray-500">Centang kalau kendala ini menghambat pekerjaan utama dan perlu diprioritaskan.</span>
            </span>
          </label>
        </div>

        <template v-if="managerMode">
          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Prioritas</label>
            <select v-model="form.priority" class="select-field">
              <option value="">Pilih prioritas</option>
              <option v-for="option in priorityOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <p v-if="errors.priority" class="mt-2 text-sm text-red-700">{{ errors.priority }}</p>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Status Awal</label>
            <select v-model="form.status" class="select-field">
              <option value="">Pilih status</option>
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <p v-if="errors.status" class="mt-2 text-sm text-red-700">{{ errors.status }}</p>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Assign ke</label>
            <select v-model="form.assigned_to" class="select-field" :disabled="form.assign_to_me">
              <option value="">Belum di-assign</option>
              <option v-for="assignee in assignees" :key="assignee.id" :value="String(assignee.id)">
                {{ assignee.name }}{{ assignee.department ? ` · ${assignee.department}` : '' }}
              </option>
            </select>
            <p v-if="errors.assigned_to" class="mt-2 text-sm text-red-700">{{ errors.assigned_to }}</p>
          </div>

          <div class="rounded-[1.25rem] border border-gray-200 bg-[#fffdf9] px-4 py-4">
            <label class="flex items-start gap-3">
              <input v-model="form.assign_to_me" type="checkbox" class="mt-1" @change="handleAssignToMeChange">
              <span>
                <span class="block text-sm font-medium text-gray-800">Assign ke saya</span>
                <span class="mt-1 block text-xs leading-5 text-gray-500">Cocok untuk tiket yang Anda terima langsung dan ingin segera ditangani sendiri.</span>
              </span>
            </label>
          </div>
        </template>
      </div>

      <div class="mt-8 flex flex-wrap justify-end gap-3">
        <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">Batal</button>
        <button type="button" class="btn-primary" :disabled="saving" @click="submit">
          {{ saving ? 'Menyimpan...' : submitLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  managerMode: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'request',
  },
  filters: {
    type: Object,
    default: () => ({}),
  },
  requesters: {
    type: Array,
    default: () => [],
  },
  assignees: {
    type: Array,
    default: () => [],
  },
  saving: {
    type: Boolean,
    default: false,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
  contextPage: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['close', 'submit']);

const emptyForm = () => ({
  requester_id: '',
  channel: props.mode === 'call' ? 'phone' : 'portal',
  category: '',
  subject: '',
  description: '',
  attachment: null,
  attachmentName: '',
  is_blocking: false,
  priority: '',
  status: '',
  assigned_to: '',
  assign_to_me: false,
});

const form = reactive(emptyForm());

const categoryOptions = computed(() => props.filters?.categories || []);
const priorityOptions = computed(() => props.filters?.priorities || []);
const statusOptions = computed(() => props.filters?.statuses || []);
const channelOptions = computed(() => props.filters?.channels || [
  { value: 'portal', label: 'Portal' },
  { value: 'phone', label: 'Panggilan' },
]);

const modalTitle = computed(() => {
  if (props.managerMode && props.mode === 'call') {
    return 'Log Panggilan Helpdesk';
  }

  if (props.managerMode) {
    return 'Buat Ticket Bantuan';
  }

  return 'Butuh Bantuan IT';
});

const modalDescription = computed(() => {
  if (props.managerMode && props.mode === 'call') {
    return 'Catat bantuan yang masuk lewat telepon atau komunikasi langsung supaya jejak kerjanya tetap rapi untuk tim IT.';
  }

  if (props.managerMode) {
    return 'Buat ticket baru atas nama user internal';
  }

  return 'Isi singkat saja. Identitas akun, divisi, dan waktu laporan akan terisi otomatis oleh sistem.';
});

const submitLabel = computed(() => {
  if (props.managerMode && props.mode === 'call') {
    return 'Simpan Log Panggilan';
  }

  return props.managerMode ? 'Buat Ticket' : 'Kirim ke IT';
});

const resetForm = () => {
  Object.assign(form, emptyForm());

  if (!props.managerMode) {
    form.channel = 'portal';
    form.priority = '';
    form.status = '';
  }
};

watch(
  () => [props.open, props.mode, props.managerMode],
  ([isOpen]) => {
    if (!isOpen) {
      return;
    }

    resetForm();
  },
  { immediate: true }
);

const handleAttachmentChange = (event) => {
  const file = event.target?.files?.[0] || null;
  form.attachment = file;
  form.attachmentName = file?.name || '';
};

const handleAssignToMeChange = () => {
  if (form.assign_to_me) {
    form.assigned_to = '';
  }
};

const submit = () => {
  emit('submit', {
    requester_id: form.requester_id || undefined,
    channel: form.channel || undefined,
    category: form.category,
    subject: form.subject,
    description: form.description,
    attachment: form.attachment,
    is_blocking: form.is_blocking,
    priority: form.priority || undefined,
    status: form.status || undefined,
    assigned_to: form.assigned_to || undefined,
    assign_to_me: form.assign_to_me,
    context: {
      page: props.contextPage || undefined,
    },
  });
};
</script>
