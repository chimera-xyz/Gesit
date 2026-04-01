<template>
  <div class="form-view">
    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-gray-900 mx-auto"></div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="card p-6">
      <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <h2 class="text-lg font-medium text-red-900">Error</h2>
        <p class="text-red-700">{{ error }}</p>
        <button @click="loadForm" class="btn-primary">Coba Lagi</button>
      </div>
    </div>

    <!-- Form Content -->
    <div v-else-if="form" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="mb-6">
        <button @click="router.back()" class="btn-secondary mb-4">← Kembali</button>

        <div class="card">
          <div class="px-6 py-4 border-b border-gray-200">
            <h1 class="text-2xl font-bold text-gray-900">{{ form.name }}</h1>
            <p v-if="form.description" class="text-gray-600 mt-2">{{ form.description }}</p>
          </div>

          <div class="px-6 py-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
              <div class="info-item">
                <div class="info-label">Form ID</div>
                <div class="info-value">#{{ form.id }}</div>
              </div>
              <div class="info-item">
                <div class="info-label">Workflow</div>
                <div class="info-value">{{ form.workflow?.name || 'Not assigned' }}</div>
              </div>
              <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                  <span class="status-badge" :class="form.is_active ? 'status-approved' : 'status-rejected'">
                    {{ form.is_active ? 'Aktif' : 'Non-Aktif' }}
                  </span>
                </div>
              </div>
              <div class="info-item">
                <div class="info-label">Total Submissions</div>
                <div class="info-value">{{ form.submissions?.length || 0 }}</div>
              </div>
            </div>

            <!-- Form Configuration Display -->
            <div class="mt-8">
              <h2 class="text-lg font-medium text-gray-900 mb-4">Form Configuration</h2>
              <div class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                <div class="space-y-4">
                  <div
                    v-for="(field, index) in form.form_config?.fields || []"
                    :key="index"
                    class="p-4 bg-white border border-gray-200 rounded"
                  >
                    <div class="flex items-center justify-between mb-2">
                      <h3 class="font-medium text-gray-900">{{ field.label }}</h3>
                      <span class="text-xs text-gray-500">{{ field.type }}</span>
                    </div>
                    <div class="text-sm text-gray-600">
                      <div v-if="field.required" class="text-red-600 mb-1">Wajib diisi</div>
                      <div v-if="field.placeholder" class="mb-1">
                        <span class="font-medium">Placeholder:</span> {{ field.placeholder }}
                      </div>
                      <div v-if="field.options" class="mb-1">
                        <span class="font-medium">Options:</span>
                        <span
                          v-for="(option, optIndex) in field.options"
                          :key="optIndex"
                          class="mr-1 rounded bg-gray-100 px-2 py-1 text-xs text-gray-700"
                        >
                          {{ option }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
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
import { useRouter, useRoute } from 'vue-router';
import { useFormStore } from '../../stores/forms';

const router = useRouter();
const route = useRoute();
const formStore = useFormStore();

const loading = ref(false);
const error = ref(null);
const form = ref(null);

const loadForm = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await formStore.fetchForm(route.params.id);
    form.value = response.form;
  } catch (err) {
    console.error('Error loading form:', err);
    error.value = 'Gagal memuat detail form. Silakan coba lagi.';
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  loadForm();
});
</script>
