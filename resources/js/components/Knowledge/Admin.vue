<template>
  <div class="space-y-4 pb-8">
    <section class="border-b border-[#eadfcf] pb-4">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-[#111827]">AI Knowledge Settings</h1>
        </div>

        <div class="flex flex-wrap gap-3 text-sm text-[#6b7280]">
          <span>{{ general?.is_active ? 'General aktif' : 'General nonaktif' }}</span>
          <span>{{ divisions.length }} divisi</span>
          <span>{{ totalDocuments }} dokumen</span>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <router-link
          v-for="item in adminMenus"
          :key="item.routeName"
          :to="{ name: item.routeName }"
          class="rounded-full border px-4 py-2 text-sm font-medium transition"
          :class="route.name === item.routeName ? 'border-[#cfa65a] bg-[#fff6e8] text-[#8f6115]' : 'border-[#eadfcf] bg-white text-[#4b5563] hover:border-[#d8bc84] hover:text-[#8f6115]'"
        >
          {{ item.label }}
        </router-link>
      </div>
    </section>

    <router-view />
  </div>
</template>

<script setup>
import { onMounted, provide } from 'vue';
import { useRoute } from 'vue-router';
import { knowledgeAdminWorkspaceKey, useKnowledgeAdminWorkspace } from '../../composables/useKnowledgeAdminWorkspace';

const route = useRoute();
const workspace = useKnowledgeAdminWorkspace();
const {
  general,
  divisions,
  totalDocuments,
  ensureLoaded,
} = workspace;

const adminMenus = [
  {
    routeName: 'knowledge-admin-overview',
    label: 'Overview',
  },
  {
    routeName: 'knowledge-admin-general',
    label: 'General',
  },
  {
    routeName: 'knowledge-admin-divisions',
    label: 'Divisi',
  },
];

provide(knowledgeAdminWorkspaceKey, workspace);

onMounted(() => {
  ensureLoaded();
});
</script>
