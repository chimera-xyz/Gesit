<template>
  <div class="space-y-4">
    <div v-if="error" class="rounded-[18px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="rounded-[20px] border border-[#e8dcc9] bg-white px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm text-[#6b7280]">Memuat...</p>
    </section>

    <section v-else class="grid gap-4 xl:grid-cols-[260px_minmax(0,1fr)]">
      <aside class="rounded-[20px] border border-[#e8dcc9] bg-white p-4">
        <div class="flex items-center justify-between gap-3 border-b border-[#f1e9dc] pb-3">
          <div>
            <h2 class="text-sm font-semibold text-[#111827]">Divisi</h2>
            <p class="text-xs text-[#9ca3af]">{{ divisions.length }} item</p>
          </div>

          <button type="button" class="rounded-full border border-[#eadfcf] px-3 py-1.5 text-xs font-medium text-[#8f6115] transition hover:border-[#d8bc84]" @click="startCreateDivision">
            Tambah
          </button>
        </div>

        <div v-if="divisions.length === 0" class="py-8 text-center text-sm text-[#6b7280]">
          Belum ada divisi.
        </div>

        <div v-else class="mt-3 space-y-2">
          <button
            v-for="division in divisions"
            :key="division.id"
            type="button"
            class="w-full rounded-[16px] border px-3 py-3 text-left transition"
            :class="selectedDivisionId === division.id ? 'border-[#d8bc84] bg-[#fffaf1]' : 'border-[#f1e9dc] hover:border-[#d8bc84]'"
            @click="selectDivision(division.id)"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="min-w-0 truncate text-sm font-medium text-[#111827]">{{ division.name }}</p>
              <span class="h-2.5 w-2.5 rounded-full" :class="division.is_active ? 'bg-green-500' : 'bg-slate-300'"></span>
            </div>
            <p class="mt-1 text-xs text-[#9ca3af]">{{ division.document_count }} dokumen</p>
            <p v-if="division.description" class="mt-2 line-clamp-2 text-sm text-[#6b7280]">
              {{ division.description }}
            </p>
          </button>
        </div>
      </aside>

      <div class="space-y-4">
        <section class="rounded-[20px] border border-[#e8dcc9] bg-white p-5">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-lg font-semibold text-[#111827]">{{ divisionForm.id ? divisionForm.name || 'Edit Divisi' : 'Divisi Baru' }}</h2>

            <div class="flex flex-wrap gap-2">
              <button v-if="divisionForm.id" type="button" class="rounded-full border border-[#eadfcf] px-4 py-2 text-sm text-[#4b5563] transition hover:border-[#d8bc84]" @click="startCreateDivision">
                Reset
              </button>
              <button v-if="divisionForm.id" type="button" class="rounded-full border border-red-200 px-4 py-2 text-sm text-red-700 transition hover:bg-red-50" @click="removeDivision(divisionForm.id)">
                Hapus
              </button>
            </div>
          </div>

          <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Nama divisi</label>
              <input v-model="divisionForm.name" type="text" class="input-field" placeholder="IT, HR, Finance">
              <p v-if="divisionErrors.name" class="mt-2 text-sm text-red-700">{{ divisionErrors.name }}</p>
            </div>

            <label class="flex items-center gap-3 rounded-[16px] border border-[#f1e9dc] px-4 py-3 text-sm text-[#4b5563] md:mt-7">
              <input v-model="divisionForm.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
              Divisi aktif
            </label>
          </div>

          <div class="mt-4">
            <label class="mb-2 block text-sm font-medium text-[#374151]">Deskripsi</label>
            <input v-model="divisionForm.description" type="text" class="input-field" placeholder="Area knowledge utama divisi">
          </div>

          <div class="mt-4">
            <label class="mb-2 block text-sm font-medium text-[#374151]">AI instruction</label>
            <textarea
              v-model="divisionForm.ai_instruction"
              rows="6"
              class="input-field resize-none"
              placeholder="Arah jawaban AI khusus untuk divisi ini"
            ></textarea>
          </div>

          <div class="mt-4">
            <label class="mb-2 block text-sm font-medium text-[#374151]">Knowledge text</label>
            <textarea
              v-model="divisionForm.knowledge_text"
              rows="8"
              class="input-field resize-none"
              placeholder="Ringkasan konteks yang selalu dibawa AI untuk divisi ini"
            ></textarea>
          </div>

          <details class="mt-4 rounded-[16px] border border-[#f1e9dc] px-4 py-3">
            <summary class="cursor-pointer text-sm font-medium text-[#4b5563]">Opsi lanjutan</summary>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Icon</label>
                <input v-model="divisionForm.icon" type="text" class="input-field" placeholder="folder">
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-[#374151]">Urutan</label>
                <input v-model.number="divisionForm.sort_order" type="number" min="0" class="input-field">
              </div>
            </div>
          </details>

          <div class="mt-5 flex justify-end">
            <button type="button" class="btn-primary min-w-44" :disabled="savingDivision" @click="saveDivision">
              {{ savingDivision ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </section>

        <section class="rounded-[20px] border border-[#e8dcc9] bg-white p-5">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="text-lg font-semibold text-[#111827]">Dokumen</h2>
              <p class="text-sm text-[#9ca3af]">{{ selectedDivision?.name || 'Pilih divisi' }}</p>
            </div>

            <button
              type="button"
              class="rounded-full border border-[#eadfcf] px-4 py-2 text-sm font-medium text-[#8f6115] transition hover:border-[#d8bc84] disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="!selectedDivision"
              @click="openCreateDocumentModal"
            >
              Dokumen Baru
            </button>
          </div>

          <div v-if="!selectedDivision" class="py-10 text-center text-sm text-[#6b7280]">
            Pilih divisi untuk mengelola dokumen.
          </div>

          <div v-else class="mt-5 grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
              <div v-if="divisionDocuments.length === 0" class="rounded-[16px] border border-dashed border-[#eadfcf] px-4 py-8 text-center text-sm text-[#6b7280]">
                Belum ada dokumen.
              </div>

              <button
                v-for="document in divisionDocuments"
                :key="document.id"
                type="button"
                class="w-full rounded-[16px] border px-3 py-3 text-left transition"
                :class="selectedDocumentId === document.id ? 'border-[#d8bc84] bg-[#fffaf1]' : 'border-[#f1e9dc] hover:border-[#d8bc84]'"
                @click="openEditDocumentModal(document)"
              >
                <div class="flex items-center justify-between gap-3">
                  <p class="min-w-0 truncate text-sm font-medium text-[#111827]">{{ document.title }}</p>
                  <span class="h-2.5 w-2.5 rounded-full" :class="document.is_active ? 'bg-green-500' : 'bg-slate-300'"></span>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                  <span class="rounded-full bg-[#fbf5ea] px-2.5 py-1 text-[11px] font-medium text-[#8f6115]">{{ typeLabel(document.type) }}</span>
                  <span class="rounded-full bg-[#eef4ff] px-2.5 py-1 text-[11px] font-medium text-[#315ea8]">{{ sourceKindLabel(document.source_kind) }}</span>
                </div>
                <p v-if="document.summary" class="mt-2 line-clamp-2 text-sm text-[#6b7280]">
                  {{ document.summary }}
                </p>
              </button>
          </div>
        </section>
      </div>
    </section>

    <AdminDocumentModal
      :open="isDocumentModalOpen"
      :saving="savingDocument"
      :division-name="selectedDivision?.name || ''"
      :form="documentForm"
      :errors="documentErrors"
      :catalogs="catalogs"
      :roles="roles"
      @close="closeDocumentModal"
      @submit="submitDocumentModal"
      @remove="removeDocumentFromModal"
      @attachment-change="handleAttachmentChange"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import AdminDocumentModal from './AdminDocumentModal.vue';
import { injectKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const {
  loading,
  error,
  savingDivision,
  savingDocument,
  selectedDivisionId,
  selectedDocumentId,
  divisionErrors,
  documentErrors,
  divisionForm,
  documentForm,
  divisions,
  roles,
  catalogs,
  selectedDivision,
  divisionDocuments,
  startCreateDivision,
  startCreateDocument,
  selectDivision,
  saveDivision,
  saveDocument,
  removeDivision,
  removeDocument,
  handleAttachmentChange,
  editDocument,
} = injectKnowledgeAdminWorkspace();

const isDocumentModalOpen = ref(false);

const openCreateDocumentModal = () => {
  startCreateDocument(selectedDivisionId.value);
  isDocumentModalOpen.value = true;
};

const openEditDocumentModal = (document) => {
  editDocument(document);
  isDocumentModalOpen.value = true;
};

const closeDocumentModal = () => {
  isDocumentModalOpen.value = false;
  documentErrors.value = {};
  error.value = '';
};

const submitDocumentModal = async () => {
  const saved = await saveDocument();

  if (saved) {
    isDocumentModalOpen.value = false;
  }
};

const removeDocumentFromModal = async () => {
  if (!documentForm.id) {
    return;
  }

  const removed = await removeDocument(documentForm.id);

  if (removed) {
    isDocumentModalOpen.value = false;
  }
};

const typeLabel = (value) => {
  return catalogs.value.types.find((item) => item.value === value)?.label || value;
};

const sourceKindLabel = (value) => {
  return catalogs.value.source_kinds.find((item) => item.value === value)?.label || value;
};
</script>
