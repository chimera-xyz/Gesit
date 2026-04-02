<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Admin Form Builder</p>
        <h1 class="mt-2 text-3xl font-bold text-gray-900">{{ isEditing ? 'Edit Form' : 'Buat Form Baru' }}</h1>
        <p class="mt-2 text-gray-600">Susun field secara drag-and-drop lalu hubungkan ke workflow yang sudah tersedia.</p>
      </div>

      <router-link to="/forms" class="btn-secondary">Kembali ke daftar form</router-link>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else class="space-y-6">
      <section class="card p-6">
        <div class="grid gap-5 lg:grid-cols-2">
          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Nama Form</label>
            <input v-model="form.name" type="text" class="input-field" placeholder="Contoh: Form Pengadaan Vendor Baru">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Workflow</label>
            <select v-model="form.workflow_id" class="select-field">
              <option value="">Pilih workflow</option>
              <option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">
                {{ workflow.name }}
              </option>
            </select>
          </div>

          <div class="lg:col-span-2">
            <label class="mb-2 block text-sm font-medium text-gray-700">Deskripsi</label>
            <textarea
              v-model="form.description"
              class="input-field"
              rows="4"
              placeholder="Jelaskan tujuan form ini dan siapa yang akan menggunakannya."
            ></textarea>
          </div>
        </div>
      </section>

      <section class="grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
        <div class="space-y-6">
          <div class="card p-5">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">Toolbox Field</h2>
                <p class="mt-1 text-sm text-gray-500">Klik atau drag field ke canvas.</p>
              </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
              <button
                v-for="fieldType in availableFieldTypes"
                :key="fieldType.type"
                type="button"
                draggable="true"
                class="form-builder-field cursor-grab text-left"
                @click="addField(fieldType.type)"
                @dragstart="onDragStart($event, fieldType)"
                @dragend="onDragEnd"
              >
                <p class="text-sm font-semibold text-gray-900">{{ fieldType.label }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ fieldType.description }}</p>
              </button>
            </div>
          </div>

          <div v-if="selectedField" class="card p-5">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="text-lg font-semibold text-gray-900">Pengaturan Field</h2>
                <p class="mt-1 text-sm text-gray-500">Atur label, validasi, dan perilaku field yang dipilih.</p>
              </div>
              <button type="button" class="text-sm font-medium text-red-600" @click="removeField(selectedField.id)">Hapus</button>
            </div>

            <div class="mt-5 space-y-4">
              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Label</label>
                <input v-model="selectedField.label" type="text" class="input-field">
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Field ID</label>
                <input v-model="selectedField.id" type="text" class="input-field">
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Placeholder</label>
                <input v-model="selectedField.placeholder" type="text" class="input-field">
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Default Value</label>
                <input v-model="selectedField.default" type="text" class="input-field">
              </div>

              <div v-if="supportsOptions(selectedField.type)">
                <label class="mb-2 block text-sm font-medium text-gray-700">Opsi</label>
                <input
                  v-model="selectedField.optionsText"
                  type="text"
                  class="input-field"
                  placeholder="Pisahkan dengan koma, contoh: Hardware, Software"
                >
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Validation Rules</label>
                <input
                  v-model="selectedField.validation"
                  type="text"
                  class="input-field"
                  placeholder="Contoh: string|max:255"
                >
              </div>

              <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3">
                  <input v-model="selectedField.required" type="checkbox">
                  <span class="text-sm text-gray-700">Wajib diisi</span>
                </label>
                <label class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3">
                  <input v-model="selectedField.readonly" type="checkbox">
                  <span class="text-sm text-gray-700">Readonly</span>
                </label>
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Auto Fill</label>
                <select v-model="selectedField.auto_fill" class="select-field">
                  <option value="">Tidak ada</option>
                  <option value="user.name">User Name</option>
                  <option value="user.email">User Email</option>
                  <option value="user.department">User Department</option>
                  <option value="user.employee_id">User Employee ID</option>
                  <option value="today">Tanggal Hari Ini</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <div class="card p-6">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-gray-900">Canvas Form</h2>
              <p class="mt-1 text-sm text-gray-500">Urutan field di sini akan menjadi urutan form di halaman user.</p>
            </div>
            <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
              {{ builderFields.length }} field
            </span>
          </div>

          <div
            class="mt-5 min-h-[24rem] rounded-[1.5rem] border border-dashed border-gray-300 bg-[#fffdf9] p-5"
            :class="{ 'border-[#c7911d] bg-[#fff9ef]': isDragging }"
            @dragover.prevent="onDragOver"
            @dragenter.prevent="onDragEnter"
            @drop.prevent="onDrop"
          >
            <div v-if="builderFields.length === 0" class="flex h-full min-h-[18rem] flex-col items-center justify-center text-center">
              <p class="text-base font-semibold text-gray-900">Belum ada field</p>
              <p class="mt-2 max-w-sm text-sm leading-6 text-gray-500">Tarik field dari toolbox atau klik salah satu tipe field untuk mulai membangun form.</p>
            </div>

            <div v-else class="space-y-4">
              <button
                v-for="(field, index) in builderFields"
                :key="field.uid"
                type="button"
                class="form-builder-field block w-full text-left"
                :class="{ selected: selectedFieldId === field.uid }"
                @click="selectField(field.uid)"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p class="text-sm font-semibold text-gray-900">{{ field.label || 'Untitled Field' }}</p>
                    <p class="mt-1 text-xs uppercase tracking-[0.18em] text-gray-500">{{ field.type }}</p>
                  </div>

                  <div class="flex items-center gap-2 text-xs text-gray-500">
                    <span v-if="field.required" class="rounded-full bg-red-50 px-3 py-1 font-semibold text-red-600">Required</span>
                    <span v-if="field.readonly" class="rounded-full bg-gray-100 px-3 py-1 font-semibold text-gray-600">Readonly</span>
                    <button type="button" class="text-red-600" @click.stop="removeField(field.uid)">Hapus</button>
                  </div>
                </div>

                <div class="mt-4 rounded-2xl border border-gray-200 bg-white p-4">
                  <template v-if="['text', 'email', 'number', 'date'].includes(field.type)">
                    <input :type="field.type" class="input-field" :placeholder="field.placeholder || field.label">
                  </template>

                  <template v-else-if="field.type === 'textarea'">
                    <textarea class="input-field" rows="4" :placeholder="field.placeholder || field.label"></textarea>
                  </template>

                  <template v-else-if="field.type === 'select'">
                    <select class="select-field">
                      <option value="">Pilih salah satu</option>
                      <option v-for="option in normalizeOptions(field.optionsText)" :key="option" :value="option">
                        {{ option }}
                      </option>
                    </select>
                  </template>

                  <template v-else-if="field.type === 'radio'">
                    <div class="grid gap-3 sm:grid-cols-2">
                      <label
                        v-for="option in normalizeOptions(field.optionsText)"
                        :key="option"
                        class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3 text-sm text-gray-700"
                      >
                        <input type="radio">
                        {{ option }}
                      </label>
                    </div>
                  </template>

                  <template v-else-if="field.type === 'checkbox'">
                    <div class="grid gap-3 sm:grid-cols-2">
                      <label
                        v-for="option in normalizeOptions(field.optionsText)"
                        :key="option"
                        class="flex items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3 text-sm text-gray-700"
                      >
                        <input type="checkbox">
                        {{ option }}
                      </label>
                    </div>
                  </template>

                  <template v-else-if="field.type === 'file'">
                    <input type="file" class="block w-full text-sm text-gray-700">
                  </template>
                </div>

                <div class="mt-4 flex items-center justify-between text-xs text-gray-500">
                  <span>ID: {{ field.id }}</span>
                  <div class="flex items-center gap-2">
                    <button type="button" @click.stop="moveField(index, index - 1)" :disabled="index === 0">Naik</button>
                    <button type="button" @click.stop="moveField(index, index + 1)" :disabled="index === builderFields.length - 1">Turun</button>
                  </div>
                </div>
              </button>
            </div>
          </div>
        </div>
      </section>

      <div class="flex flex-wrap justify-end gap-3">
        <router-link to="/forms" class="btn-secondary">Batal</router-link>
        <button
          type="button"
          class="btn-primary"
          :disabled="isSaving || !form.name || !form.workflow_id || builderFields.length === 0"
          @click="saveForm"
        >
          {{ isSaving ? 'Menyimpan...' : (isEditing ? 'Update Form' : 'Simpan Form') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useFormStore } from '../../stores/forms';

const router = useRouter();
const route = useRoute();
const formStore = useFormStore();

const loading = ref(false);
const isSaving = ref(false);
const isDragging = ref(false);
const isEditing = ref(false);
const selectedFieldId = ref(null);
const workflows = ref([]);
const builderFields = ref([]);

const form = ref({
  name: '',
  description: '',
  workflow_id: '',
});

const availableFieldTypes = [
  { type: 'text', label: 'Text', description: 'Single-line input untuk data pendek.' },
  { type: 'email', label: 'Email', description: 'Input email dengan validasi format.' },
  { type: 'textarea', label: 'Textarea', description: 'Input panjang untuk kebutuhan narasi.' },
  { type: 'number', label: 'Number', description: 'Input angka seperti jumlah atau biaya.' },
  { type: 'select', label: 'Select', description: 'Dropdown satu pilihan.' },
  { type: 'radio', label: 'Radio', description: 'Pilihan tunggal yang langsung terlihat.' },
  { type: 'checkbox', label: 'Checkbox', description: 'Pilihan jamak untuk lebih dari satu opsi.' },
  { type: 'date', label: 'Date', description: 'Pilih tanggal dengan date picker.' },
  { type: 'file', label: 'File', description: 'Upload file pendukung atau lampiran.' },
];

const selectedField = computed(() =>
  builderFields.value.find((field) => field.uid === selectedFieldId.value) || null
);

const createField = (type) => ({
  uid: `field_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`,
  id: `${type}_${Date.now()}_${Math.random().toString(36).slice(2, 5)}`,
  type,
  label: `${type.charAt(0).toUpperCase()}${type.slice(1)} Field`,
  placeholder: '',
  default: '',
  required: false,
  readonly: false,
  auto_fill: '',
  validation: '',
  optionsText: ['select', 'radio', 'checkbox'].includes(type) ? 'Opsi 1, Opsi 2' : '',
});

const normalizeOptions = (optionsText) =>
  (optionsText || '')
    .split(',')
    .map((option) => option.trim())
    .filter(Boolean);

const supportsOptions = (type) => ['select', 'radio', 'checkbox'].includes(type);

const selectField = (fieldUid) => {
  selectedFieldId.value = fieldUid;
};

const addField = (type) => {
  const field = createField(type);
  builderFields.value.push(field);
  selectedFieldId.value = field.uid;
};

const removeField = (fieldUid) => {
  builderFields.value = builderFields.value.filter((field) => field.uid !== fieldUid);

  if (selectedFieldId.value === fieldUid) {
    selectedFieldId.value = builderFields.value[0]?.uid || null;
  }
};

const moveField = (fromIndex, toIndex) => {
  if (toIndex < 0 || toIndex >= builderFields.value.length) {
    return;
  }

  const [field] = builderFields.value.splice(fromIndex, 1);
  builderFields.value.splice(toIndex, 0, field);
};

const onDragStart = (event, fieldType) => {
  isDragging.value = true;
  event.dataTransfer.effectAllowed = 'copy';
  event.dataTransfer.setData('field-type', fieldType.type);
};

const onDragOver = (event) => {
  event.preventDefault();
  event.dataTransfer.dropEffect = 'copy';
};

const onDragEnter = () => {
  isDragging.value = true;
};

const onDrop = (event) => {
  const type = event.dataTransfer.getData('field-type');

  if (type) {
    addField(type);
  }

  isDragging.value = false;
};

const onDragEnd = () => {
  isDragging.value = false;
};

const serializeFields = () =>
  builderFields.value.map((field) => {
    const payload = {
      id: field.id,
      type: field.type,
      label: field.label,
      placeholder: field.placeholder,
      default: field.default,
      required: field.required,
      readonly: field.readonly,
      auto_fill: field.auto_fill || null,
      validation: field.validation,
    };

    if (supportsOptions(field.type)) {
      payload.options = normalizeOptions(field.optionsText);
    }

    return payload;
  });

const hydrateFields = (fields = []) =>
  fields.map((field, index) => ({
    uid: field.uid || `field_${Date.now()}_${index}`,
    id: field.id || `field_${index + 1}`,
    type: field.type || 'text',
    label: field.label || 'Untitled Field',
    placeholder: field.placeholder || '',
    default: field.default || '',
    required: Boolean(field.required),
    readonly: Boolean(field.readonly),
    auto_fill: field.auto_fill || '',
    validation: field.validation || '',
    optionsText: Array.isArray(field.options) ? field.options.join(', ') : (field.options || ''),
  }));

const loadWorkflows = async () => {
  const response = await axios.get('/api/workflows');
  workflows.value = response.data.workflows || [];
};

const loadForm = async () => {
  if (!route.params.id) {
    return;
  }

  isEditing.value = true;

  const response = await formStore.fetchForm(route.params.id);
  const payload = response.form;

  form.value = {
    name: payload.name || '',
    description: payload.description || '',
    workflow_id: payload.workflow_id || '',
  };
  builderFields.value = hydrateFields(payload.form_config?.fields || []);
  selectedFieldId.value = builderFields.value[0]?.uid || null;
};

const saveForm = async () => {
  if (isSaving.value) {
    return;
  }

  isSaving.value = true;

  try {
    const payload = {
      ...form.value,
      form_config: {
        fields: serializeFields(),
      },
      is_active: true,
    };

    if (route.params.id) {
      await formStore.updateForm(route.params.id, payload);
    } else {
      await formStore.createForm(payload);
    }

    router.push('/forms');
  } catch (error) {
    console.error('Error saving form:', error);
    alert(error.response?.data?.error || 'Gagal menyimpan form.');
  } finally {
    isSaving.value = false;
  }
};

onMounted(async () => {
  loading.value = true;

  try {
    await Promise.all([loadWorkflows(), loadForm()]);
  } catch (error) {
    console.error('Error loading builder data:', error);
    alert('Builder gagal dimuat.');
    router.push('/forms');
  } finally {
    loading.value = false;
  }
});
</script>
