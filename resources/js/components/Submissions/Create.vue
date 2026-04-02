<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Request Center</p>
        <h1 class="mt-2 text-3xl font-bold text-gray-900">
          {{ selectedForm ? 'Isi Request Form' : 'Buat Request Baru' }}
        </h1>
        <p class="mt-2 text-gray-600">
          {{ selectedForm ? 'Lengkapi kebutuhan pengadaan dengan data yang jelas agar alur approval berjalan lancar.' : 'Pilih form aktif yang ingin Anda ajukan.' }}
        </p>
      </div>

      <button v-if="selectedForm" @click="clearSelectedForm" class="btn-secondary">
        Kembali ke daftar form
      </button>
    </div>

    <div v-if="loadingForms || loadingForm" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <h2 class="text-lg font-semibold text-red-800">Gagal memuat form</h2>
      <p class="mt-2 text-sm text-red-700">{{ error }}</p>
      <button @click="reloadCurrentState" class="btn-primary mt-4">Coba Lagi</button>
    </div>

    <template v-else-if="selectedForm">
      <section class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-6">
          <div class="max-w-3xl">
            <h2 class="text-2xl font-semibold text-gray-900">{{ selectedForm.name }}</h2>
            <p class="mt-3 text-sm leading-7 text-gray-600">{{ selectedForm.description || 'Tidak ada deskripsi form.' }}</p>
          </div>

          <div class="rounded-2xl border border-[#eadfcf] bg-[#fffdf9] px-4 py-3 text-sm text-gray-700">
            <p class="font-semibold text-gray-900">{{ selectedForm.workflow?.name || 'Workflow belum dipasang' }}</p>
            <p class="mt-1 text-xs uppercase tracking-[0.18em] text-[#a57e3a]">Workflow aktif</p>
          </div>
        </div>

        <div v-if="workflowSteps.length > 0" class="mt-6 grid gap-3 lg:grid-cols-5">
          <div
            v-for="step in workflowSteps"
            :key="step.step_number"
            class="rounded-2xl border border-gray-200 bg-white px-4 py-4"
          >
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Step {{ step.step_number }}</p>
            <p class="mt-2 text-sm font-semibold text-gray-900">{{ step.name }}</p>
            <p class="mt-2 text-xs text-gray-500">{{ step.role }}</p>
          </div>
        </div>
      </section>

      <section class="card p-6">
        <form class="space-y-6" @submit.prevent="submitForm">
          <div class="grid gap-5 md:grid-cols-2">
            <div
              v-for="field in formFields"
              :key="field.id"
              :class="field.type === 'textarea' || field.type === 'checkbox' || field.type === 'radio' ? 'md:col-span-2' : ''"
            >
              <label class="mb-2 block text-sm font-medium text-gray-700">
                {{ field.label }}
                <span v-if="field.required" class="text-red-600">*</span>
              </label>

              <input
                v-if="['text', 'email', 'number', 'date'].includes(field.type)"
                v-model="formValues[field.id]"
                :type="field.type"
                :readonly="isReadonly(field)"
                :placeholder="field.placeholder || ''"
                class="input-field"
              >

              <textarea
                v-else-if="field.type === 'textarea'"
                v-model="formValues[field.id]"
                :readonly="isReadonly(field)"
                :placeholder="field.placeholder || ''"
                class="input-field"
                rows="5"
              ></textarea>

              <select
                v-else-if="field.type === 'select'"
                v-model="formValues[field.id]"
                :disabled="isReadonly(field)"
                class="select-field"
              >
                <option value="">Pilih salah satu</option>
                <option v-for="option in normalizeOptions(field.options)" :key="option" :value="option">
                  {{ option }}
                </option>
              </select>

              <div v-else-if="field.type === 'radio'" class="grid gap-3 sm:grid-cols-2">
                <label
                  v-for="option in normalizeOptions(field.options)"
                  :key="option"
                  class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3"
                >
                  <input
                    v-model="formValues[field.id]"
                    :value="option"
                    :disabled="isReadonly(field)"
                    type="radio"
                  >
                  <span class="text-sm text-gray-700">{{ option }}</span>
                </label>
              </div>

              <div v-else-if="field.type === 'checkbox'" class="grid gap-3 sm:grid-cols-2">
                <label
                  v-for="option in normalizeOptions(field.options)"
                  :key="option"
                  class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3"
                >
                  <input
                    v-model="formValues[field.id]"
                    :value="option"
                    :disabled="isReadonly(field)"
                    type="checkbox"
                  >
                  <span class="text-sm text-gray-700">{{ option }}</span>
                </label>
              </div>

              <div v-else-if="field.type === 'file'" class="rounded-2xl border border-dashed border-gray-300 p-4">
                <input
                  type="file"
                  class="block w-full text-sm text-gray-700"
                  @change="updateFile(field.id, $event)"
                >
                <p v-if="formValues[field.id]?.name" class="mt-2 text-xs text-gray-500">
                  File dipilih: {{ formValues[field.id].name }}
                </p>
              </div>

              <input
                v-else
                v-model="formValues[field.id]"
                :readonly="isReadonly(field)"
                :placeholder="field.placeholder || ''"
                type="text"
                class="input-field"
              >
            </div>
          </div>

          <div class="flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-6">
            <button type="button" class="btn-secondary" @click="clearSelectedForm">Batal</button>
            <button type="submit" :disabled="isSubmitting" class="btn-primary">
              {{ isSubmitting ? 'Mengirim...' : 'Kirim Pengajuan' }}
            </button>
          </div>
        </form>
      </section>
    </template>

    <section v-else class="card p-6">
      <div v-if="forms.length === 0" class="py-12 text-center">
        <h2 class="text-lg font-semibold text-gray-900">Tidak ada form tersedia</h2>
        <p class="mt-2 text-sm text-gray-500">Silakan hubungi admin untuk mengaktifkan form.</p>
      </div>

      <div v-else class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        <button
          v-for="form in forms"
          :key="form.id"
          type="button"
          class="card cursor-pointer p-6 text-left transition hover:-translate-y-0.5 hover:shadow-xl"
          @click="selectForm(form.id)"
        >
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Form Aktif</p>
              <h2 class="mt-3 text-lg font-semibold text-gray-900">{{ form.name }}</h2>
              <p class="mt-2 text-sm leading-6 text-gray-600">{{ form.description || 'Tidak ada deskripsi form.' }}</p>
            </div>
            <span class="status-badge status-approved">Ready</span>
          </div>

          <div class="mt-6 rounded-2xl border border-gray-200 bg-[#fffdf9] px-4 py-3">
            <p class="text-sm font-medium text-gray-900">{{ form.workflow?.name || 'Workflow belum dipasang' }}</p>
            <p class="mt-1 text-xs text-gray-500">Klik untuk mulai mengisi form</p>
          </div>
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useFormStore } from '../../stores/forms';
import { useSubmissionStore } from '../../stores/submissions';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const formStore = useFormStore();
const submissionStore = useSubmissionStore();

