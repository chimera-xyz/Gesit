<template>
  <div class="forms-index">
    <!-- Page Header -->
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">Forms</h1>
      <p class="text-gray-600 mt-1">Daftar form yang tersedia untuk submission</p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-900 mx-auto"></div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="card p-6">
      <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <h2 class="text-lg font-medium text-red-900">Error</h2>
        <p class="text-red-700">{{ error }}</p>
        <button @click="loadForms" class="btn-primary">Coba Lagi</button>
      </div>
    </div>

    <!-- Forms List -->
    <div v-else-if="forms.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="form in forms"
        :key="form.id"
        @click="viewForm(form.id)"
        class="card cursor-pointer hover:shadow-lg transition-shadow"
      >
        <div class="p-6">
          <!-- Form Icon -->
          <div class="flex items-center justify-center mb-4">
            <div class="h-16 w-16 bg-primary-100 rounded-lg flex items-center justify-center">
              <svg class="h-8 w-8 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16a1 1 0 00-1 1H7m0 0a1 1 0 011-1V8a1 1 0 011-1z" />
              </svg>
            </div>
          </div>

          <!-- Form Details -->
          <h3 class="text-lg font-medium text-gray-900 mb-2">{{ form.name }}</h3>
          <p class="text-sm text-gray-600 mb-4">{{ form.description || 'Deskripsi form' }}</p>

          <!-- Form Meta -->
          <div class="flex items-center justify-between text-sm text-gray-500">
            <span>
              <svg class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4 6 6m4-6-6 4-4-2" />
              </svg>
              {{ form.workflow?.name || 'Default Workflow' }}
            </span>
            <span v-if="form.is_active" class="text-green-600">Aktif</span>
            <span v-else class="text-red-600">Non-Aktif</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="text-center py-12">
      <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16a1 1 0 00-1 1H7m0 0a1 1 0 011-1V8a1 1 0 011-1z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900">Tidak Ada Form</h3>
      <p class="text-sm text-gray-500">Belum ada form yang tersedia. Hubungi admin untuk membuat form.</p>
      <button @click="loadForms" class="btn-primary mt-4">Refresh</button>
    </div>

    <!-- Admin Actions -->
    <div v-if="authStore.isAdmin" class="fixed bottom-6 right-6">
      <button
        @click="createNewForm"
        class="flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700"
      >
        <svg class="mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Buat Form Baru
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';
import { useAuthStore } from '../../stores/auth';

const router = useRouter();
const formStore = useFormStore();
const authStore = useAuthStore();

const loading = ref(false);
const error = ref(null);
const forms = ref([]);

const viewForm = (formId) => {
  router.push(`/submissions/create?form_id=${formId}`);
};

const createNewForm = () => {
  router.push('/forms/builder');
};

const loadForms = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await formStore.fetchForms();
    forms.value = response.forms || [];
  } catch (err) {
    console.error('Error loading forms:', err);
    error.value = 'Gagal memuat daftar form. Silakan coba lagi.';
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  loadForms();
});
</script>
