<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">
          {{ canManageForms ? 'Admin Form Center' : 'Request Form Directory' }}
        </p>
        <h1 class="mt-2 text-3xl font-bold text-gray-900">Form</h1>
        <p class="mt-2 text-gray-600">
          {{ canManageForms
            ? 'Kelola form yang sudah ada'
            : 'Pilih form aktif yang ingin Anda gunakan untuk membuat pengajuan baru.' }}
        </p>
      </div>

      <button v-if="canManageForms" type="button" class="btn-primary" @click="createNewForm">
        Buat Form Baru
      </button>
    </div>

    <div v-if="!loading && !error && forms.length > 0" class="card p-5">
      <div class="grid gap-4 lg:grid-cols-[1.6fr_auto]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Cari Form</label>
          <input
            v-model="searchQuery"
            type="text"
            class="input-field"
            placeholder="Cari nama form"
          >
        </div>

        <div class="flex items-end">
          <button type="button" class="btn-secondary w-full" @click="resetSearch">Reset</button>
        </div>
      </div>

      <p class="mt-4 text-sm text-gray-500">
        {{ filteredForms.length }} form tampil dari {{ forms.length }} form.
      </p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
        <h2 class="text-lg font-medium text-red-900">Error</h2>
        <p class="mt-2 text-red-700">{{ error }}</p>
        <button type="button" class="btn-primary mt-4" @click="loadForms">Coba Lagi</button>
      </div>
    </div>

    <div
      v-else-if="canManageForms && filteredForms.length > 0"
      class="grid gap-6 md:grid-cols-2 xl:grid-cols-3"
    >
      <article
        v-for="form in filteredForms"
        :key="form.id"
        class="card group relative flex h-full flex-col overflow-visible p-6 transition duration-200"
        :class="cardClasses(form)"
        @click="handleCardClick(form)"
      >
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">
              {{ canManageForms ? 'Form Builder' : 'Form Aktif' }}
            </p>
            <h2 class="mt-3 text-xl font-semibold leading-8 text-gray-900">{{ form.name }}</h2>
          </div>

          <div class="relative flex shrink-0 items-center gap-2">
            <span
              class="rounded-full px-3 py-1 text-xs font-semibold"
              :class="form.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
            >
              {{ form.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>

            <button
              v-if="canManageForms"
              type="button"
              class="flex h-10 w-10 items-center justify-center rounded-2xl border border-[#efe5d7] bg-white text-gray-500 transition hover:border-[#d8bc84] hover:text-[#8f6115]"
              @click.stop="toggleMenu(form.id)"
            >
              <span class="sr-only">Buka aksi form</span>
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 4.75a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                <path d="M10 11.25a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                <path d="M10 17.75a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
              </svg>
            </button>

            <div
              v-if="canManageForms && openMenuId === form.id"
              class="absolute right-0 top-12 z-20 w-52 overflow-hidden rounded-3xl border border-[#efe5d7] bg-white p-2 shadow-2xl"
              @click.stop
            >
              <button type="button" class="menu-item" @click="viewDetail(form.id)">
                Detail
              </button>
              <button v-if="canEditForms" type="button" class="menu-item" @click="editForm(form.id)">
                Edit
              </button>
              <button
                v-if="canEditForms"
                type="button"
                class="menu-item"
                :disabled="isProcessing(form.id)"
                @click="toggleFormStatus(form)"
              >
                {{ isProcessing(form.id) && processingAction === 'toggle'
                  ? 'Memproses...'
                  : (form.is_active ? 'Nonaktifkan' : 'Aktifkan') }}
              </button>
              <button
                v-if="canDeleteForms"
                type="button"
                class="menu-item menu-item-danger"
                :disabled="isProcessing(form.id) || (form.submissions_count || 0) > 0"
                @click="deleteForm(form)"
              >
                {{ isProcessing(form.id) && processingAction === 'delete' ? 'Menghapus...' : 'Hapus' }}
              </button>
            </div>
          </div>
        </div>

        <p class="mt-4 line-clamp-3 min-h-[4.5rem] text-sm leading-7 text-gray-600">
          {{ form.description || 'Tidak ada deskripsi form.' }}
        </p>

        <div class="mt-6 grid gap-3">
          <div class="rounded-2xl border border-gray-200 bg-[#fffdf9] px-4 py-3">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Workflow</p>
            <p class="mt-2 text-sm font-medium text-gray-900">{{ form.workflow?.name || 'Workflow belum dipasang' }}</p>
          </div>

          <div v-if="canManageForms" class="rounded-2xl border border-gray-200 bg-white px-4 py-3">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Pengajuan</p>
            <p class="mt-2 text-sm font-medium text-gray-900">{{ form.submissions_count || 0 }} pengajuan</p>
            <p class="mt-1 text-xs text-gray-500">
              {{ (form.submissions_count || 0) > 0
                ? 'Form ini hanya bisa dinonaktifkan karena sudah pernah dipakai.'
                : 'Form ini masih bisa dihapus permanen.' }}
            </p>
          </div>
        </div>

        <div class="mt-6 flex items-center justify-between gap-3 border-t border-[#f2eadc] pt-4 text-sm">
          <span class="text-gray-500">
            {{ canSubmitFromCard(form) ? 'Klik card untuk isi form' : 'Form nonaktif tidak bisa diisi' }}
          </span>
          <span
            v-if="canSubmitFromCard(form)"
            class="font-semibold text-[#8f6115] transition group-hover:translate-x-0.5"
          >
            Isi Form
          </span>
        </div>
      </article>
    </div>

    <div v-else-if="filteredForms.length > 0" class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
      <button
        v-for="form in filteredForms"
        :key="form.id"
        type="button"
        class="card cursor-pointer text-left transition-shadow hover:shadow-lg"
        @click="handleCardClick(form)"
      >
        <div class="p-6">
          <div class="mb-4 flex items-center justify-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-lg bg-primary-100">
              <svg class="h-8 w-8 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16a1 1 0 00-1 1H7m0 0a1 1 0 011-1V8a1 1 0 011-1z"
                />
              </svg>
            </div>
          </div>

          <h3 class="mb-2 text-lg font-medium text-gray-900">{{ form.name }}</h3>
          <p class="mb-4 text-sm text-gray-600">{{ form.description || 'Deskripsi form' }}</p>

          <div class="flex items-center justify-between text-sm text-gray-500">
            <span>
              <svg class="mr-1 inline h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 12l2 2 4-4 6 6m4-6-6 4-4-2"
                />
              </svg>
              {{ form.workflow?.name || 'Default Workflow' }}
            </span>
            <span class="text-green-600">Aktif</span>
          </div>
        </div>
      </button>
    </div>

    <div v-else-if="forms.length > 0" class="card p-10 text-center">
      <h3 class="text-lg font-medium text-gray-900">Tidak ada form yang cocok</h3>
      <p class="mt-2 text-sm text-gray-500">Ubah kata kunci pencarian untuk menemukan form yang Anda cari.</p>
      <button type="button" class="btn-secondary mt-4" @click="resetSearch">Reset Pencarian</button>
    </div>

    <div v-else class="card p-10 text-center">
      <h3 class="text-lg font-medium text-gray-900">Tidak ada form</h3>
      <p class="mt-2 text-sm text-gray-500">
        {{ canManageForms
          ? 'Belum ada form yang dibuat. Buat form baru untuk mulai mengumpulkan pengajuan.'
          : 'Belum ada form aktif yang tersedia. Silakan hubungi admin.' }}
      </p>
      <button v-if="canManageForms" type="button" class="btn-primary mt-4" @click="createNewForm">Buat Form Baru</button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';
import { useAuthStore } from '../../stores/auth';

const router = useRouter();
const formStore = useFormStore();
const authStore = useAuthStore();

const loading = ref(false);
const error = ref(null);
const processingFormId = ref(null);
const processingAction = ref('');
const openMenuId = ref(null);
const searchQuery = ref('');

const forms = computed(() => formStore.forms);
const filteredForms = computed(() => {
  const search = searchQuery.value.trim().toLowerCase();

  if (!search) {
    return forms.value;
  }

  return forms.value.filter((form) => [
    form.name,
    form.description,
    form.workflow?.name,
  ].filter(Boolean).some((value) => String(value).toLowerCase().includes(search)));
});
const canCreateForms = computed(() => authStore.hasPermission('create forms'));
const canEditForms = computed(() => authStore.hasPermission('edit forms'));
const canDeleteForms = computed(() => authStore.hasPermission('delete forms'));
const canSubmitForms = computed(() => authStore.hasPermission('submit forms'));
const canManageForms = computed(() => authStore.isAdmin);

const canSubmitFromCard = (form) => Boolean(form.is_active && canSubmitForms.value);

const cardClasses = (form) => {
  if (canSubmitFromCard(form)) {
    return 'cursor-pointer hover:-translate-y-1 hover:shadow-xl';
  }

  return 'opacity-95';
};

const isProcessing = (formId) => processingFormId.value === formId;

const closeMenu = () => {
  openMenuId.value = null;
};

const toggleMenu = (formId) => {
  openMenuId.value = openMenuId.value === formId ? null : formId;
};

const handleCardClick = (form) => {
  closeMenu();

  if (!canSubmitFromCard(form)) {
    return;
  }

  submitWithForm(form.id);
};

const submitWithForm = (formId) => {
  router.push({
    name: 'submissions-create',
    query: { form_id: String(formId) },
  });
};

const createNewForm = () => {
  closeMenu();
  router.push({ name: 'forms-builder' });
};

const resetSearch = () => {
  searchQuery.value = '';
};

const viewDetail = (formId) => {
  closeMenu();
  router.push({ name: 'form-view', params: { id: String(formId) } });
};

const editForm = (formId) => {
  closeMenu();
  router.push({ name: 'forms-edit', params: { id: String(formId) } });
};

const toggleFormStatus = async (form) => {
  const targetStatus = form.is_active ? 'nonaktifkan' : 'aktifkan';

  if (!window.confirm(`Yakin ingin ${targetStatus} form "${form.name}"?`)) {
    return;
  }

  processingFormId.value = form.id;
  processingAction.value = 'toggle';

  try {
    await formStore.updateForm(form.id, {
      is_active: !form.is_active,
    });
    closeMenu();
  } catch (err) {
    console.error('Error toggling form status:', err);
    alert(err.response?.data?.error || 'Status form gagal diperbarui.');
  } finally {
    processingFormId.value = null;
    processingAction.value = '';
  }
};

const deleteForm = async (form) => {
  if ((form.submissions_count || 0) > 0) {
    alert('Form yang sudah punya pengajuan tidak bisa dihapus permanen. Nonaktifkan saja.');
    return;
  }

  if (!window.confirm(`Hapus permanen form "${form.name}"? Aksi ini tidak bisa dibatalkan.`)) {
    return;
  }

  processingFormId.value = form.id;
  processingAction.value = 'delete';

  try {
    await formStore.deleteForm(form.id);
    closeMenu();
  } catch (err) {
    console.error('Error deleting form:', err);
    alert(err.response?.data?.error || 'Form gagal dihapus.');
  } finally {
    processingFormId.value = null;
    processingAction.value = '';
  }
};

const loadForms = async () => {
  loading.value = true;
  error.value = null;

  try {
    await formStore.fetchForms();
  } catch (err) {
    console.error('Error loading forms:', err);
    error.value = err.response?.data?.error || 'Gagal memuat daftar form.';
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', closeMenu);
  loadForms();
});

onUnmounted(() => {
  document.removeEventListener('click', closeMenu);
});
</script>

<style scoped>
.menu-item {
  width: 100%;
  border: 0;
  background: transparent;
  border-radius: 1rem;
  padding: 0.85rem 1rem;
  text-align: left;
  font-size: 0.95rem;
  color: #334155;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.menu-item:hover:not(:disabled) {
  background: #fbf5ea;
  color: #8f6115;
}

.menu-item:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.menu-item-danger {
  color: #b42318;
}

.menu-item-danger:hover:not(:disabled) {
  background: #fef3f2;
  color: #b42318;
}
</style>
