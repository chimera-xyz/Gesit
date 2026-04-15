import { computed, inject, reactive, ref } from 'vue';
import { useKnowledgeHubStore } from '../stores/knowledgeHub';

const defaultGeneralForm = () => ({
  id: null,
  description: '',
  ai_instruction: '',
  knowledge_text: '',
  icon: 'sparkles',
  is_active: true,
});

const defaultDivisionForm = () => ({
  id: null,
  name: '',
  description: '',
  ai_instruction: '',
  knowledge_text: '',
  icon: 'folder',
  sort_order: 0,
  is_active: true,
});

const defaultDocumentForm = (spaceId = null) => ({
  id: null,
  knowledge_space_id: spaceId,
  title: '',
  summary: '',
  body: '',
  scope: 'internal',
  type: 'sop',
  source_kind: 'file',
  owner_name: '',
  reviewer_name: '',
  version_label: '',
  effective_date: '',
  reference_notes: '',
  source_link: '',
  tags_text: '',
  access_mode: 'all',
  role_ids: [],
  sort_order: 0,
  is_active: true,
  attachment: null,
  remove_attachment: false,
  existing_attachment_name: '',
  existing_attachment_url: '',
});

const patchReactive = (target, payload) => {
  Object.assign(target, payload);
};

const mapValidationErrors = (target, err) => {
  target.value = {};

  Object.entries(err.response?.data?.errors || {}).forEach(([key, value]) => {
    target.value[key] = Array.isArray(value) ? value[0] : value;
  });
};

export const knowledgeAdminWorkspaceKey = Symbol('knowledge-admin-workspace');

