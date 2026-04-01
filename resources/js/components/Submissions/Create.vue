<template>
  <div class="form-submission">
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900">Buat Request Baru</h1>
      <p class="mt-2 text-gray-600">Pilih form untuk mengajukan request baru</p>
    </div>

    <!-- Forms List -->
    <div class="card p-6 mb-6">
      <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">Forms Tersedia</h2>
      </div>
      <div class="px-6 py-4">
        <div v-if="loading" class="text-center py-12">
          <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-900 mx-auto"></div>
        </div>
        <div v-else-if="forms.length === 0" class="text-center py-12">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 4-4m6 2a9 9 0 11-18 0 2 2 2h1a2 2 0 00-2 2V7a2 2 0 00-2-2h10a2 2 0 002 2H9a2 2 0 01-2 2V7a2 2 0 00-2 2h5.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V19a2 2 0 00-2-2h1a2 1 0 001 1v-4a1 1 0 001 1H7m0 0a1 1 0 001 1v-3a1 1 0 00-1-1H7m0 0a1 1 0 001 1v-3z" />
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Tidak ada form tersedia</h3>
          <p class="mt-1 text-sm text-gray-500">Silakan hubungi admin untuk membuat form terlebih dahulu.</p>
        </div>
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <div
            v-for="form in forms"
            :key="form.id"
            @click="selectForm(form.id)"
            class="card p-6 cursor-pointer hover:shadow-xl transition-shadow duration-300"
          >
            <div class="flex items-start mb-4">
              <div>
                <h3 class="text-lg font-medium text-gray-900">{{ form.name }}</h3>
                <p class="text-sm text-gray-500">{{ form.description || 'Tidak ada deskripsi' }}</p>
                <div class="mt-2 flex items-center space-x-2">
                  <span class="status-badge" :class="getStatusBadgeClass(form.is_active)">
                    {{ form.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </div>
              </div>
              <div class="mt-4">
                <p class="text-sm text-gray-500">{{ form.workflow?.name || 'Tidak ada workflow' }}</p>
                <p class="text-xs text-gray-400">ID: {{ form.id }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';

const router = useRouter();
const formStore = useFormStore();

const loading = ref(false);
const selectedFormId = ref(null);

const forms = ref([]);

const getStatusBadgeClass = (isActive) => (isActive ? 'status-approved' : 'status-rejected');

const selectForm = (formId) => {
  selectedFormId.value = selectedFormId.value === formId ? null : formId;
  router.push(`/forms/${formId}`);
};

const loadForms = async () => {
  loading.value = true;
  try {
    await formStore.fetchForms();
    forms.value = formStore.forms;
  } catch (error) {
    console.error('Error loading forms:', error);
    alert('Gagal memuat forms. Silakan coba lagi.');
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  loadForms();
});
</script>
