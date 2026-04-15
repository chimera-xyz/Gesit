<template>
  <div class="space-y-4">
    <div v-if="error" class="rounded-[18px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="rounded-[20px] border border-[#e8dcc9] bg-white px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm text-[#6b7280]">Memuat...</p>
    </section>

    <template v-else>
      <section class="rounded-[20px] border border-[#e8dcc9] bg-white p-5">
        <div class="grid gap-3 md:grid-cols-3">
          <div class="rounded-[16px] border border-[#f1e9dc] px-4 py-4">
            <p class="text-xs uppercase tracking-[0.14em] text-[#9ca3af]">General</p>
            <p class="mt-2 text-base font-semibold text-[#111827]">{{ general?.is_active ? 'Aktif' : 'Nonaktif' }}</p>
          </div>
          <div class="rounded-[16px] border border-[#f1e9dc] px-4 py-4">
            <p class="text-xs uppercase tracking-[0.14em] text-[#9ca3af]">Divisi</p>
            <p class="mt-2 text-2xl font-semibold text-[#111827]">{{ divisions.length }}</p>
          </div>
          <div class="rounded-[16px] border border-[#f1e9dc] px-4 py-4">
            <p class="text-xs uppercase tracking-[0.14em] text-[#9ca3af]">Dokumen</p>
            <p class="mt-2 text-2xl font-semibold text-[#111827]">{{ totalDocuments }}</p>
          </div>
        </div>

        <div class="mt-4 flex flex-col gap-4 border-t border-[#f1e9dc] pt-4 md:flex-row md:items-center md:justify-between">
          <p class="text-sm text-[#6b7280]">
            AI pakai urutan: <span class="font-medium text-[#111827]">General</span> -> <span class="font-medium text-[#111827]">Divisi</span> -> <span class="font-medium text-[#111827]">Dokumen</span>
          </p>

          <div class="flex flex-wrap gap-2">
            <router-link
              to="/settings/knowledge-ai/general"
              class="rounded-full border border-[#eadfcf] bg-white px-4 py-2 text-sm font-medium text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
            >
              Edit General
            </router-link>
            <router-link
              to="/settings/knowledge-ai/divisions"
              class="rounded-full border border-[#eadfcf] bg-white px-4 py-2 text-sm font-medium text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
            >
              Edit Divisi
            </router-link>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { injectKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const {
  loading,
  error,
  general,
  divisions,
  totalDocuments,
} = injectKnowledgeAdminWorkspace();
</script>
