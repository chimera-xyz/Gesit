<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#111827]/30 px-4 py-6"
    @click.self="$emit('close')"
  >
    <div class="max-h-[92vh] w-full max-w-4xl overflow-y-auto rounded-[24px] border border-[#e8dcc9] bg-white p-5 sm:p-6">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 class="text-xl font-semibold text-[#111827]">{{ form.id ? 'Edit Dokumen' : 'Dokumen Baru' }}</h2>
          <p class="mt-1 text-sm text-[#9ca3af]">{{ divisionName || 'Pilih divisi' }}</p>
        </div>

        <button type="button" class="text-sm font-medium text-[#6b7280]" :disabled="saving" @click="$emit('close')">
          Tutup
        </button>
      </div>

      <div class="mt-5 grid gap-4 md:grid-cols-2">
        <div class="md:col-span-2">
          <label class="mb-2 block text-sm font-medium text-[#374151]">Judul</label>
          <input v-model="form.title" type="text" class="input-field" placeholder="SOP Reset Password Email">
          <p v-if="errors.title" class="mt-2 text-sm text-red-700">{{ errors.title }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Jenis</label>
          <select v-model="form.type" class="select-field">
            <option v-for="type in catalogs.types" :key="type.value" :value="type.value">{{ type.label }}</option>
          </select>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Sumber</label>
          <select v-model="form.source_kind" class="select-field">
            <option v-for="sourceKind in catalogs.source_kinds" :key="sourceKind.value" :value="sourceKind.value">{{ sourceKind.label }}</option>
          </select>
        </div>
      </div>

      <div class="mt-4">
        <label class="mb-2 block text-sm font-medium text-[#374151]">Ringkasan</label>
        <textarea v-model="form.summary" rows="3" class="input-field resize-none" placeholder="Ringkas isi dokumen"></textarea>
      </div>

      <div class="mt-4">
        <label class="mb-2 block text-sm font-medium text-[#374151]">Isi text</label>
        <textarea v-model="form.body" rows="8" class="input-field resize-none" placeholder="Tulis SOP atau konteks inti di sini"></textarea>
      </div>

      <div class="mt-4">
        <label class="mb-2 block text-sm font-medium text-[#374151]">Lampiran</label>
        <input type="file" class="input-field" @change="$emit('attachment-change', $event)">

        <label v-if="form.existing_attachment_name" class="mt-3 inline-flex items-center gap-3 text-sm text-[#6b7280]">
          <input v-model="form.remove_attachment" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
          Hapus file lama
        </label>

        <div v-if="form.existing_attachment_name" class="mt-3 text-sm text-[#6b7280]">
          <span>{{ form.existing_attachment_name }}</span>
          <a
            v-if="form.existing_attachment_url"
            :href="form.existing_attachment_url"
            target="_blank"
            rel="noreferrer"
            class="ml-3 font-medium text-[#8f6115]"
          >
            Buka
          </a>
        </div>
      </div>

      <div class="mt-4 grid gap-4 md:grid-cols-2">
        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Akses</label>
          <select v-model="form.access_mode" class="select-field">
            <option v-for="accessMode in catalogs.access_modes" :key="accessMode.value" :value="accessMode.value">{{ accessMode.label }}</option>
          </select>
        </div>

        <label class="flex items-center gap-3 rounded-[16px] border border-[#f1e9dc] px-4 py-3 text-sm text-[#4b5563] md:mt-7">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
          Dokumen aktif
        </label>
      </div>

      <div v-if="form.access_mode === 'role_based'" class="mt-4">
        <p class="mb-2 text-sm font-medium text-[#374151]">Role</p>
        <div class="grid gap-2 md:grid-cols-2">
          <label
            v-for="role in roles"
            :key="role.id"
            class="flex items-center gap-3 rounded-[16px] border border-[#f1e9dc] px-4 py-3 text-sm text-[#4b5563]"
          >
            <input v-model="form.role_ids" type="checkbox" :value="role.id" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
            {{ role.name }}
          </label>
        </div>
        <p v-if="errors.role_ids" class="mt-2 text-sm text-red-700">{{ errors.role_ids }}</p>
      </div>

      <details class="mt-4 rounded-[16px] border border-[#f1e9dc] px-4 py-3">
        <summary class="cursor-pointer text-sm font-medium text-[#4b5563]">Opsi lanjutan</summary>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">PIC</label>
            <input v-model="form.owner_name" type="text" class="input-field" placeholder="Opsional">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Reviewer</label>
            <input v-model="form.reviewer_name" type="text" class="input-field" placeholder="Opsional">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Versi</label>
            <input v-model="form.version_label" type="text" class="input-field" placeholder="v1.0">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Tanggal berlaku</label>
            <input v-model="form.effective_date" type="date" class="input-field">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Tag</label>
            <input v-model="form.tags_text" type="text" class="input-field" placeholder="pisahkan dengan koma">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Link sumber</label>
            <input v-model="form.source_link" type="url" class="input-field" placeholder="https://...">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Catatan referensi</label>
            <input v-model="form.reference_notes" type="text" class="input-field" placeholder="Opsional">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Mode knowledge</label>
            <select v-model="form.scope" class="select-field">
              <option v-for="scope in catalogs.scopes" :key="scope.value" :value="scope.value">{{ scope.label }}</option>
            </select>
          </div>
        </div>
      </details>

      <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <button
          v-if="form.id"
          type="button"
          class="rounded-full border border-red-200 px-4 py-2 text-sm text-red-700 transition hover:bg-red-50"
          :disabled="saving"
          @click="$emit('remove')"
        >
          Hapus
        </button>
        <div v-else></div>

        <div class="flex flex-wrap gap-2">
          <button type="button" class="rounded-full border border-[#eadfcf] px-4 py-2 text-sm text-[#4b5563] transition hover:border-[#d8bc84]" :disabled="saving" @click="$emit('close')">
            Batal
          </button>
          <button
            type="button"
            class="btn-primary min-w-40"
            :disabled="saving || !form.knowledge_space_id"
            @click="$emit('submit')"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
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
  divisionName: {
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
  catalogs: {
    type: Object,
    default: () => ({
      types: [],
      source_kinds: [],
      access_modes: [],
      scopes: [],
    }),
  },
  roles: {
    type: Array,
    default: () => [],
  },
});

defineEmits(['close', 'submit', 'remove', 'attachment-change']);
</script>
