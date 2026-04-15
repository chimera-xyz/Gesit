<template>
  <div class="space-y-6">
    <div v-if="error" class="rounded-[24px] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="card px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm font-medium text-secondary-700">Memuat editor knowledge item...</p>
    </section>

    <template v-else>
      <section class="rounded-[24px] border border-[#e8dcc9] bg-white p-6 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_260px_260px_auto] xl:items-end">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Knowledge Item</p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-[#111827]">Kelola isi, akses, dan lampiran</h2>
          </div>

          <select v-model="activeEntrySpaceId" class="select-field">
            <option :value="null">Pilih divisi</option>
            <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.name }}</option>
          </select>

          <select v-model="activeSectionId" class="select-field" :disabled="entrySectionOptions.length === 0">
            <option :value="null">Pilih submenu</option>
            <option v-for="section in entrySectionOptions" :key="section.id" :value="section.id">{{ section.name }}</option>
          </select>

          <button type="button" class="btn-primary" :disabled="!entryForm.knowledge_section_id" @click="startCreateEntry">
            Tambah Item
          </button>
        </div>
      </section>

      <section v-if="spaces.length === 0" class="rounded-[28px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-6 py-12 text-center text-sm text-[#6b7280]">
        Buat divisi dan submenu lebih dulu sebelum menambahkan knowledge item.
      </section>

      <section v-else class="grid gap-5 2xl:grid-cols-[0.88fr_1.12fr]">
        <article class="rounded-[24px] border border-[#e8dcc9] bg-white p-5 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Daftar Knowledge Item</p>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ selectedSection?.name || 'Belum pilih submenu' }}</h3>
            </div>
            <span
              v-if="selectedSection"
              class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]"
            >
              {{ selectedSection.entries.length }} item
            </span>
          </div>

          <div v-if="!selectedSection" class="mt-6 rounded-[22px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-4 py-8 text-center text-sm text-[#6b7280]">
            Pilih submenu untuk melihat knowledge item.
          </div>

          <div v-else-if="selectedSection.entries.length === 0" class="mt-6 rounded-[22px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-4 py-8 text-center text-sm text-[#6b7280]">
            Submenu ini belum punya knowledge item.
          </div>

          <div v-else class="mt-5 space-y-3">
            <button
              v-for="entry in selectedSection.entries"
              :key="entry.id"
              type="button"
              class="w-full rounded-[22px] border px-4 py-4 text-left transition"
              :class="selectedEntryId === entry.id ? 'border-[#d8bc84] bg-[#fffdf9]' : 'border-[#eee3d4] bg-white hover:border-[#d8bc84]'"
              @click="editEntry(entry)"
            >
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-[#111827]">{{ entry.title }}</p>
                  <p class="mt-1 text-sm text-[#6b7280]">{{ entry.summary || 'Ringkasan belum diisi' }}</p>
                  <div class="mt-3 flex flex-wrap gap-2">
                    <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-[11px] font-semibold text-[#8f6115]">{{ entry.scope === 'internal' ? 'Internal' : 'Domain Sekuritas' }}</span>
                    <span class="rounded-full bg-[#eef4ff] px-3 py-1 text-[11px] font-semibold text-[#315ea8]">{{ entry.type }}</span>
                  </div>
                </div>
                <span class="rounded-full px-3 py-1 text-[11px] font-semibold" :class="entry.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
                  {{ entry.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </div>
            </button>
          </div>
        </article>

        <article class="rounded-[24px] border border-[#e8dcc9] bg-white p-6 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Editor Knowledge Item</p>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ entryForm.id ? 'Edit Knowledge Item' : 'Knowledge Item Baru' }}</h3>
            </div>
            <div class="flex gap-2">
              <button type="button" class="btn-secondary" @click="startCreateEntry">Reset</button>
              <button v-if="entryForm.id" type="button" class="btn-danger" @click="removeEntry(entryForm.id)">Hapus</button>
            </div>
          </div>

          <div class="mt-5 space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Divisi</label>
                <select v-model="activeEntrySpaceId" class="select-field">
                  <option :value="null">Pilih divisi</option>
                  <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.name }}</option>
                </select>
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Submenu</label>
                <select v-model="activeSectionId" class="select-field">
                  <option :value="null">Pilih submenu</option>
                  <option v-for="section in entrySectionOptions" :key="section.id" :value="section.id">{{ section.name }}</option>
                </select>
                <p v-if="entryErrors.knowledge_section_id" class="mt-2 text-sm text-red-700">{{ entryErrors.knowledge_section_id }}</p>
              </div>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Judul knowledge item</label>
              <input v-model="entryForm.title" type="text" class="input-field" placeholder="Judul knowledge item">
              <p v-if="entryErrors.title" class="mt-2 text-sm text-red-700">{{ entryErrors.title }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Mode knowledge</label>
                <select v-model="entryForm.scope" class="select-field">
                  <option v-for="scope in catalogs.scopes" :key="scope.value" :value="scope.value">{{ scope.label }}</option>
                </select>
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Tipe</label>
                <select v-model="entryForm.type" class="select-field">
                  <option v-for="type in catalogs.types" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Sumber</label>
                <select v-model="entryForm.source_kind" class="select-field">
                  <option v-for="sourceKind in catalogs.source_kinds" :key="sourceKind.value" :value="sourceKind.value">{{ sourceKind.label }}</option>
                </select>
              </div>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Ringkasan</label>
              <textarea v-model="entryForm.summary" rows="3" class="input-field resize-none" placeholder="Ringkasan"></textarea>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Isi knowledge</label>
              <textarea v-model="entryForm.body" rows="8" class="input-field resize-none" placeholder="Isi knowledge"></textarea>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Owner</label>
                <input v-model="entryForm.owner_name" type="text" class="input-field" placeholder="PIC dokumen">
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Reviewer</label>
                <input v-model="entryForm.reviewer_name" type="text" class="input-field" placeholder="Reviewer">
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Versi</label>
                <input v-model="entryForm.version_label" type="text" class="input-field" placeholder="v1.0">
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Tanggal berlaku</label>
                <input v-model="entryForm.effective_date" type="date" class="input-field">
              </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Catatan referensi</label>
                <input v-model="entryForm.reference_notes" type="text" class="input-field" placeholder="Contoh: Halaman 3-5">
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Link sumber</label>
                <input v-model="entryForm.source_link" type="url" class="input-field" placeholder="https://...">
              </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Tag</label>
                <input v-model="entryForm.tags_text" type="text" class="input-field" placeholder="Pisahkan dengan koma: mkbd, finance, closing">
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Urutan</label>
                <input v-model.number="entryForm.sort_order" type="number" min="0" class="input-field">
              </div>
            </div>

            <div class="rounded-[24px] border border-[#eee3d4] bg-[#fffdf9] px-5 py-5">
              <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <p class="text-sm font-semibold text-[#111827]">Akses dokumen</p>
                </div>
                <select v-model="entryForm.access_mode" class="select-field lg:w-72">
                  <option v-for="accessMode in catalogs.access_modes" :key="accessMode.value" :value="accessMode.value">{{ accessMode.label }}</option>
                </select>
              </div>

              <div v-if="entryForm.access_mode === 'role_based'" class="mt-4 grid gap-3 md:grid-cols-2">
                <label
                  v-for="role in roles"
                  :key="role.id"
                  class="flex items-center gap-3 rounded-[18px] border border-[#efe5d7] bg-white px-4 py-3 text-sm text-[#4b5563]"
                >
                  <input v-model="entryForm.role_ids" type="checkbox" :value="role.id" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                  {{ role.name }}
                </label>
              </div>
              <p v-if="entryErrors.role_ids" class="mt-2 text-sm text-red-700">{{ entryErrors.role_ids }}</p>
            </div>

            <div class="rounded-[24px] border border-[#eee3d4] bg-[#fffdf9] px-5 py-5">
              <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                <div>
                  <label class="mb-2 block text-sm font-medium text-[#374151]">Lampiran dokumen</label>
                  <input type="file" class="input-field" @change="handleAttachmentChange">
                </div>

                <label v-if="entryForm.existing_attachment_name" class="inline-flex items-center gap-3 text-sm text-[#6b7280]">
                  <input v-model="entryForm.remove_attachment" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                  Hapus lampiran lama
                </label>
              </div>

              <div v-if="entryForm.existing_attachment_name" class="mt-4 rounded-[18px] border border-[#efe5d7] bg-white px-4 py-3 text-sm text-[#4b5563]">
                <p class="font-medium text-[#111827]">{{ entryForm.existing_attachment_name }}</p>
                <a
                  v-if="entryForm.existing_attachment_url"
                  :href="entryForm.existing_attachment_url"
                  target="_blank"
                  rel="noreferrer"
                  class="mt-2 inline-flex text-sm font-medium text-[#8b6316]"
                >
                  Buka lampiran
                </a>
              </div>
            </div>

            <div class="flex flex-wrap gap-4">
              <label class="inline-flex items-center gap-3 text-sm text-[#6b7280]">
                <input v-model="entryForm.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                Knowledge item aktif
              </label>
            </div>

            <button type="button" class="btn-primary w-full" :disabled="savingEntry" @click="saveEntry">
              {{ savingEntry ? 'Menyimpan...' : 'Simpan Knowledge Item' }}
            </button>
          </div>
        </article>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { injectKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const {
  loading,
  error,
  savingEntry,
  selectedEntryId,
  selectedSection,
  selectedSectionId,
  entrySpaceId,
  entryErrors,
  entryForm,
  spaces,
  roles,
  catalogs,
  entrySectionOptions,
  startCreateEntry,
  selectSection,
  editEntry,
  handleEntrySpaceChange,
  handleAttachmentChange,
  saveEntry,
  removeEntry,
} = injectKnowledgeAdminWorkspace();

const activeEntrySpaceId = computed({
  get: () => entrySpaceId.value,
  set: (value) => {
    entrySpaceId.value = value;
    handleEntrySpaceChange();

    if (entryForm.knowledge_section_id) {
      selectSection(entryForm.knowledge_section_id);
    } else {
      startCreateEntry();
    }
  },
});

const activeSectionId = computed({
  get: () => entryForm.knowledge_section_id,
  set: (value) => {
    if (value === null) {
      entryForm.knowledge_section_id = null;
      selectedSectionId.value = null;
      startCreateEntry();
      return;
    }

    selectSection(value);
  },
});
</script>
