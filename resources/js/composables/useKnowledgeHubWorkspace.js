import { computed, inject, ref, watch } from 'vue';
import { useKnowledgeHubStore } from '../stores/knowledgeHub';

const defaultAssistantMessage = () => ({
  id: 1,
  role: 'assistant',
  content: 'Tanyakan SOP internal, workflow operasional, panduan aplikasi, atau domain sekuritas. Jika konteks tidak cukup, saya akan menjawab data belum tersedia.',
  scopeLabel: 'Guarded Mode',
  sources: [],
});

export const knowledgeHubWorkspaceKey = Symbol('knowledge-hub-workspace');

export const useKnowledgeHubWorkspace = () => {
  const knowledgeStore = useKnowledgeHubStore();
  const starterMessage = defaultAssistantMessage();
  const starterMessageContent = starterMessage.content;
  const normalizeMessages = (items = []) => items.map((message) => ({
    ...message,
    sources: message.sources || [],
  }));
  const withoutStarterMessage = (items = []) => normalizeMessages(items).filter((message) => !(
    message.role === 'assistant' && message.content === starterMessageContent
  ));
  const initialMessages = withoutStarterMessage(knowledgeStore.conversation || []);

  const loading = ref(true);
  const chatLoading = ref(false);
  const historyLoading = ref(false);
  const searchLoading = ref(false);
  const creatingFolder = ref(false);
  const uploadingDocument = ref(false);
  const error = ref('');
  const search = ref('');
  const selectedScope = ref('all');
  const selectedType = ref('all');
  const selectedSpaceId = ref(null);
  const selectedSectionId = ref(null);
  const selectedEntryId = ref(null);
  const selectedPreviewPage = ref(null);
  const showBookmarksOnly = ref(false);
  const chatInput = ref('');
  const hasLoaded = ref(false);
  const messages = ref(initialMessages.length > 0 ? initialMessages : [starterMessage]);

  const spaces = computed(() => knowledgeStore.spaces);
  const entries = computed(() => knowledgeStore.entries);
  const conversationSummaries = computed(() => knowledgeStore.conversationSummaries || []);
  const activeConversationId = computed(() => knowledgeStore.activeConversationId);
  const activeConversationSummary = computed(() => (
    conversationSummaries.value.find((conversation) => conversation.id === activeConversationId.value) || null
  ));
  const suggestedQuestions = computed(() => knowledgeStore.hub.suggestedQuestions || []);
  const typeOptions = computed(() => (knowledgeStore.hub.filters?.types || []).filter((item) => item.value !== 'all'));
  const scopeOptions = computed(() => (knowledgeStore.hub.filters?.scopes || []).filter((item) => item.value !== 'all'));
  const currentSpace = computed(() => spaces.value.find((space) => space.id === selectedSpaceId.value) || null);
  const currentSection = computed(() => currentSpace.value?.sections?.find((section) => section.id === selectedSectionId.value) || null);
  const defaultSectionId = computed(() => currentSpace.value?.default_section_id || null);

  const sectionOptions = computed(() => {
    if (selectedSpaceId.value === null) {
      return [];
    }

    return spaces.value.find((space) => space.id === selectedSpaceId.value)?.sections || [];
  });

  const filteredEntries = computed(() => {
    const keyword = search.value.trim().toLowerCase();

    return entries.value.filter((entry) => {
      const haystack = [
        entry.title,
        entry.summary,
        entry.space_name,
        entry.section_name,
        ...(entry.tags || []),
      ].join(' ').toLowerCase();

      const matchesKeyword = keyword === '' || haystack.includes(keyword);
      const matchesScope = selectedScope.value === 'all' || entry.scope === selectedScope.value;
      const matchesType = selectedType.value === 'all' || entry.type === selectedType.value;
      const matchesDirectory = selectedSpaceId.value === null
        ? true
        : selectedSectionId.value !== null
          ? entry.section_id === selectedSectionId.value
          : entry.space_id === selectedSpaceId.value && entry.section_id === defaultSectionId.value;
      const matchesBookmark = !showBookmarksOnly.value || entry.is_bookmarked;

      return matchesKeyword && matchesScope && matchesType && matchesDirectory && matchesBookmark;
    });
  });

  const selectedEntry = computed(() => {
    if (!selectedEntryId.value) {
      return null;
    }

    return entries.value.find((entry) => entry.id === selectedEntryId.value) || null;
  });

  const bookmarkCount = computed(() => entries.value.filter((entry) => entry.is_bookmarked).length);
  const visibleMessages = computed(() => messages.value.filter((message, index) => {
    if (index !== 0) {
      return true;
    }

    return !(message.role === 'assistant' && message.content === starterMessageContent);
  }));
  const hasConversation = computed(() => visibleMessages.value.length > 0);

  const applyConversationMessages = (items = [], options = {}) => {
    const { includeStarter = false } = options;
    const normalized = includeStarter ? normalizeMessages(items) : withoutStarterMessage(items);

    messages.value = normalized.length > 0 ? normalized : [starterMessage];
  };

  const clearError = () => {
    error.value = '';
  };

  const extractReferencePage = (referenceNotes = '') => {
    const normalized = String(referenceNotes || '').trim();

    if (normalized === '') {
      return null;
    }

    const explicitMatch = normalized.match(/(?:halaman|page|hlm\.?|p\.)\s*(\d+)/i);

    if (explicitMatch) {
      return Number.parseInt(explicitMatch[1], 10) || null;
    }

    const fallbackMatch = normalized.match(/\b(\d+)\b/);

    return fallbackMatch ? (Number.parseInt(fallbackMatch[1], 10) || null) : null;
  };

  const getEntryById = (entryId) => entries.value.find((item) => item.id === entryId) || null;

  const resetConversation = () => {
    error.value = '';
    chatInput.value = '';
    knowledgeStore.resetConversation();
    messages.value = [starterMessage];
  };

  const ensureConversationHistoryLoaded = async (options = {}) => {
    if (knowledgeStore.conversationsLoaded && !options.force) {
      return conversationSummaries.value;
    }

    historyLoading.value = true;

    try {
      const response = await knowledgeStore.fetchConversations();
      return response.conversations || [];
    } catch (err) {
      error.value = err.response?.data?.error || 'Riwayat obrolan gagal dimuat.';
      throw err;
    } finally {
      historyLoading.value = false;
    }
  };

  const loadConversation = async (conversationId, options = {}) => {
    const { force = false } = options;

    if (!conversationId) {
      resetConversation();
      return null;
    }

    clearError();
    historyLoading.value = true;

    try {
      const response = await knowledgeStore.fetchConversation(conversationId, { force });
      applyConversationMessages(response.messages || []);
      return response.conversation || null;
    } catch (err) {
      error.value = err.response?.data?.error || 'Obrolan gagal dimuat.';
      throw err;
    } finally {
      historyLoading.value = false;
    }
  };

  const searchConversationHistory = async (query) => {
    const keyword = String(query || '').trim();

    if (keyword === '') {
      await ensureConversationHistoryLoaded();
      return conversationSummaries.value;
    }

    searchLoading.value = true;

    try {
      const response = await knowledgeStore.searchConversations(keyword);
      return response.conversations || [];
    } catch (err) {
      error.value = err.response?.data?.error || 'Pencarian obrolan gagal dijalankan.';
      throw err;
    } finally {
      searchLoading.value = false;
    }
  };

  const renameConversation = async (conversationId, title) => {
    clearError();

    try {
      const response = await knowledgeStore.renameConversation(conversationId, title);
      return response.conversation || null;
    } catch (err) {
      error.value = err.response?.data?.error || 'Nama obrolan gagal diperbarui.';
      throw err;
    }
  };

  const deleteConversation = async (conversationId) => {
    clearError();

    try {
      const response = await knowledgeStore.deleteConversation(conversationId);
      return response;
    } catch (err) {
      error.value = err.response?.data?.error || 'Obrolan gagal dihapus.';
      throw err;
    }
  };

  const selectSpace = (spaceId, options = {}) => {
    const { resetEntry = true } = options;
    selectedSpaceId.value = spaceId;
    selectedSectionId.value = null;

    if (resetEntry) {
      selectedEntryId.value = null;
      selectedPreviewPage.value = null;
    }
  };

  const selectSection = (sectionId, options = {}) => {
    const { resetEntry = true } = options;

    selectedSectionId.value = sectionId;

    if (sectionId !== null) {
      const matchingEntry = entries.value.find((entry) => entry.section_id === sectionId);

      if (matchingEntry) {
        selectedSpaceId.value = matchingEntry.space_id;
      }
    }

    if (resetEntry) {
      selectedEntryId.value = null;
      selectedPreviewPage.value = null;
    }
  };

  const openEntry = (entry, options = {}) => {
    const { previewPage = null } = options;
    selectedEntryId.value = entry.id;
    selectedPreviewPage.value = previewPage;
  };

  const clearSelectedEntry = () => {
    selectedEntryId.value = null;
    selectedPreviewPage.value = null;
  };

  const focusEntry = (entryId, options = {}) => {
    const { previewPage = null, fromReference = false } = options;
    const entry = getEntryById(entryId);

    if (!entry) {
      return null;
    }

    selectedSpaceId.value = entry.space_id;
    selectedSectionId.value = entry.section_is_default ? null : entry.section_id;
    selectedEntryId.value = entry.id;
    selectedPreviewPage.value = previewPage ?? (fromReference ? extractReferencePage(entry.reference_notes) : null);

    return entry;
  };

  const openSourceFromChat = (entryId) => focusEntry(entryId, { fromReference: true });

  const toggleBookmark = async (entry) => {
    clearError();

    try {
      await knowledgeStore.toggleBookmark(entry.id);
    } catch (err) {
      error.value = err.response?.data?.error || 'Bookmark gagal diperbarui.';
      throw err;
    }
  };

  const createFolder = async (spaceId, payload) => {
    clearError();
    creatingFolder.value = true;

    try {
      const response = await knowledgeStore.createHubFolder(spaceId, payload);
      await knowledgeStore.fetchHub();
      selectSpace(spaceId, { resetEntry: true });

      if (response.folder?.id) {
        selectSection(response.folder.id);
      }

      return response.folder || null;
    } catch (err) {
      if (err.response?.status !== 422) {
        error.value = err.response?.data?.error || 'Folder gagal dibuat.';
      }

      throw err;
    } finally {
      creatingFolder.value = false;
    }
  };

  const uploadDocument = async (spaceId, payload) => {
    clearError();
    uploadingDocument.value = true;

    try {
      const response = await knowledgeStore.uploadHubEntry(spaceId, payload);
      await knowledgeStore.fetchHub();

      if (response.document?.id) {
        focusEntry(response.document.id);
      }

      return response.document || null;
    } catch (err) {
      if (err.response?.status !== 422) {
        error.value = err.response?.data?.error || 'Upload dokumen gagal.';
      }

      throw err;
    } finally {
      uploadingDocument.value = false;
    }
  };

  const formatScopeLabel = (scope) => {
    if (scope === 'internal') {
      return 'Internal';
    }

    if (scope === 'securities_domain') {
      return 'Domain Sekuritas';
    }

    return 'Di luar scope';
  };

  const submitQuestion = async (presetQuestion = '') => {
    const question = (presetQuestion || chatInput.value).trim();

    if (question === '') {
      return;
    }

    error.value = '';
    const optimisticUserMessage = {
      id: `draft-user-${Date.now()}`,
      role: 'user',
      content: question,
      sources: [],
    };

    messages.value = [
      ...withoutStarterMessage(messages.value),
      optimisticUserMessage,
    ];

    chatInput.value = '';
    chatLoading.value = true;

    try {
      const response = await knowledgeStore.askQuestion(question);
      const nextMessages = [
        ...messages.value.filter((message) => message.id !== optimisticUserMessage.id),
        {
          ...response.user_message,
          sources: response.user_message?.sources || [],
        },
        {
          ...response.assistant_message,
          sources: response.assistant_message?.sources || [],
          scopeLabel: response.assistant_message?.scopeLabel || formatScopeLabel(response.scope),
        },
      ];

      knowledgeStore.syncConversationRecord(response.conversation, withoutStarterMessage(nextMessages));
      applyConversationMessages(nextMessages);

      if (response.sources?.length) {
        focusEntry(response.sources[0].id, { fromReference: true });
      }
    } catch (err) {
      const message = err.response?.data?.error || 'Pertanyaan gagal diproses.';
      error.value = message;
      messages.value = [
        ...messages.value,
        {
          id: `draft-error-${Date.now()}`,
          role: 'assistant',
          content: message,
          sources: [],
          scopeLabel: 'Error',
        },
      ];
    } finally {
      chatLoading.value = false;
    }
  };

  const ensureLoaded = async () => {
    if (hasLoaded.value) {
      loading.value = false;
      return;
    }

    loading.value = true;
    error.value = '';

    try {
      await knowledgeStore.fetchHub();
      hasLoaded.value = true;
    } catch (err) {
      error.value = err.response?.data?.error || 'Knowledge hub gagal dimuat.';
    } finally {
      loading.value = false;
    }
  };

  watch(sectionOptions, (sections) => {
    if (selectedSectionId.value !== null && !sections.some((section) => section.id === selectedSectionId.value)) {
      selectedSectionId.value = null;
    }
  }, { immediate: true });

  watch(filteredEntries, (nextEntries) => {
    if (nextEntries.length === 0) {
      selectedEntryId.value = null;
      selectedPreviewPage.value = null;
      return;
    }

    if (selectedEntryId.value !== null && !nextEntries.some((entry) => entry.id === selectedEntryId.value)) {
      selectedEntryId.value = null;
      selectedPreviewPage.value = null;
    }
  }, { immediate: true });

  watch(messages, (nextMessages) => {
    knowledgeStore.setConversation(withoutStarterMessage(nextMessages));
  }, { deep: true });

  return {
    loading,
    chatLoading,
    historyLoading,
    searchLoading,
    creatingFolder,
    uploadingDocument,
    error,
    search,
    selectedScope,
    selectedType,
    selectedSpaceId,
    selectedSectionId,
    selectedEntryId,
    selectedPreviewPage,
    showBookmarksOnly,
    chatInput,
    messages,
    visibleMessages,
    hasConversation,
    conversationSummaries,
    activeConversationId,
    activeConversationSummary,
    spaces,
    currentSpace,
    currentSection,
    entries,
    suggestedQuestions,
    typeOptions,
    scopeOptions,
    sectionOptions,
    filteredEntries,
    selectedEntry,
    bookmarkCount,
    clearError,
    extractReferencePage,
    getEntryById,
    resetConversation,
    ensureConversationHistoryLoaded,
    loadConversation,
    searchConversationHistory,
    renameConversation,
    deleteConversation,
    selectSpace,
    selectSection,
    openEntry,
    clearSelectedEntry,
    focusEntry,
    openSourceFromChat,
    toggleBookmark,
    createFolder,
    uploadDocument,
    submitQuestion,
    ensureLoaded,
  };
};

export const injectKnowledgeHubWorkspace = () => {
  const workspace = inject(knowledgeHubWorkspaceKey, null);

  if (!workspace) {
    throw new Error('Knowledge Hub workspace is not available.');
  }

  return workspace;
};
