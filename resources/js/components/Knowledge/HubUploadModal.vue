<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#111827]/30 px-4 py-6"
    @click.self="$emit('close')"
  >
    <div class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-[24px] border border-[#e8dcc9] bg-white p-5 sm:p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="text-xl font-semibold text-[#111827]">Upload File</h2>
          <p class="mt-1 text-sm text-[#9ca3af]">{{ pathLabel }}</p>
        </div>

        <button type="button" class="text-sm font-medium text-[#6b7280]" :disabled="saving" @click="$emit('close')">
          Tutup
        </button>
      </div>

      <div class="mt-5 grid gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
          <label class="mb-2 block text-sm font-medium text-[#374151]">Judul</label>
          <input v-model="form.title" type="text" class="input-field" placeholder="Contoh: SOP Reset Password Email">
          <p v-if="errors.title" class="mt-2 text-sm text-red-700">{{ errors.title }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Tipe</label>
          <select v-model="form.type" class="select-field">
            <option v-for="type in typeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
          </select>
          <p v-if="errors.type" class="mt-2 text-sm text-red-700">{{ errors.type }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Lampiran</label>
          <input type="file" class="input-field" @change="$emit('attachment-change', $event)">
          <p class="mt-2 text-xs text-[#9ca3af]">AI akan membaca isi file ini otomatis saat menjawab pertanyaan yang relevan.</p>
          <p v-if="errors.attachment" class="mt-2 text-sm text-red-700">{{ errors.attachment }}</p>
        </div>
      </div>

      <div class="mt-4">
        <label class="mb-2 block text-sm font-medium text-[#374151]">Ringkasan</label>
        <textarea v-model="form.summary" rows="3" class="input-field resize-none" placeholder="Opsional"></textarea>
        <p v-if="errors.summary" class="mt-2 text-sm text-red-700">{{ errors.summary }}</p>
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button type="button" class="rounded-full border border-[#eadfcf] px-4 py-2 text-sm text-[#4b5563] transition hover:border-[#d8bc84]" :disabled="saving" @click="$emit('close')">
          Batal
        </button>
        <button type="button" class="btn-primary min-w-36" :disabled="saving" @click="$emit('submit')">
          {{ saving ? 'Mengunggah...' : 'Upload' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  saving: {
    type: Boolean,
    default: false,
  },
  pathLabel: {
    type: String,
    default: '',
  },
  form: {
    type: Object,
    required: true,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
  typeOptions: {
    type: Array,
    default: () => [],
  },
});

defineEmits(['close', 'submit', 'attachment-change']);
</script>
