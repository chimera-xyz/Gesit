<template>
  <div class="flex h-full min-h-0 flex-col gap-4 overflow-hidden">
    <div v-if="error" class="rounded-[24px] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="card px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm font-medium text-secondary-700">Memuat Smart Document Hub...</p>
    </section>

    <article
      v-else
      class="grid h-full min-h-[40rem] min-w-0 overflow-hidden rounded-[24px] border border-[#e8dcc9] bg-white shadow-[0_14px_30px_rgba(41,28,9,0.05)]"
      :class="documentsWorkspaceClass"
    >
      <aside v-if="!explorerCollapsed" class="flex min-h-0 flex-col border-b border-[#f0e6d7] bg-[#fcfbf8] xl:border-b-0 xl:border-r">
        <div class="border-b border-[#f0e6d7] px-5 py-5">
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Explorer</p>
          <h2 class="mt-2 text-lg font-semibold text-[#111827]">Smart Document Hub</h2>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-3 py-4">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-[18px] px-3 py-3 text-left text-sm font-medium transition"
            :class="selectedSpaceId === null ? 'bg-[#fff2d8] text-[#8f6115]' : 'text-[#4b5563] hover:bg-white'"
            @click="goToRoot"
          >
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-[14px] bg-white text-[#8f6115] shadow-sm">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7.5 12 4l9 3.5v9L12 20l-9-3.5v-9Z" />
              </svg>
            </span>
            Semua Dokumen
          </button>

          <div class="mt-4 space-y-1">
            <button
              v-for="space in spaces"
              :key="space.id"
              type="button"
              class="flex w-full items-start gap-3 rounded-[18px] px-3 py-3 text-left transition"
              :class="selectedSpaceId === space.id ? 'bg-[#fff2d8]' : 'hover:bg-white'"
              @click="goToSpace(space.id)"
            >
              <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[14px] bg-white text-[#8f6115] shadow-sm">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7.5A1.5 1.5 0 0 1 4.5 6H10l1.5 1.5h8A1.5 1.5 0 0 1 21 9v8.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-10Z" />
                </svg>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-[#111827]">{{ space.name }}</span>
                <span class="mt-1 block text-xs text-[#9ca3af]">{{ space.entry_count }} file</span>
              </span>
            </button>
          </div>
        </div>

        <div class="border-t border-[#f0e6d7] px-5 py-4 text-xs text-[#9ca3af]">
          {{ filteredEntries.length }} file tampil
        </div>
      </aside>

      <section
        class="flex min-h-0 flex-col border-b border-[#f0e6d7] xl:border-b-0"
        :class="selectedEntry ? 'xl:border-r' : ''"
      >
        <div class="border-b border-[#f0e6d7] px-6 py-5">
          <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-[#9ca3af]">
                <button type="button" class="transition hover:text-[#8f6115]" @click="goToRoot">All Files</button>
                <template v-if="currentSpace">
                  <span>/</span>
                  <button type="button" class="transition hover:text-[#8f6115]" @click="goToSpace(currentSpace.id)">
                    {{ currentSpace.name }}
                  </button>
                </template>
                <template v-if="currentSection">
                  <span>/</span>
                  <span class="text-[#111827]">{{ currentSection.name }}</span>
                </template>
              </nav>
              <h3 class="mt-2 text-xl font-semibold text-[#111827]">{{ currentDirectoryTitle }}</h3>
              <p class="mt-2 text-sm text-[#6b7280]">{{ currentDirectorySummary }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm">
              <div v-if="currentSpace" ref="createMenuRoot" class="relative">
                <button
                  type="button"
                  class="inline-flex items-center gap-2 rounded-full border border-[#efe5d7] bg-white px-3 py-2 text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                  @click.stop="toggleCreateMenu"
                >
                  <span>+ Buat</span>
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="1.8"
                      :d="showCreateMenu ? 'm18 15-6-6-6 6' : 'm6 9 6 6 6-6'"
                    />
                  </svg>
                </button>

                <div
                  v-if="showCreateMenu"
                  class="absolute right-0 top-[calc(100%+0.5rem)] z-20 min-w-[12rem] rounded-[20px] border border-[#efe5d7] bg-white p-2 shadow-[0_14px_30px_rgba(41,28,9,0.08)]"
                >
                  <button
                    v-if="!currentSection"
                    type="button"
                    class="flex w-full items-center rounded-[14px] px-3 py-2 text-left text-sm text-[#4b5563] transition hover:bg-[#fcfbf8] hover:text-[#8f6115]"
                    @click="openCreateFolderModal"
                  >
                    Folder Baru
                  </button>
                  <button
                    type="button"
                    class="flex w-full items-center rounded-[14px] px-3 py-2 text-left text-sm text-[#4b5563] transition hover:bg-[#fcfbf8] hover:text-[#8f6115]"
                    @click="openUploadModal"
                  >
                    Upload File
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="inline-flex items-center gap-2 rounded-full border border-[#efe5d7] bg-[#fffdf9] px-3 py-2 text-[#6b7280] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="explorerCollapsed = !explorerCollapsed"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    :d="explorerCollapsed ? 'M3 7.5A1.5 1.5 0 0 1 4.5 6H10l1.5 1.5h8A1.5 1.5 0 0 1 21 9v8.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-10Z' : 'M19.5 12h-15M13.5 6 7.5 12l6 6'"
                  />
                </svg>
                <span>{{ explorerCollapsed ? 'Explorer' : 'Sembunyikan' }}</span>
              </button>
              <label class="inline-flex items-center gap-2 rounded-full border border-[#efe5d7] bg-[#fffdf9] px-3 py-2 text-[#6b7280]">
                <input v-model="showBookmarksOnly" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                Bookmark
              </label>
            </div>
          </div>

          <div class="mt-4 grid gap-3 xl:grid-cols-[minmax(0,1fr)_180px_180px]">
            <input
              v-model="search"
              type="text"
              class="input-field"
              placeholder="Cari Dokumen"
            >
            <select v-model="selectedScope" class="select-field">
              <option value="all">Semua mode</option>
              <option v-for="scope in scopeOptions" :key="scope.value" :value="scope.value">{{ scope.label }}</option>
            </select>
            <select v-model="selectedType" class="select-field">
              <option value="all">Semua tipe</option>
              <option v-for="type in typeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select>
          </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto bg-[#faf7f1] px-5 py-5">
          <section v-if="folderCards.length" class="mb-8">
            <div class="mb-4">
              <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">{{ folderHeadingLabel }}</p>
              <h4 class="mt-1 text-lg font-semibold text-[#111827]">{{ folderHeadingTitle }}</h4>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              <button
                v-for="folder in folderCards"
                :key="folder.key"
                type="button"
                class="rounded-[22px] border border-[#eee3d4] bg-[#fcfbf8] px-4 py-4 text-left transition hover:border-[#d8bc84] hover:bg-white"
                @click="folder.action"
              >
                <div class="flex items-start gap-3">
                  <span class="inline-flex h-11 w-11 items-center justify-center rounded-[16px] bg-white text-[#8f6115] shadow-sm">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7.5A1.5 1.5 0 0 1 4.5 6H10l1.5 1.5h8A1.5 1.5 0 0 1 21 9v8.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-10Z" />
                    </svg>
                  </span>
                  <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-[#111827]">{{ folder.name }}</p>
                    <p class="mt-1 text-xs text-[#9ca3af]">{{ folder.caption }}</p>
                  </div>
                </div>
              </button>
            </div>
          </section>

          <section>
            <div class="mb-4 flex items-center justify-between gap-3">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Files</p>
                <h4 class="mt-1 text-lg font-semibold text-[#111827]">{{ currentEntries.length }} file</h4>
              </div>
            </div>

            <div v-if="currentEntries.length === 0" class="rounded-[24px] border border-dashed border-[#eadfcf] bg-[#fffdf9] px-5 py-12 text-center">
              <h4 class="text-base font-semibold text-[#111827]">Belum ada file di direktori ini</h4>
              <p class="mt-2 text-sm text-[#6b7280]">Ubah folder, upload file, atau pakai pencarian untuk melihat dokumen lain.</p>
            </div>

            <div v-else class="overflow-hidden rounded-[24px] border border-[#eee3d4]">
              <div class="grid grid-cols-[minmax(0,2.1fr)_1.3fr_0.9fr_1fr_44px] gap-4 border-b border-[#f3ecdf] bg-[#fcfbf8] px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-[#9ca3af]">
                <span>Name</span>
                <span>Path</span>
                <span>Type</span>
                <span>Updated</span>
                <span></span>
              </div>

              <div
                v-for="entry in currentEntries"
                :key="entry.id"
                class="grid w-full grid-cols-[minmax(0,2.1fr)_1.3fr_0.9fr_1fr_44px] gap-4 border-b border-[#f7f1e6] px-5 py-4 text-left transition last:border-b-0 hover:bg-[#fffdf9]"
                :class="selectedEntry?.id === entry.id ? 'bg-[#fff8ec]' : 'bg-white'"
                role="button"
                tabindex="0"
                @click="handleEntrySelection(entry)"
                @keydown.enter.prevent="handleEntrySelection(entry)"
                @keydown.space.prevent="handleEntrySelection(entry)"
              >
                <div class="flex min-w-0 items-center gap-3">
                  <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[16px]" :class="fileIconClass(entry)">
                    <svg v-if="entry.attachment_url" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 3.75h6l4.5 4.5v12A1.5 1.5 0 0 1 16.5 21.75h-9A1.5 1.5 0 0 1 6 20.25v-15A1.5 1.5 0 0 1 7.5 3.75Z" />
                    </svg>
                    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7.5A1.5 1.5 0 0 1 4.5 6H10l1.5 1.5h8A1.5 1.5 0 0 1 21 9v8.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-10Z" />
                    </svg>
                  </span>
                  <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-[#111827]">{{ entry.title }}</p>
                    <p class="mt-1 truncate text-xs text-[#9ca3af]">{{ entry.summary }}</p>
                  </div>
                </div>

                <div class="truncate text-sm text-[#6b7280]">{{ entry.path_label }}</div>
                <div class="text-sm text-[#6b7280]">{{ entry.type_label }}</div>
                <div class="text-sm text-[#6b7280]">{{ entry.effective_date_label }}</div>
                <button
                  type="button"
                  class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border transition"
                  :class="entry.is_bookmarked ? 'border-[#d8bc84] bg-[#fbf5ea] text-[#9b6b17]' : 'border-[#efe5d7] bg-white text-[#9ca3af] hover:border-[#d8bc84] hover:text-[#9b6b17]'"
                  @click.stop="toggleBookmark(entry)"
                >
                  <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M5.5 3.75A1.75 1.75 0 0 0 3.75 5.5v10.75l6.25-3.54 6.25 3.54V5.5A1.75 1.75 0 0 0 14.5 3.75h-9Z" />
                  </svg>
                </button>
              </div>
            </div>
          </section>
        </div>
      </section>

      <aside v-if="selectedEntry" class="relative min-h-0 overflow-hidden bg-[#faf7f1]">
        <div class="absolute right-4 top-4 z-10 flex items-center gap-2">
          <a
            v-if="previewActionUrl"
            :href="previewActionUrl"
            target="_blank"
            rel="noreferrer"
            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#efe5d7] bg-white/95 text-[#6b7280] shadow-sm transition hover:border-[#d8bc84] hover:text-[#8f6115]"
          >
            <span class="sr-only">Buka file</span>
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.5 4.5H19.5M19.5 4.5V10.5M19.5 4.5L10.5 13.5M9.75 6H7.5A2.25 2.25 0 0 0 5.25 8.25v8.25A2.25 2.25 0 0 0 7.5 18.75h8.25A2.25 2.25 0 0 0 18 16.5v-2.25" />
            </svg>
          </a>
          <button
            type="button"
            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#efe5d7] bg-white/95 text-[#6b7280] shadow-sm transition hover:border-[#d8bc84] hover:text-[#8f6115]"
            @click="clearSelectedEntry"
          >
            <span class="sr-only">Tutup preview</span>
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <iframe
          v-if="selectedPreviewUrl"
          :src="selectedPreviewUrl"
          class="h-full w-full bg-white"
          title="Knowledge preview"
        ></iframe>

        <div v-else-if="selectedEntry.body" class="h-full overflow-y-auto whitespace-pre-wrap bg-white px-6 py-6 text-sm leading-7 text-[#4b5563]">
          {{ selectedEntry.body }}
        </div>

        <div v-else class="h-full bg-white"></div>
      </aside>
    </article>

    <HubFolderModal
      :open="showFolderModal"
      :saving="creatingFolder"
      :path-label="currentPathLabel"
      :form="folderForm"
      :errors="folderErrors"
      @close="closeFolderModal"
      @submit="submitCreateFolder"
    />

    <HubUploadModal
      :open="showUploadModal"
      :saving="uploadingDocument"
      :path-label="currentPathLabel"
      :form="uploadForm"
      :errors="uploadErrors"
      :type-options="typeOptions"
      @close="closeUploadModal"
      @submit="submitUpload"
      @attachment-change="handleAttachmentChange"
    />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import HubFolderModal from './HubFolderModal.vue';
import HubUploadModal from './HubUploadModal.vue';
import { injectKnowledgeHubWorkspace } from '../../composables/useKnowledgeHubWorkspace';

const route = useRoute();
const {
  loading,
  error,
  search,
  selectedScope,
  selectedType,
  selectedSpaceId,
  selectedSectionId,
  selectedPreviewPage,
  showBookmarksOnly,
  spaces,
  currentSpace,
  currentSection,
  scopeOptions,
  typeOptions,
  filteredEntries,
  selectedEntry,
  creatingFolder,
  uploadingDocument,
  selectSpace,
  selectSection,
  openEntry,
  clearSelectedEntry,
  focusEntry,
  toggleBookmark,
  extractReferencePage,
  createFolder,
  uploadDocument,
} = injectKnowledgeHubWorkspace();

const explorerCollapsed = ref(true);
const showCreateMenu = ref(false);
const showFolderModal = ref(false);
const showUploadModal = ref(false);
const createMenuRoot = ref(null);
const folderErrors = ref({});
const uploadErrors = ref({});
const explorerStorageKey = 'knowledge-hub-documents-explorer-collapsed';

const folderForm = reactive({
  name: '',
  description: '',
});

const uploadForm = reactive({
  knowledge_section_id: null,
  title: '',
  summary: '',
  type: 'sop',
  attachment: null,
});

const currentPathLabel = computed(() => {
  if (currentSection.value) {
    return `${currentSpace.value?.name || ''} / ${currentSection.value.name}`;
  }

  if (currentSpace.value) {
    return `${currentSpace.value.name} / Root`;
  }

  return 'All Files';
});

const currentDirectoryTitle = computed(() => currentSection.value?.name || currentSpace.value?.name || 'All Files');

const currentDirectorySummary = computed(() => {
  if (currentSection.value) {
    return `Menampilkan file di folder ${currentSection.value.name}`;
  }

  if (currentSpace.value) {
    return `Folder divisi ${currentSpace.value.name}`;
  }

  return 'Explorer halaman ini menampilkan seluruh file yang bisa Anda akses untuk pencarian cepat.';
});

const folderHeadingLabel = computed(() => (!currentSpace.value ? 'Folders' : 'Subfolders'));
const folderHeadingTitle = computed(() => (!currentSpace.value ? 'Browse per divisi' : `Folder di ${currentSpace.value.name}`));

const folderCards = computed(() => {
  if (!currentSpace.value) {
    return spaces.value.map((space) => ({
      key: `space-${space.id}`,
      name: space.name,
      caption: `${space.entry_count} file`,
      action: () => goToSpace(space.id),
    }));
  }

  if (currentSection.value) {
    return [];
  }

  return (currentSpace.value.sections || []).map((section) => ({
    key: `section-${section.id}`,
    name: section.name,
    caption: `${section.entry_count} file`,
    action: () => goToSection(section.id),
  }));
});

const currentEntries = computed(() => filteredEntries.value);

const activePreviewPage = computed(() => {
  if (!selectedEntry.value) {
    return null;
  }

  if (selectedPreviewPage.value) {
    return selectedPreviewPage.value;
  }

  return null;
});

const selectedPreviewUrl = computed(() => {
  if (!selectedEntry.value?.attachment_url) {
    return null;
  }

  if (selectedEntry.value.attachment_mime === 'application/pdf' && activePreviewPage.value) {
    return `${selectedEntry.value.attachment_url}#page=${activePreviewPage.value}`;
  }

  return selectedEntry.value.attachment_url;
});

const previewActionUrl = computed(() => (
  selectedPreviewUrl.value
  || selectedEntry.value?.attachment_url
  || selectedEntry.value?.source_link
  || null
));

const documentsWorkspaceClass = computed(() => {
  if (selectedEntry.value) {
    return explorerCollapsed.value
      ? 'xl:grid-cols-[minmax(0,1fr)_380px]'
      : 'xl:grid-cols-[250px_minmax(0,1fr)_380px]';
  }

  return explorerCollapsed.value
    ? 'xl:grid-cols-[minmax(0,1fr)]'
    : 'xl:grid-cols-[250px_minmax(0,1fr)]';
});

const mapValidationErrors = (target, err) => {
  target.value = {};

  Object.entries(err.response?.data?.errors || {}).forEach(([key, value]) => {
    target.value[key] = Array.isArray(value) ? value[0] : value;
  });
};

const resetFolderForm = () => {
  folderForm.name = '';
  folderForm.description = '';
  folderErrors.value = {};
};

const resetUploadForm = () => {
  uploadForm.knowledge_section_id = selectedSectionId.value || null;
  uploadForm.title = '';
  uploadForm.summary = '';
  uploadForm.type = 'sop';
  uploadForm.attachment = null;
  uploadErrors.value = {};
};

const inferTitleFromFile = (file) => {
  if (!file?.name) {
    return '';
  }

  return file.name.replace(/\.[^/.]+$/, '').trim();
};

const fileIconClass = (entry) => {
  if (entry.attachment_mime === 'application/pdf') {
    return 'bg-red-50 text-red-600';
  }

  if (entry.attachment_previewable) {
    return 'bg-blue-50 text-blue-600';
  }

  return 'bg-[#fbf5ea] text-[#8f6115]';
};

const goToRoot = () => {
  selectSpace(null);
};

const goToSpace = (spaceId) => {
  selectSpace(spaceId);
};

const goToSection = (sectionId) => {
  selectSection(sectionId);
};

const handleEntrySelection = (entry) => {
  if (selectedEntry.value?.id === entry.id) {
    clearSelectedEntry();
    return;
  }

  openEntry(entry);
};

const openCreateFolderModal = () => {
  if (!currentSpace.value || currentSection.value) {
    return;
  }

  showCreateMenu.value = false;
  resetFolderForm();
  showFolderModal.value = true;
};

const closeFolderModal = () => {
  showFolderModal.value = false;
  resetFolderForm();
};

const submitCreateFolder = async () => {
  if (!currentSpace.value) {
    return;
  }

  try {
    await createFolder(currentSpace.value.id, {
      name: folderForm.name,
      description: folderForm.description,
    });
    closeFolderModal();
  } catch (err) {
    if (err.response?.status === 422) {
      mapValidationErrors(folderErrors, err);
    }
  }
};

const openUploadModal = () => {
  if (!currentSpace.value) {
    return;
  }

  showCreateMenu.value = false;
  resetUploadForm();
  showUploadModal.value = true;
};

const closeUploadModal = () => {
  showUploadModal.value = false;
  resetUploadForm();
};

const handleAttachmentChange = (event) => {
  const file = event.target.files?.[0] || null;
  uploadForm.attachment = file;

  if (!uploadForm.title.trim() && file) {
    uploadForm.title = inferTitleFromFile(file);
  }
};

const submitUpload = async () => {
  if (!currentSpace.value) {
    return;
  }

  try {
    await uploadDocument(currentSpace.value.id, {
      knowledge_section_id: uploadForm.knowledge_section_id,
      title: uploadForm.title,
      summary: uploadForm.summary,
      type: uploadForm.type,
      attachment: uploadForm.attachment,
    });
    closeUploadModal();
  } catch (err) {
    if (err.response?.status === 422) {
      mapValidationErrors(uploadErrors, err);
    }
  }
};

const toggleCreateMenu = () => {
  if (!currentSpace.value) {
    return;
  }

  showCreateMenu.value = !showCreateMenu.value;
};

const closeCreateMenu = () => {
  showCreateMenu.value = false;
};

const handleCreateMenuClickOutside = (event) => {
  if (!showCreateMenu.value || !createMenuRoot.value) {
    return;
  }

  if (!createMenuRoot.value.contains(event.target)) {
    closeCreateMenu();
  }
};

onMounted(() => {
  if (typeof window === 'undefined') {
    return;
  }

  const storedValue = window.localStorage.getItem(explorerStorageKey);
  explorerCollapsed.value = storedValue === null ? true : storedValue === 'true';
  window.addEventListener('click', handleCreateMenuClickOutside);
});

onBeforeUnmount(() => {
  if (typeof window === 'undefined') {
    return;
  }

  window.removeEventListener('click', handleCreateMenuClickOutside);
});

watch(explorerCollapsed, (value) => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(explorerStorageKey, value ? 'true' : 'false');
});

watch([selectedSpaceId, selectedSectionId], () => {
  closeCreateMenu();
});

watch(
  [() => route.query.entry, () => route.query.page, loading],
  ([entryId, page, isLoading]) => {
    if (isLoading) {
      return;
    }

    const parsedId = Number.parseInt(String(entryId || ''), 10);

    if (!Number.isInteger(parsedId) || parsedId <= 0) {
      return;
    }

    const parsedPage = Number.parseInt(String(page || ''), 10);

    focusEntry(parsedId, {
      previewPage: Number.isInteger(parsedPage) && parsedPage > 0 ? parsedPage : null,
    });
  },
  { immediate: true },
);

watch(selectedEntry, (entry) => {
  if (!entry || selectedPreviewPage.value !== null) {
    return;
  }

  if (entry.attachment_mime === 'application/pdf' && route.query.entry && String(route.query.entry) === String(entry.id)) {
    selectedPreviewPage.value = extractReferencePage(entry.reference_notes);
  }
});
</script>
