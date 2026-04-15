<template>
  <div class="space-y-6">
    <div v-if="error" class="rounded-[24px] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="card px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm font-medium text-secondary-700">Memuat editor divisi...</p>
    </section>

    <template v-else>
      <section class="rounded-[24px] border border-[#e8dcc9] bg-white p-6 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Divisi</p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-[#111827]">Kelola struktur level pertama</h2>
          </div>

          <button type="button" class="btn-primary" @click="startCreateSpace">
            Tambah Divisi
          </button>
        </div>
      </section>

      <section class="grid gap-5 2xl:grid-cols-[0.9fr_1.1fr]">
        <article class="rounded-[24px] border border-[#e8dcc9] bg-white p-5 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Daftar Divisi</p>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ spaces.length }} divisi</h3>
            </div>
          </div>

          <div v-if="spaces.length === 0" class="mt-6 rounded-[22px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-4 py-8 text-center text-sm text-[#6b7280]">
            Belum ada divisi knowledge.
          </div>

          <div v-else class="mt-5 space-y-3">
            <button
              v-for="space in spaces"
              :key="space.id"
              type="button"
              class="w-full rounded-[22px] border px-4 py-4 text-left transition"
              :class="selectedSpaceId === space.id ? 'border-[#d8bc84] bg-[#fffdf9]' : 'border-[#eee3d4] bg-white hover:border-[#d8bc84]'"
              @click="selectSpace(space.id)"
            >
              <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-[#111827]">{{ space.name }}</p>
                  <p class="mt-1 text-sm text-[#6b7280]">{{ space.description || 'Tanpa deskripsi' }}</p>
                  <p class="mt-3 text-xs uppercase tracking-[0.18em] text-[#9ca3af]">
                    {{ space.section_count }} submenu · {{ space.entry_count }} item
                  </p>
                </div>
                <span class="rounded-full px-3 py-1 text-[11px] font-semibold" :class="space.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
                  {{ space.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </div>
            </button>
          </div>
        </article>

        <article class="rounded-[24px] border border-[#e8dcc9] bg-white p-6 shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Editor Divisi</p>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ spaceForm.id ? 'Edit Divisi' : 'Divisi Baru' }}</h3>
            </div>
            <div class="flex gap-2">
              <button v-if="spaceForm.id" type="button" class="btn-secondary" @click="startCreateSpace">Reset</button>
              <button v-if="spaceForm.id" type="button" class="btn-danger" @click="removeSpace(spaceForm.id)">Hapus</button>
            </div>
          </div>

          <div class="mt-5 space-y-4">
            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Nama divisi</label>
              <input v-model="spaceForm.name" type="text" class="input-field" placeholder="Contoh: HR, Finance, IT">
              <p v-if="spaceErrors.name" class="mt-2 text-sm text-red-700">{{ spaceErrors.name }}</p>
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Deskripsi singkat</label>
              <textarea v-model="spaceForm.description" rows="3" class="input-field resize-none" placeholder="Deskripsi"></textarea>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Icon</label>
                <input v-model="spaceForm.icon" type="text" class="input-field" placeholder="folder / cpu / chart">
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Urutan</label>
                <input v-model.number="spaceForm.sort_order" type="number" min="0" class="input-field">
              </div>
            </div>
            <label class="inline-flex items-center gap-3 text-sm text-[#6b7280]">
              <input v-model="spaceForm.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
              Divisi aktif
            </label>
            <button type="button" class="btn-primary w-full" :disabled="savingSpace" @click="saveSpace">
              {{ savingSpace ? 'Menyimpan...' : 'Simpan Divisi' }}
            </button>
          </div>
        </article>
      </section>
    </template>
  </div>
</template>

<script setup>
import { injectKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const {
  loading,
  error,
  savingSpace,
  selectedSpaceId,
  spaceErrors,
  spaceForm,
  spaces,
  startCreateSpace,
  selectSpace,
  saveSpace,
  removeSpace,
} = injectKnowledgeAdminWorkspace();
</script>