export const useKnowledgeAdminWorkspace = () => {
  const knowledgeStore = useKnowledgeHubStore();

  const loading = ref(true);
  const error = ref('');
  const hasLoaded = ref(false);
  const savingGeneral = ref(false);
  const savingDivision = ref(false);
  const savingDocument = ref(false);
  const selectedDivisionId = ref(null);
  const selectedDocumentId = ref(null);

  const generalErrors = ref({});
  const divisionErrors = ref({});
  const documentErrors = ref({});

  const generalForm = reactive(defaultGeneralForm());
  const divisionForm = reactive(defaultDivisionForm());
  const documentForm = reactive(defaultDocumentForm());

  const general = computed(() => knowledgeStore.adminGeneral);
  const divisions = computed(() => knowledgeStore.adminDivisions);
  const roles = computed(() => knowledgeStore.adminRoles);
  const catalogs = computed(() => knowledgeStore.adminCatalogs);
  const selectedDivision = computed(() => divisions.value.find((item) => item.id === selectedDivisionId.value) || null);
  const divisionDocuments = computed(() => selectedDivision.value?.documents || []);
  const totalDocuments = computed(() => (
    (general.value?.document_count || 0)
    + divisions.value.reduce((count, division) => count + (division.document_count || 0), 0)
  ));

  const fillGeneralForm = (payload) => {
    patchReactive(generalForm, {
      id: payload?.id || null,
      description: payload?.description || '',
      ai_instruction: payload?.ai_instruction || '',
      knowledge_text: payload?.knowledge_text || '',
      icon: payload?.icon || 'sparkles',
      is_active: payload?.is_active ?? true,
    });
  };

  const fillDivisionForm = (payload) => {
    patchReactive(divisionForm, {
      id: payload?.id || null,
      name: payload?.name || '',
      description: payload?.description || '',
      ai_instruction: payload?.ai_instruction || '',
      knowledge_text: payload?.knowledge_text || '',
      icon: payload?.icon || 'folder',
      sort_order: payload?.sort_order || 0,
      is_active: payload?.is_active ?? true,
    });
  };

  const fillDocumentForm = (payload) => {
    patchReactive(documentForm, {
      id: payload?.id || null,
      knowledge_space_id: payload?.knowledge_space_id || selectedDivisionId.value,
      title: payload?.title || '',
      summary: payload?.summary || '',
      body: payload?.body || '',
      scope: payload?.scope || 'internal',
      type: payload?.type || 'sop',
      source_kind: payload?.source_kind || 'file',
      owner_name: payload?.owner_name || '',
      reviewer_name: payload?.reviewer_name || '',
      version_label: payload?.version_label || '',
      effective_date: payload?.effective_date || '',
      reference_notes: payload?.reference_notes || '',
      source_link: payload?.source_link || '',
      tags_text: (payload?.tags || []).join(', '),
      access_mode: payload?.access_mode || 'all',
      role_ids: [...(payload?.role_ids || [])],
      sort_order: payload?.sort_order || 0,
      is_active: payload?.is_active ?? true,
      attachment: null,
      remove_attachment: false,
      existing_attachment_name: payload?.attachment_name || '',
      existing_attachment_url: payload?.attachment_url || '',
    });
  };

  const resetErrors = () => {
    error.value = '';
    generalErrors.value = {};
    divisionErrors.value = {};
    documentErrors.value = {};
  };

  const startCreateDivision = () => {
    selectedDivisionId.value = null;
    fillDivisionForm(defaultDivisionForm());
    divisionErrors.value = {};
    startCreateDocument(null);
  };

  const startCreateDocument = (spaceId = selectedDivisionId.value) => {
    selectedDocumentId.value = null;
    fillDocumentForm(defaultDocumentForm(spaceId));
    documentErrors.value = {};
  };

  const selectDivision = (divisionId) => {
    const division = divisions.value.find((item) => item.id === divisionId) || null;

    if (!division) {
      startCreateDivision();
      return;
    }

    selectedDivisionId.value = division.id;
    fillDivisionForm(division);
    startCreateDocument(division.id);
  };

  const editDocument = (document) => {
    if (!document) {
      startCreateDocument(selectedDivisionId.value);
      return;
    }

    if (document.knowledge_space_id && selectedDivisionId.value !== document.knowledge_space_id) {
      selectDivision(document.knowledge_space_id);
    }

    selectedDocumentId.value = document.id;
    fillDocumentForm(document);
    documentErrors.value = {};
  };

  const refreshAdmin = async () => {
    await knowledgeStore.fetchAdmin();
    fillGeneralForm(general.value || defaultGeneralForm());

    if (divisions.value.length === 0) {
      startCreateDivision();
      return;
    }

    const currentDivision = divisions.value.find((item) => item.id === selectedDivisionId.value) || divisions.value[0];
    selectDivision(currentDivision.id);
  };

  const saveGeneral = async () => {
    savingGeneral.value = true;
    generalErrors.value = {};
    error.value = '';

    try {
      await knowledgeStore.saveGeneral({ ...generalForm });
      await refreshAdmin();
    } catch (err) {
      if (err.response?.status === 422) {
        mapValidationErrors(generalErrors, err);
      } else {
        error.value = err.response?.data?.error || 'General knowledge gagal disimpan.';
      }
    } finally {
      savingGeneral.value = false;
    }
  };

  const saveDivision = async () => {
    savingDivision.value = true;
    divisionErrors.value = {};
    error.value = '';

    try {
      const response = await knowledgeStore.saveDivision({ ...divisionForm });
      const savedDivisionId = response.data?.division?.id || divisionForm.id;

      await knowledgeStore.fetchAdmin();

      if (savedDivisionId) {
        selectDivision(savedDivisionId);
      } else if (divisions.value.length > 0) {
        selectDivision(divisions.value[0].id);
      }
    } catch (err) {
      if (err.response?.status === 422) {
        mapValidationErrors(divisionErrors, err);
      } else {
        error.value = err.response?.data?.error || 'Divisi knowledge gagal disimpan.';
      }
    } finally {
      savingDivision.value = false;
    }
  };

  const saveDocument = async () => {
    savingDocument.value = true;
    documentErrors.value = {};
    error.value = '';

    try {
      const response = await knowledgeStore.saveDocument({
        ...documentForm,
        tags: documentForm.tags_text
          .split(',')
          .map((tag) => tag.trim())
          .filter(Boolean),
      });
      const savedDocumentId = response.data?.document?.id || documentForm.id;

      await knowledgeStore.fetchAdmin();

      if (documentForm.knowledge_space_id) {
        selectDivision(documentForm.knowledge_space_id);
      }

      if (savedDocumentId) {
        const nextDocument = (selectedDivision.value?.documents || []).find((item) => item.id === savedDocumentId) || null;

        if (nextDocument) {
          editDocument(nextDocument);
        }
      }

      return true;
    } catch (err) {
      if (err.response?.status === 422) {
        mapValidationErrors(documentErrors, err);
      } else {
        error.value = err.response?.data?.error || 'Dokumen knowledge gagal disimpan.';
      }

      return false;
    } finally {
      savingDocument.value = false;
    }
  };

  const removeDivision = async (divisionId) => {
    if (!window.confirm('Hapus divisi ini beserta seluruh dokumen AI di dalamnya?')) {
      return;
    }

    error.value = '';

    try {
      await knowledgeStore.deleteDivision(divisionId);
      await knowledgeStore.fetchAdmin();

      if (divisions.value.length > 0) {
        selectDivision(divisions.value[0].id);
      } else {
        startCreateDivision();
      }
    } catch (err) {
      error.value = err.response?.data?.error || 'Divisi knowledge gagal dihapus.';
    }
  };

  const removeDocument = async (documentId) => {
    if (!window.confirm('Hapus dokumen knowledge ini?')) {
      return false;
    }

    error.value = '';

    try {
      await knowledgeStore.deleteDocument(documentId);
      await knowledgeStore.fetchAdmin();

      if (selectedDivisionId.value) {
        selectDivision(selectedDivisionId.value);
      }

      startCreateDocument(selectedDivisionId.value);
      return true;
    } catch (err) {
      error.value = err.response?.data?.error || 'Dokumen knowledge gagal dihapus.';
      return false;
    }
  };

  const handleAttachmentChange = (event) => {
    documentForm.attachment = event.target.files?.[0] || null;
  };

  const ensureLoaded = async () => {
    if (hasLoaded.value) {
      loading.value = false;
      return;
    }

    loading.value = true;

    try {
      resetErrors();
      await refreshAdmin();
      hasLoaded.value = true;
    } catch (err) {
      error.value = err.response?.data?.error || 'Panel Knowledge AI gagal dimuat.';
    } finally {
      loading.value = false;
    }
  };

  return {
    loading,
    error,
    savingGeneral,
    savingDivision,
    savingDocument,
    selectedDivisionId,
    selectedDocumentId,
    generalErrors,
    divisionErrors,
    documentErrors,
    generalForm,
    divisionForm,
    documentForm,
    general,
    divisions,
    roles,
    catalogs,
    selectedDivision,
    divisionDocuments,
    totalDocuments,
    resetErrors,
    startCreateDivision,
    startCreateDocument,
    selectDivision,
    editDocument,
    saveGeneral,
    saveDivision,
    saveDocument,
    removeDivision,
    removeDocument,
    handleAttachmentChange,
    ensureLoaded,
  };
};

export const injectKnowledgeAdminWorkspace = () => {
  const workspace = inject(knowledgeAdminWorkspaceKey, null);

  if (!workspace) {
    throw new Error('Knowledge Admin workspace is not available.');
  }

  return workspace;
};
