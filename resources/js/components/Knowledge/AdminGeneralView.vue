<template>
  <div class="space-y-4">
    <div v-if="error" class="rounded-[18px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="rounded-[20px] border border-[#e8dcc9] bg-white px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm text-[#6b7280]">Memuat...</p>
    </section>

    <section v-else class="rounded-[20px] border border-[#e8dcc9] bg-white p-5 sm:p-6">
      <div class="flex items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-[#111827]">General Knowledge</h2>

        <label class="inline-flex items-center gap-2 text-sm text-[#6b7280]">
          <input v-model="generalForm.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
          Aktif
        </label>
      </div>

      <div class="mt-5 space-y-4">
        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Deskripsi</label>
          <input
            v-model="generalForm.description"
            type="text"
            class="input-field"
            placeholder="Knowledge umum perusahaan"
          >
          <p v-if="generalErrors.description" class="mt-2 text-sm text-red-700">{{ generalErrors.description }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">AI instruction</label>
          <textarea
            v-model="generalForm.ai_instruction"
            rows="7"
            class="input-field resize-none"
            placeholder="Arah jawaban AI secara umum"
          ></textarea>
          <p v-if="generalErrors.ai_instruction" class="mt-2 text-sm text-red-700">{{ generalErrors.ai_instruction }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-[#374151]">Knowledge text</label>
          <textarea
            v-model="generalForm.knowledge_text"
            rows="10"
            class="input-field resize-none"
            placeholder="Konteks umum yang selalu dibawa AI"
          ></textarea>
          <p v-if="generalErrors.knowledge_text" class="mt-2 text-sm text-red-700">{{ generalErrors.knowledge_text }}</p>
        </div>

        <div class="flex justify-end">
          <button type="button" class="btn-primary min-w-44" :disabled="savingGeneral" @click="saveGeneral">
            {{ savingGeneral ? 'Menyimpan...' : 'Simpan' }}
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { injectKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const {
  loading,
  error,
  savingGeneral,
  generalErrors,
  generalForm,
  saveGeneral,
} = injectKnowledgeAdminWorkspace();
</script>
