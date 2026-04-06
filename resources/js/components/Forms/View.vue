<template>
  <div class="space-y-6">
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
        <h2 class="text-lg font-medium text-red-900">Error</h2>
        <p class="mt-2 text-red-700">{{ error }}</p>
        <button type="button" class="btn-primary mt-4" @click="loadForm">Coba Lagi</button>
      </div>
    </div>

    <template v-else-if="form">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Form Detail</p>
          <h1 class="mt-2 text-3xl font-bold text-gray-900">{{ form.name }}</h1>
          <p class="mt-2 max-w-3xl text-gray-600">{{ form.description || 'Tidak ada deskripsi form.' }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="button" class="btn-secondary" @click="router.push('/forms')">Kembali</button>
          <button v-if="canSubmitForms && form.is_active" type="button" class="btn-secondary" @click="submitWithForm">
            Isi Form
          </button>
          <button v-if="canEditForms" type="button" class="btn-secondary" @click="editForm">Edit Form</button>
          <button
            v-if="canEditForms"
            type="button"
            class="btn-secondary"
            :disabled="processing"
            @click="toggleFormStatus"
          >
            {{ processingAction === 'toggle' ? 'Memproses...' : (form.is_active ? 'Nonaktifkan' : 'Aktifkan') }}
          </button>
          <button
            v-if="canDeleteForms"
            type="button"
            class="btn-danger"
            :disabled="processing || (form.submissions_count || 0) > 0"
            @click="deleteForm"
          >
            {{ processingAction === 'delete' ? 'Menghapus...' : 'Hapus Form' }}
          </button>
        </div>
      </div>

      <section class="grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
        <div class="card p-6">
          <div class="grid gap-5 sm:grid-cols-2">
            <div>
              <p class="text-sm text-gray-500">Status</p>
              <p class="mt-2">
                <span
                  class="rounded-full px-3 py-1 text-xs font-semibold"
                  :class="form.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
                >
                  {{ form.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </p>
            </div>

            <div>
              <p class="text-sm text-gray-500">Workflow</p>
              <p class="mt-2 font-medium text-gray-900">{{ form.workflow?.name || 'Belum dipasang' }}</p>
            </div>

            <div>
              <p class="text-sm text-gray-500">Jumlah Field</p>
              <p class="mt-2 font-medium text-gray-900">{{ formFields.length }}</p>
            </div>

            <div>
              <p class="text-sm text-gray-500">Jumlah Pengajuan</p>
              <p class="mt-2 font-medium text-gray-900">{{ form.submissions_count || 0 }}</p>
            </div>
          </div>

          <div v-if="(form.submissions_count || 0) > 0" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800">
            Form ini sudah pernah dipakai untuk pengajuan. Anda masih bisa edit struktur form untuk submission baru dan bisa nonaktifkan form, tetapi hapus permanen dinonaktifkan agar histori pengajuan tetap aman.
          </div>
        </div>

        <div class="card p-6">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Konfigurasi Field</h2>
              <p class="mt-1 text-sm text-gray-500">Urutan field di bawah mengikuti struktur form yang aktif saat ini.</p>
            </div>
            <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
              {{ formFields.length }} field
            </span>
          </div>

          <div v-if="formFields.length > 0" class="mt-5 space-y-4">
            <div
              v-for="field in formFields"
              :key="field.id"
              class="rounded-2xl border border-gray-200 bg-white p-4"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p class="text-sm font-semibold text-gray-900">{{ field.label }}</p>
                  <p class="mt-1 text-xs uppercase tracking-[0.16em] text-gray-500">{{ field.type }}</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                  <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700">ID: {{ field.id }}</span>
                  <span v-if="field.required" class="rounded-full bg-red-50 px-3 py-1 font-medium text-red-700">Required</span>
                  <span v-if="field.readonly" class="rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-700">Readonly</span>
                  <span v-if="field.auto_fill" class="rounded-full bg-blue-50 px-3 py-1 font-medium text-blue-700">Auto Fill</span>
                </div>
              </div>

              <p v-if="field.placeholder" class="mt-3 text-sm text-gray-600">
                Placeholder: {{ field.placeholder }}
              </p>

              <p v-if="field.validation" class="mt-2 text-sm text-gray-600">
                Validasi: {{ field.validation }}
              </p>

              <div v-if="normalizeOptions(field.options).length > 0" class="mt-3 flex flex-wrap gap-2">
                <span
                  v-for="option in normalizeOptions(field.options)"
                  :key="option"
                  class="rounded-full bg-[#fff4dd] px-3 py-1 text-xs font-medium text-[#8f6115]"
                >
                  {{ option }}
                </span>
              </div>
            </div>
          </div>

          <div v-else class="mt-5 rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-500">
            Form ini belum punya field.
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useFormStore } from '../../stores/forms';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();
const formStore = useFormStore();

const loading = ref(false);
const error = ref(null);
const form = ref(null);
const processing = ref(false);
const processingAction = ref('');

const formFields = computed(() => form.value?.form_config?.fields || []);
const canEditForms = computed(() => authStore.hasPermission('edit forms'));
const canDeleteForms = computed(() => authStore.hasPermission('delete forms'));
const canSubmitForms = computed(() => authStore.hasPermission('submit forms'));

const normalizeOptions = (options) => {
  if (Array.isArray(options)) {
    return options;
  }

  if (typeof options === 'string') {
    return options.split(',').map((option) => option.trim()).filter(Boolean);
  }

  return [];
};

const loadForm = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await formStore.fetchForm(route.params.id);
    form.value = response.form;
  } catch (err) {
    console.error('Error loading form:', err);
    error.value = err.response?.data?.error || 'Gagal memuat detail form.';
  } finally {
    loading.value = false;
  }
};

const submitWithForm = () => {
  router.push({
    name: 'submissions-create',
    query: { form_id: String(form.value.id) },
  });
};

const editForm = () => {
  router.push({
    name: 'forms-edit',
    params: { id: String(form.value.id) },
  });
};

const toggleFormStatus = async () => {
  const targetStatus = form.value.is_active ? 'nonaktifkan' : 'aktifkan';

  if (!window.confirm(`Yakin ingin ${targetStatus} form "${form.value.name}"?`)) {
    return;
  }

  processing.value = true;
  processingAction.value = 'toggle';

  try {
    const response = await formStore.updateForm(form.value.id, {
      is_active: !form.value.is_active,
    });
    form.value = response.form;
  } catch (err) {
    console.error('Error toggling form:', err);
    alert(err.response?.data?.error || 'Status form gagal diperbarui.');
  } finally {
    processing.value = false;
    processingAction.value = '';
  }
};

const deleteForm = async () => {
  if ((form.value.submissions_count || 0) > 0) {
    alert('Form yang sudah punya pengajuan tidak bisa dihapus permanen. Nonaktifkan saja.');
    return;
  }

  if (!window.confirm(`Hapus permanen form "${form.value.name}"? Aksi ini tidak bisa dibatalkan.`)) {
    return;
  }

  processing.value = true;
  processingAction.value = 'delete';

  try {
    await formStore.deleteForm(form.value.id);
    router.push('/forms');
  } catch (err) {
    console.error('Error deleting form:', err);
    alert(err.response?.data?.error || 'Form gagal dihapus.');
  } finally {
    processing.value = false;
    processingAction.value = '';
  }
};

onMounted(() => {
  loadForm();
});
</script>
