<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#111827]/30 px-4 py-6"
    @click.self="$emit('close')"
  >
    <div class="w-full max-w-lg rounded-[24px] border border-[#e8dcc9] bg-white p-5 sm:p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="text-xl font-semibold text-[#111827]">Folder Baru</h2>
          <p class="mt-1 text-sm text-[#9ca3af]">{{ pathLabel }}</p>
        </div>

        <button type="button" class="text-sm font-medium text-[#6b7280]" :disabled="saving" @click="$emit('close')">
          Tutup
        </button>
      </div>

      <div class="mt-5 space-y-4">
        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Nama folder</label>
          <input v-model="form.name" type="text" class="input-field" placeholder="Contoh: SOP Operasional">
          <p v-if="errors.name" class="mt-2 text-sm text-red-700">{{ errors.name }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Deskripsi</label>
          <textarea v-model="form.description" rows="3" class="input-field resize-none" placeholder="Opsional"></textarea>
          <p v-if="errors.description" class="mt-2 text-sm text-red-700">{{ errors.description }}</p>
        </div>
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button type="button" class="rounded-full border border-[#eadfcf] px-4 py-2 text-sm text-[#4b5563] transition hover:border-[#d8bc84]" :disabled="saving" @click="$emit('close')">
          Batal
        </button>
        <button type="button" class="btn-primary min-w-36" :disabled="saving" @click="$emit('submit')">
          {{ saving ? 'Menyimpan...' : 'Buat Folder' }}
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
});

defineEmits(['close', 'submit']);
</script>
