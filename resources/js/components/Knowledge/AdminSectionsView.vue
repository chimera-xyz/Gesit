<template>
  <div class="space-y-6">
    <div v-if="error" class="rounded-[24px] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="card px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm font-medium text-secondary-700">Memuat editor submenu...</p>
    </section>

    <template v-else>
      <section class="rounded-[24px] border border-[#e8dcc9] bg-white p-6 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_280px_auto] xl:items-end">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Submenu</p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-[#111827]">Kelola submenu per divisi</h2>
          </div>

          <select v-model="activeSpaceId" class="select-field">
            <option :value="null">Pilih divisi</option>
            <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.name }}</option>
          </select>

          <button type="button" class="btn-primary" :disabled="!selectedSpaceId" @click="startCreateSection">
            Tambah Submenu
          </button>
        </div>
      </section>

      <section v-if="spaces.length === 0" class="rounded-[28px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-6 py-12 text-center text-sm text-[#6b7280]">
        Buat divisi lebih dulu sebelum menambahkan submenu.
      </section>

      <section v-else class="grid gap-5 2xl:grid-cols-[0.9fr_1.1fr]">
        <article class="rounded-[24px] border border-[#e8dcc9] bg-white p-5 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Daftar Submenu</p>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ selectedSpace?.name || 'Belum pilih divisi' }}</h3>
            </div>
            <span
              v-if="selectedSpace"
              class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]"
            >
              {{ selectedSpace.sections.length }} submenu
            </span>
          </div>

          <div v-if="!selectedSpace" class="mt-6 rounded-[22px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-4 py-8 text-center text-sm text-[#6b7280]">
            Pilih divisi untuk melihat submenu.
          </div>

          <div v-else-if="selectedSpace.sections.length === 0" class="mt-6 rounded-[22px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-4 py-8 text-center text-sm text-[#6b7280]">
            Divisi ini belum punya submenu.
          </div>

          <div v-else class="mt-5 space-y-3">
            <button
              v-for="section in selectedSpace.sections"
              :key="section.id"
              type="button"
              class="w-full rounded-[22px] border px-4 py-4 text-left transition"
              :class="selectedSectionId === section.id ? 'border-[#d8bc84] bg-[#fffdf9]' : 'border-[#eee3d4] bg-white hover:border-[#d8bc84]'"
              @click="selectSection(section.id)"
            >
              <p class="text-sm font-semibold text-[#111827]">{{ section.name }}</p>
              <p class="mt-1 text-sm text-[#6b7280]">{{ section.description || 'Tanpa deskripsi' }}</p>
              <p class="mt-3 text-xs uppercase tracking-[0.18em] text-[#9ca3af]">
                {{ section.entry_count }} knowledge item
              </p>
            </button>
          </div>
        </article>

        <article class="rounded-[24px] border border-[#e8dcc9] bg-white p-6 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Editor Submenu</p>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ sectionForm.id ? 'Edit Submenu' : 'Submenu Baru' }}</h3>
            </div>
            <div class="flex gap-2">
              <button v-if="sectionForm.id" type="button" class="btn-secondary" @click="startCreateSection">Reset</button>
              <button v-if="sectionForm.id" type="button" class="btn-danger" @click="removeSection(sectionForm.id)">Hapus</button>
            </div>
          </div>

          <div class="mt-5 space-y-4">
            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Divisi</label>
              <select v-model="sectionForm.knowledge_space_id" class="select-field">
                <option :value="null">Pilih divisi</option>
                <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.name }}</option>
              </select>
              <p v-if="sectionErrors.knowledge_space_id" class="mt-2 text-sm text-red-700">{{ sectionErrors.knowledge_space_id }}</p>
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Nama submenu</label>
              <input v-model="sectionForm.name" type="text" class="input-field" placeholder="Nama submenu">
              <p v-if="sectionErrors.name" class="mt-2 text-sm text-red-700">{{ sectionErrors.name }}</p>
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Deskripsi</label>
              <textarea v-model="sectionForm.description" rows="3" class="input-field resize-none" placeholder="Deskripsi"></textarea>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Urutan</label>
                <input v-model.number="sectionForm.sort_order" type="number" min="0" class="input-field">
              </div>
              <div class="flex items-end">
                <label class="inline-flex items-center gap-3 text-sm text-[#6b7280]">
                  <input v-model="sectionForm.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                  Submenu aktif
                </label>
              </div>
            </div>
            <button type="button" class="btn-primary w-full" :disabled="savingSection" @click="saveSection">
              {{ savingSection ? 'Menyimpan...' : 'Simpan Submenu' }}
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
  savingSection,
  selectedSpaceId,
  selectedSectionId,
  sectionErrors,
  sectionForm,
  spaces,
  selectedSpace,
  startCreateSection,
  selectSpace,
  selectSection,
  saveSection,
  removeSection,
} = injectKnowledgeAdminWorkspace();

const activeSpaceId = computed({
  get: () => selectedSpaceId.value,
  set: (value) => {
    if (value === null) {
      return;
    }

    selectSpace(value);
  },
});
</script>
