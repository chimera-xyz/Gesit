<template>
  <div class="relative flex h-full min-h-0 flex-col gap-3 overflow-hidden pb-2">
    <div v-if="isChatRoute" class="relative min-h-0 flex-1 overflow-hidden">
      <div
        class="grid h-full min-h-0 gap-4 overflow-hidden"
        :class="chatNavigationCollapsed ? 'xl:grid-cols-[minmax(0,1fr)]' : 'xl:grid-cols-[290px_minmax(0,1fr)]'"
      >
        <aside v-if="!chatNavigationCollapsed" class="min-h-0 overflow-hidden">
          <article class="flex h-full min-h-0 flex-col overflow-hidden rounded-[24px] border border-[#e8dcc9] bg-white shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
            <div class="flex items-center justify-between gap-3 border-b border-[#f0e6d7] px-4 py-4">
              <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">AI Chat Assistant</p>
                <p class="mt-1 truncate text-sm font-semibold text-[#111827]">Riwayat obrolan</p>
              </div>
              <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#efe5d7] bg-white text-[#6b7280] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="chatNavigationCollapsed = true"
              >
                <span class="sr-only">Sembunyikan riwayat</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19.5 12h-15M13.5 6 7.5 12l6 6" />
                </svg>
              </button>
            </div>

            <div class="space-y-2 border-b border-[#f0e6d7] p-4">
              <button
                type="button"
                class="inline-flex w-full items-center gap-3 rounded-[18px] bg-[#111827] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#1f2937]"
                @click="startNewConversation"
              >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5.25v13.5M5.25 12h13.5" />
                </svg>
                <span class="truncate">Obrolan baru</span>
              </button>

              <button
                type="button"
                class="inline-flex w-full items-center gap-3 rounded-[18px] border border-[#efe5d7] bg-[#fcfbf8] px-4 py-3 text-sm font-medium text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="openSearchModal"
              >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
                </svg>
                <span class="truncate">Cari obrolan</span>
              </button>

              <router-link
                :to="{ name: 'knowledge-hub-documents' }"
                class="inline-flex w-full items-center gap-3 rounded-[18px] border border-[#efe5d7] bg-white px-4 py-3 text-sm font-medium text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
              >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7.5A1.5 1.5 0 0 1 4.5 6H10l1.5 1.5h8A1.5 1.5 0 0 1 21 9v8.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-10Z" />
                </svg>
                <span class="truncate">Smart Document Hub</span>
              </router-link>
            </div>

            <div class="min-h-0 flex-1 overflow-hidden px-3 pb-3 pt-3">
              <div class="flex items-center justify-between gap-2 px-2 pb-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#9ca3af]">Riwayat</p>
                <span v-if="conversationSummaries.length" class="text-xs text-[#9ca3af]">{{ conversationSummaries.length }}</span>
              </div>

              <div v-if="historyLoading && !conversationSummaries.length" class="flex h-full min-h-[12rem] items-center justify-center px-4 text-sm text-[#6b7280]">
                <div class="flex items-center gap-3">
                  <div class="h-4 w-4 animate-spin rounded-full border-2 border-[#f3e4bf] border-t-[#9b6b17]"></div>
                  <p>Memuat riwayat...</p>
                </div>
              </div>

              <div v-else-if="!conversationSummaries.length" class="flex h-full min-h-[12rem] items-center justify-center px-5 text-center">
                <div>
                  <p class="text-sm font-medium text-[#374151]">Belum ada riwayat obrolan.</p>
                  <p class="mt-2 text-sm text-[#9ca3af]">Mulai pertanyaan baru, lalu room akan tersimpan di sini.</p>
                </div>
              </div>

              <div v-else class="h-full overflow-y-auto pr-1">
                <div class="space-y-1 pb-2">
                  <div
                    v-for="conversation in conversationSummaries"
                    :key="conversation.id"
                    class="group relative"
                    data-history-menu-root
                  >
                    <button
                      type="button"
                      class="w-full rounded-[16px] px-3 py-3 pr-12 text-left text-sm transition"
                      :class="activeConversationId === conversation.id ? 'bg-[#f3f4f6] text-[#111827]' : 'text-[#4b5563] hover:bg-[#fcfbf8] hover:text-[#111827]'"
                      @click="selectConversation(conversation.id)"
                    >
                      <span class="block truncate font-medium">{{ conversation.title }}</span>
                    </button>

                    <button
                      type="button"
                      class="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full border border-transparent bg-white/90 text-[#6b7280] opacity-0 shadow-sm transition group-hover:opacity-100 hover:border-[#d8bc84] hover:text-[#8f6115]"
                      :class="historyMenuConversationId === conversation.id || activeConversationId === conversation.id ? 'opacity-100' : ''"
                      @click.stop="toggleHistoryMenu(conversation.id)"
                    >
                      <span class="sr-only">Aksi obrolan</span>
                      <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                        <circle cx="5" cy="12" r="1.8" />
                        <circle cx="12" cy="12" r="1.8" />
                        <circle cx="19" cy="12" r="1.8" />
                      </svg>
                    </button>

                    <div
                      v-if="historyMenuConversationId === conversation.id"
                      class="absolute right-2 top-[calc(100%-0.2rem)] z-20 w-44 rounded-[18px] border border-[#e8dcc9] bg-white p-2 shadow-[0_18px_40px_rgba(17,24,39,0.12)]"
                    >
                      <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-[14px] px-3 py-2.5 text-left text-sm font-medium text-[#374151] transition hover:bg-[#fcfbf8] hover:text-[#8f6115]"
                        @click.stop="openRenameDialog(conversation)"
                      >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.862 4.487Z" />
                        </svg>
                        <span>Ganti nama</span>
                      </button>

                      <button
                        type="button"
                        class="mt-1 flex w-full items-center gap-3 rounded-[14px] px-3 py-2.5 text-left text-sm font-medium text-[#b42318] transition hover:bg-[#fff5f5]"
                        @click.stop="openDeleteDialog(conversation)"
                      >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673A2.25 2.25 0 0 1 15.916 21.75H8.084a2.25 2.25 0 0 1-2.245-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916A2.25 2.25 0 0 0 13.5 2.25h-3a2.25 2.25 0 0 0-2.25 2.25v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                        <span>Hapus</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </article>
        </aside>

        <button
          v-else
          type="button"
          class="absolute left-0 top-0 z-20 inline-flex h-11 items-center justify-center gap-2 rounded-full border border-[#efe5d7] bg-white/95 px-4 text-sm font-medium text-[#6b7280] shadow-[0_10px_24px_rgba(41,28,9,0.08)] backdrop-blur transition hover:border-[#d8bc84] hover:text-[#8f6115]"
          @click="chatNavigationCollapsed = false"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4.5 12h15M10.5 18l6-6-6-6" />
          </svg>
          <span class="hidden sm:inline">Riwayat</span>
        </button>

        <div class="min-h-0 overflow-hidden">
          <router-view />
        </div>
      </div>

      <transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="showSearchModal"
          class="fixed inset-0 z-40 flex items-start justify-center bg-[#111827]/20 px-4 py-12 sm:py-16"
          @click.self="closeSearchModal"
        >
          <div class="flex max-h-[min(80vh,48rem)] w-full max-w-3xl flex-col overflow-hidden rounded-[28px] border border-[#e8dcc9] bg-white shadow-[0_30px_60px_rgba(17,24,39,0.18)]">
            <div class="flex items-center gap-3 border-b border-[#f0e6d7] px-6 py-5">
              <svg class="h-5 w-5 shrink-0 text-[#9ca3af]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
              </svg>
              <input
                ref="searchInputRef"
                v-model="searchQuery"
                type="text"
                class="w-full border-0 bg-transparent p-0 text-base text-[#111827] outline-none placeholder:text-[#9ca3af] focus:ring-0"
                placeholder="Cari obrolan..."
              >
              <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#efe5d7] bg-white text-[#6b7280] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="closeSearchModal"
              >
                <span class="sr-only">Tutup pencarian obrolan</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m6.75 6.75 10.5 10.5m0-10.5-10.5 10.5" />
                </svg>
              </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
              <div v-if="searchLoading" class="flex items-center gap-3 rounded-[20px] bg-[#fcfbf8] px-5 py-4 text-sm text-[#6b7280]">
                <div class="h-4 w-4 animate-spin rounded-full border-2 border-[#f3e4bf] border-t-[#9b6b17]"></div>
                <p>Mencari obrolan...</p>
              </div>

              <div v-else-if="!searchResults.length" class="flex min-h-[16rem] items-center justify-center px-6 text-center">
                <div>
                  <p class="text-sm font-medium text-[#374151]">Obrolan tidak ditemukan.</p>
                  <p class="mt-2 text-sm text-[#9ca3af]">Coba kata kunci lain dari judul, pertanyaan, atau jawaban sebelumnya.</p>
                </div>
              </div>

              <div v-else class="space-y-1">
                <button
                  v-for="conversation in searchResults"
                  :key="`search-${conversation.id}`"
                  type="button"
                  class="w-full rounded-[18px] px-4 py-3 text-left transition"
                  :class="activeConversationId === conversation.id ? 'bg-[#f3f4f6]' : 'hover:bg-[#fcfbf8]'"
                  @click="selectConversation(conversation.id, { closeModal: true })"
                >
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-[#111827]">{{ conversation.title }}</p>
                      <p v-if="conversation.preview" class="mt-1 truncate text-sm text-[#6b7280]">{{ conversation.preview }}</p>
                    </div>
                    <span class="shrink-0 pt-0.5 text-xs text-[#9ca3af]">
                      {{ formatConversationDate(conversation.last_message_at || conversation.updated_at) }}
                    </span>
                  </div>
                </button>
              </div>
            </div>
          </div>
        </div>
      </transition>

      <transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="renameDialogConversationId !== null"
          class="fixed inset-0 z-40 flex items-center justify-center bg-[#111827]/20 px-4 py-8"
          @click.self="closeRenameDialog"
        >
          <div class="w-full max-w-md rounded-[28px] border border-[#e8dcc9] bg-white p-6 shadow-[0_30px_60px_rgba(17,24,39,0.18)]">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Riwayat obrolan</p>
                <h2 class="mt-2 text-xl font-semibold text-[#111827]">Ganti nama obrolan</h2>
              </div>
              <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#efe5d7] bg-white text-[#6b7280] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="closeRenameDialog"
              >
                <span class="sr-only">Tutup dialog ganti nama</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m6.75 6.75 10.5 10.5m0-10.5-10.5 10.5" />
                </svg>
              </button>
            </div>

            <div class="mt-6">
              <label class="block text-sm font-medium text-[#374151]" for="rename-conversation-input">Nama obrolan</label>
              <input
                id="rename-conversation-input"
                ref="renameInputRef"
                v-model="renameTitle"
                type="text"
                maxlength="160"
                class="mt-2 w-full rounded-[18px] border border-[#e8dcc9] bg-[#fcfbf8] px-4 py-3 text-sm text-[#111827] outline-none transition focus:border-[#d8bc84] focus:ring-0"
                placeholder="Masukkan nama obrolan"
                @keydown.enter.prevent="submitRenameConversation"
              >
              <p v-if="renameError" class="mt-2 text-sm text-[#b42318]">{{ renameError }}</p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
              <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-full border border-[#e8dcc9] bg-white px-5 text-sm font-medium text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="closeRenameDialog"
              >
                Batal
              </button>
              <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-full bg-[#111827] px-5 text-sm font-semibold text-white transition hover:bg-[#1f2937] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="renameSubmitting || renameTitle.trim() === ''"
                @click="submitRenameConversation"
              >
                {{ renameSubmitting ? 'Menyimpan...' : 'Simpan' }}
              </button>
            </div>
          </div>
        </div>
      </transition>

      <transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="deleteDialogConversationId !== null"
          class="fixed inset-0 z-40 flex items-center justify-center bg-[#111827]/20 px-4 py-8"
          @click.self="closeDeleteDialog"
        >
          <div class="w-full max-w-md rounded-[28px] border border-[#e8dcc9] bg-white p-6 shadow-[0_30px_60px_rgba(17,24,39,0.18)]">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Riwayat obrolan</p>
            <h2 class="mt-2 text-xl font-semibold text-[#111827]">Hapus obrolan ini?</h2>
            <p class="mt-3 text-sm leading-7 text-[#6b7280]">
              Riwayat <span class="font-medium text-[#111827]">{{ deleteConversationTitle }}</span> akan dihapus permanen.
            </p>
            <p v-if="deleteError" class="mt-3 text-sm text-[#b42318]">{{ deleteError }}</p>

            <div class="mt-6 flex justify-end gap-3">
              <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-full border border-[#e8dcc9] bg-white px-5 text-sm font-medium text-[#4b5563] transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                @click="closeDeleteDialog"
              >
                Batal
              </button>
              <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-full bg-[#b42318] px-5 text-sm font-semibold text-white transition hover:bg-[#912018] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="deleteSubmitting"
                @click="submitDeleteConversation"
              >
                {{ deleteSubmitting ? 'Menghapus...' : 'Hapus' }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </div>

    <div v-else class="min-h-0 flex-1 overflow-hidden">
      <router-view />
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { knowledgeHubWorkspaceKey, useKnowledgeHubWorkspace } from '../../composables/useKnowledgeHubWorkspace';

const router = useRouter();
const route = useRoute();
const workspace = useKnowledgeHubWorkspace();
const {
  ensureLoaded,
  ensureConversationHistoryLoaded,
  loadConversation,
  searchConversationHistory,
  renameConversation,
  deleteConversation,
  resetConversation,
  conversationSummaries,
  historyLoading,
  searchLoading,
  activeConversationId,
} = workspace;
const chatNavigationCollapsed = ref(false);
const showSearchModal = ref(false);
const historyMenuConversationId = ref(null);
const renameDialogConversationId = ref(null);
const renameTitle = ref('');
const renameError = ref('');
const renameSubmitting = ref(false);
const deleteDialogConversationId = ref(null);
const deleteError = ref('');
const deleteSubmitting = ref(false);
const searchQuery = ref('');
const searchResults = ref([]);
const searchInputRef = ref(null);
const renameInputRef = ref(null);
const chatNavigationStateStorageKey = 'knowledge-hub-chat-navigation-collapsed';
const isChatRoute = computed(() => route.name === 'knowledge-hub-chat');
const conversationQueryId = computed(() => {
  const rawValue = Array.isArray(route.query.conversation) ? route.query.conversation[0] : route.query.conversation;
  const parsed = Number.parseInt(`${rawValue ?? ''}`, 10);

  return Number.isNaN(parsed) ? null : parsed;
});
const deleteConversationTitle = computed(() => {
  if (deleteDialogConversationId.value === null) {
    return '';
  }

  return getConversationById(deleteDialogConversationId.value)?.title || 'obrolan ini';
});
const hasOverlayOpen = computed(() => (
  showSearchModal.value
  || renameDialogConversationId.value !== null
  || deleteDialogConversationId.value !== null
));
let searchDebounceId = null;

provide(knowledgeHubWorkspaceKey, workspace);

const getConversationById = (conversationId) => (
  conversationSummaries.value.find((conversation) => conversation.id === conversationId)
  || searchResults.value.find((conversation) => conversation.id === conversationId)
  || null
);

const formatConversationDate = (value) => {
  if (!value) {
    return '';
  }

  try {
    return new Intl.DateTimeFormat('id-ID', {
      day: 'numeric',
      month: 'short',
    }).format(new Date(value));
  } catch (error) {
    return '';
  }
};

const replaceConversationInSearchResults = (conversation) => {
  searchResults.value = searchResults.value.map((item) => (
    item.id === conversation.id
      ? { ...item, ...conversation }
      : item
  ));
};

const removeConversationFromSearchResults = (conversationId) => {
  searchResults.value = searchResults.value.filter((item) => item.id !== conversationId);
};

const closeHistoryMenu = () => {
  historyMenuConversationId.value = null;
};

const toggleHistoryMenu = (conversationId) => {
  historyMenuConversationId.value = historyMenuConversationId.value === conversationId ? null : conversationId;
};

const syncSearchResults = async (query = '') => {
  try {
    searchResults.value = await searchConversationHistory(query);
  } catch (error) {
    searchResults.value = [];
  }
};

const closeSearchModal = () => {
  if (searchDebounceId) {
    window.clearTimeout(searchDebounceId);
    searchDebounceId = null;
  }

  showSearchModal.value = false;
  searchQuery.value = '';
  searchResults.value = conversationSummaries.value;
};

const openSearchModal = async () => {
  closeHistoryMenu();
  try {
    await ensureConversationHistoryLoaded();
  } catch (error) {
    return;
  }

  searchResults.value = conversationSummaries.value;
  showSearchModal.value = true;
  await nextTick();
  searchInputRef.value?.focus();
};

const selectConversation = async (conversationId, options = {}) => {
  const { closeModal = false } = options;
  const normalizedConversationId = Number.parseInt(`${conversationId}`, 10);

  if (Number.isNaN(normalizedConversationId)) {
    return;
  }

  closeHistoryMenu();
  try {
    await loadConversation(normalizedConversationId);
  } catch (error) {
    return;
  }

  await router.replace({
    name: 'knowledge-hub-chat',
    query: {
      conversation: String(normalizedConversationId),
    },
  });

  if (closeModal) {
    closeSearchModal();
  }

  if (typeof window !== 'undefined' && window.innerWidth < 1280) {
    chatNavigationCollapsed.value = true;
  }
};

const closeRenameDialog = () => {
  renameDialogConversationId.value = null;
  renameTitle.value = '';
  renameError.value = '';
  renameSubmitting.value = false;
};

const openRenameDialog = async (conversation) => {
  closeHistoryMenu();
  renameDialogConversationId.value = conversation.id;
  renameTitle.value = conversation.title;
  renameError.value = '';
  await nextTick();
  renameInputRef.value?.focus();
  renameInputRef.value?.select();
};

const submitRenameConversation = async () => {
  const conversationId = renameDialogConversationId.value;
  const title = renameTitle.value.trim();

  if (conversationId === null || title === '') {
    return;
  }

  renameSubmitting.value = true;
  renameError.value = '';

  try {
    const updatedConversation = await renameConversation(conversationId, title);

    if (updatedConversation) {
      replaceConversationInSearchResults(updatedConversation);
    }

    closeRenameDialog();
  } catch (error) {
    renameError.value = error.response?.data?.error || 'Nama obrolan gagal diperbarui.';
  } finally {
    renameSubmitting.value = false;
  }
};

const closeDeleteDialog = () => {
  deleteDialogConversationId.value = null;
  deleteError.value = '';
  deleteSubmitting.value = false;
};

const openDeleteDialog = (conversation) => {
  closeHistoryMenu();
  deleteDialogConversationId.value = conversation.id;
  deleteError.value = '';
};

const submitDeleteConversation = async () => {
  const conversationId = deleteDialogConversationId.value;

  if (conversationId === null) {
    return;
  }

  deleteSubmitting.value = true;
  deleteError.value = '';

  try {
    await deleteConversation(conversationId);
    removeConversationFromSearchResults(conversationId);

    if (conversationQueryId.value === conversationId) {
      await router.replace({ name: 'knowledge-hub-chat' });
    }

    closeDeleteDialog();
  } catch (error) {
    deleteError.value = error.response?.data?.error || 'Obrolan gagal dihapus.';
  } finally {
    deleteSubmitting.value = false;
  }
};

const startNewConversation = async () => {
  closeHistoryMenu();
  closeSearchModal();
  closeRenameDialog();
  closeDeleteDialog();
  resetConversation();

  if (conversationQueryId.value !== null) {
    await router.replace({ name: 'knowledge-hub-chat' });
  }

  if (typeof window !== 'undefined' && window.innerWidth < 1280) {
    chatNavigationCollapsed.value = true;
  }
};

const handleEscape = (event) => {
  if (event.key !== 'Escape') {
    return;
  }

  if (historyMenuConversationId.value !== null) {
    closeHistoryMenu();
    return;
  }

  if (deleteDialogConversationId.value !== null) {
    closeDeleteDialog();
    return;
  }

  if (renameDialogConversationId.value !== null) {
    closeRenameDialog();
    return;
  }

  if (showSearchModal.value) {
    closeSearchModal();
  }
};

const handleDocumentClick = (event) => {
  if (!event.target.closest('[data-history-menu-root]')) {
    closeHistoryMenu();
  }
};

onMounted(async () => {
  if (typeof window !== 'undefined') {
    chatNavigationCollapsed.value = window.localStorage.getItem(chatNavigationStateStorageKey) === 'true';
    window.addEventListener('keydown', handleEscape);
    window.addEventListener('click', handleDocumentClick);
  }

  await ensureLoaded();
});

onUnmounted(() => {
  if (searchDebounceId) {
    window.clearTimeout(searchDebounceId);
  }

  if (typeof document !== 'undefined') {
    document.body.style.overflow = '';
  }

  if (typeof window !== 'undefined') {
    window.removeEventListener('keydown', handleEscape);
    window.removeEventListener('click', handleDocumentClick);
  }
});

watch(chatNavigationCollapsed, (value) => {
  if (typeof window === 'undefined') {
    return;
  }

  window.localStorage.setItem(chatNavigationStateStorageKey, value ? 'true' : 'false');
});

watch(hasOverlayOpen, (value) => {
  if (typeof document !== 'undefined') {
    document.body.style.overflow = value ? 'hidden' : '';
  }
});

watch(showSearchModal, async (value) => {
  if (!value && searchDebounceId) {
    window.clearTimeout(searchDebounceId);
    searchDebounceId = null;
  }

  if (value) {
    await nextTick();
    searchInputRef.value?.focus();
  }
});

watch(renameDialogConversationId, async (value) => {
  if (value !== null) {
    await nextTick();
    renameInputRef.value?.focus();
    renameInputRef.value?.select();
  }
});

watch(searchQuery, (value) => {
  if (!showSearchModal.value) {
    return;
  }

  if (searchDebounceId) {
    window.clearTimeout(searchDebounceId);
  }

  searchDebounceId = window.setTimeout(() => {
    syncSearchResults(value);
  }, value.trim() === '' ? 0 : 220);
});

watch(conversationSummaries, (items) => {
  if (!showSearchModal.value || searchQuery.value.trim() !== '') {
    return;
  }

  searchResults.value = items;
});

watch(
  () => [isChatRoute.value, conversationQueryId.value],
  async ([chatRoute, conversationId], [previousChatRoute, previousConversationId] = [false, null]) => {
    if (!chatRoute) {
      return;
    }

    await ensureConversationHistoryLoaded();

    if (conversationId !== null) {
      if (conversationId !== activeConversationId.value) {
        try {
          await loadConversation(conversationId);
        } catch (error) {
          await router.replace({ name: 'knowledge-hub-chat' });
        }
      }

      return;
    }

    if (previousChatRoute && previousConversationId !== null) {
      resetConversation();
    }
  },
  { immediate: true },
);

watch(activeConversationId, async (conversationId) => {
  if (!isChatRoute.value) {
    return;
  }

  if (conversationId !== null && conversationId !== conversationQueryId.value) {
    await router.replace({
      name: 'knowledge-hub-chat',
      query: {
        conversation: String(conversationId),
      },
    });

    return;
  }

  if (conversationId === null && conversationQueryId.value !== null) {
    await router.replace({ name: 'knowledge-hub-chat' });
  }
});
</script>
