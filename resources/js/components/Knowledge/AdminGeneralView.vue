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
          <label class="mb-2 block text-sm font-medium text-[#374151]">Provider AI aktif</label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label
              v-for="option in providerOptions"
              :key="option.value"
              class="cursor-pointer rounded-[18px] border px-4 py-4 transition"
              :class="generalForm.ai_provider === option.value ? 'border-[#d8bc84] bg-[#fff6e8]' : 'border-[#eadfcf] bg-[#fcfaf6]'"
            >
              <input
                v-model="generalForm.ai_provider"
                type="radio"
                class="sr-only"
                :value="option.value"
              >
              <p class="text-sm font-semibold text-[#111827]">{{ option.label }}</p>
              <p class="mt-1 text-xs leading-5 text-[#6b7280]">
                {{ option.value === 'zai' ? 'Pakai konfigurasi GLM / Z.ai dari server backend.' : 'Pakai AI lokal perusahaan via endpoint OpenAI-compatible.' }}
              </p>
            </label>
          </div>
          <p v-if="generalErrors.ai_provider" class="mt-2 text-sm text-red-700">{{ generalErrors.ai_provider }}</p>
        </div>

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

        <section
          v-if="generalForm.ai_provider === 'local'"
          class="rounded-[18px] border border-[#eadfcf] bg-[#fcfaf6] p-4 sm:p-5"
        >
          <div class="flex flex-col gap-1">
            <h3 class="text-base font-semibold text-[#111827]">Konfigurasi AI Local</h3>
            <p class="text-sm leading-6 text-[#6b7280]">
              Base URL boleh diisi sebagai `http://192.168.1.55:8080/v1` atau `192.168.1.55:8080`. Backend akan menormalkan format endpoint chat completions.
            </p>
          </div>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
              <label class="mb-2 block text-sm font-medium text-[#374151]">Base URL</label>
              <input
                v-model="generalForm.ai_local_base_url"
                type="text"
                class="input-field"
                placeholder="http://192.168.1.55:8080/v1"
              >
              <p v-if="generalErrors.ai_local_base_url" class="mt-2 text-sm text-red-700">{{ generalErrors.ai_local_base_url }}</p>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Model</label>
              <input
                v-model="generalForm.ai_local_model"
                type="text"
                class="input-field"
                placeholder="model-ai-yulie.gguf"
              >
              <p v-if="generalErrors.ai_local_model" class="mt-2 text-sm text-red-700">{{ generalErrors.ai_local_model }}</p>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-[#374151]">Timeout (detik)</label>
              <input
                v-model.number="generalForm.ai_local_timeout"
                type="number"
                min="5"
                max="300"
                class="input-field"
                placeholder="60"
              >
              <p v-if="generalErrors.ai_local_timeout" class="mt-2 text-sm text-red-700">{{ generalErrors.ai_local_timeout }}</p>
            </div>

            <div class="md:col-span-2">
              <label class="mb-2 block text-sm font-medium text-[#374151]">API key lokal</label>
              <input
                v-model="generalForm.ai_local_api_key"
                type="password"
                class="input-field"
                placeholder="Isi hanya jika ingin mengganti API key"
                autocomplete="new-password"
              >
              <p class="mt-2 text-xs leading-5 text-[#6b7280]">
                {{ generalForm.has_ai_local_api_key ? 'API key lokal sudah tersimpan di backend.' : 'Belum ada API key lokal yang tersimpan.' }}
              </p>
              <label class="mt-3 inline-flex items-center gap-2 text-sm text-[#6b7280]">
                <input v-model="generalForm.clear_ai_local_api_key" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                Hapus API key lokal yang tersimpan
              </label>
              <p v-if="generalErrors.ai_local_api_key" class="mt-2 text-sm text-red-700">{{ generalErrors.ai_local_api_key }}</p>
            </div>
          </div>
        </section>

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
import { computed } from 'vue';
import { injectKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const {
  loading,
  error,
  catalogs,
  savingGeneral,
  generalErrors,
  generalForm,
  saveGeneral,
} = injectKnowledgeAdminWorkspace();

const providerOptions = computed(() => (
  catalogs.value?.ai_providers?.length
    ? catalogs.value.ai_providers
    : [
        { value: 'zai', label: 'GLM / Z.ai' },
        { value: 'local', label: 'AI Local' },
      ]
));
</script>