const loadingForms = ref(false);
const loadingForm = ref(false);
const isSubmitting = ref(false);
const error = ref(null);
const forms = ref([]);
const selectedForm = ref(null);
const formValues = ref({});

const selectedFormId = computed(() => route.query.form_id ? String(route.query.form_id) : null);
const formFields = computed(() => selectedForm.value?.form_config?.fields || []);
const workflowSteps = computed(() => selectedForm.value?.workflow?.workflow_config?.steps || []);

const normalizeOptions = (options) => {
  if (Array.isArray(options)) {
    return options;
  }

  if (typeof options === 'string') {
    return options.split(',').map((option) => option.trim()).filter(Boolean);
  }

  return [];
};

const resolveAutoFillValue = (field) => {
  switch (field.auto_fill) {
    case 'user.name':
      return authStore.user?.name || '';
    case 'user.email':
      return authStore.user?.email || '';
    case 'user.department':
      return authStore.user?.department || '';
    case 'user.employee_id':
      return authStore.user?.employee_id || '';
    case 'today':
      return new Date().toISOString().slice(0, 10);
    default:
      return field.default ?? (field.type === 'checkbox' ? [] : '');
  }
};

const hydrateFormValues = (form) => {
  const values = {};

  (form?.form_config?.fields || []).forEach((field) => {
    values[field.id] = resolveAutoFillValue(field);
  });

  formValues.value = values;
};

const isReadonly = (field) => Boolean(field.readonly || field.auto_fill);

const loadForms = async () => {
  loadingForms.value = true;
  error.value = null;

  try {
    const response = await formStore.fetchForms();
    forms.value = (response.forms || []).filter((form) => form.is_active);
  } catch (err) {
    console.error('Error loading forms:', err);
    error.value = err.response?.data?.error || 'Form tidak dapat dimuat.';
  } finally {
    loadingForms.value = false;
  }
};

const loadSelectedForm = async () => {
  if (!selectedFormId.value) {
    selectedForm.value = null;
    return;
  }

  loadingForm.value = true;
  error.value = null;

  try {
    const response = await formStore.fetchForm(selectedFormId.value);
    selectedForm.value = response.form;
    hydrateFormValues(response.form);
  } catch (err) {
    console.error('Error loading selected form:', err);
    error.value = err.response?.data?.error || 'Detail form tidak dapat dimuat.';
  } finally {
    loadingForm.value = false;
  }
};

const selectForm = (formId) => {
  router.push({
    name: 'submissions-create',
    query: { form_id: String(formId) },
  });
};

const clearSelectedForm = () => {
  router.push({ name: 'submissions-create' });
};

const updateFile = (fieldId, event) => {
  formValues.value[fieldId] = event.target.files?.[0] || null;
};

const submitForm = async () => {
  if (!selectedForm.value || isSubmitting.value) {
    return;
  }

  isSubmitting.value = true;
  error.value = null;

  try {
    const payload = new FormData();
    payload.append('form_id', selectedForm.value.id);

    formFields.value.forEach((field) => {
      const value = formValues.value[field.id];

      if (field.type === 'checkbox') {
        (value || []).forEach((item) => {
          payload.append(`form_data[${field.id}][]`, item);
        });

        return;
      }

      if (field.type === 'file') {
        if (value instanceof File) {
          payload.append(`form_data[${field.id}]`, value);
        }

        return;
      }

      payload.append(`form_data[${field.id}]`, value ?? '');
    });

    const response = await submissionStore.createSubmission(payload);
    router.push(`/submissions/${response.submission.id}`);
  } catch (err) {
    console.error('Error creating submission:', err);
    error.value = err.response?.data?.error || 'Gagal mengirim pengajuan.';
  } finally {
    isSubmitting.value = false;
  }
};

const reloadCurrentState = async () => {
  await loadForms();
  await loadSelectedForm();
};

watch(
  () => route.query.form_id,
  async () => {
    await loadSelectedForm();
  }
);

onMounted(async () => {
  await loadForms();
  await loadSelectedForm();
});
</script>
