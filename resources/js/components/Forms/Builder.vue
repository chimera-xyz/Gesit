<template>
  <div class="form-builder">
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900">{{ isEditing ? 'Edit Form' : 'Create New Form' }}</h1>
      <p class="mt-2 text-gray-600">{{ isEditing ? 'Update the form configuration' : 'Design your form using drag and drop' }}</p>
    </div>

    <!-- Form Details -->
    <div class="card p-6 mb-6">
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Form Name</label>
          <input
            v-model="form.name"
            type="text"
            class="input-field"
            placeholder="e.g., Hardware Procurement Form"
          >
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
          <textarea
            v-model="form.description"
            class="input-field"
            rows="3"
            placeholder="Describe the purpose of this form"
          ></textarea>
        </div>
      </div>
      <div class="mt-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">Workflow</label>
        <select v-model="form.workflow_id" class="select-field">
          <option value="">Select a workflow</option>
          <option v-for="workflow in workflows" :key="workflow.id" :value="workflow.id">
            {{ workflow.name }}
          </option>
        </select>
      </div>
    </div>

    <!-- Form Builder Toolbar -->
    <div class="card p-4 mb-6">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900">Field Toolbox</h3>
        <div class="flex space-x-2">
          <button
            @click="addField('text')"
            class="btn-secondary text-sm"
          >
            + Text
          </button>
          <button
            @click="addField('textarea')"
            class="btn-secondary text-sm"
          >
            + Textarea
          </button>
          <button
            @click="addField('number')"
            class="btn-secondary text-sm"
          >
            + Number
          </button>
          <button
            @click="addField('select')"
            class="btn-secondary text-sm"
          >
            + Select
          </button>
          <button
            @click="addField('date')"
            class="btn-secondary text-sm"
          >
            + Date
          </button>
          <button
            @click="addField('file')"
            class="btn-secondary text-sm"
          >
            + File
          </button>
        </div>
      </div>
    </div>

    <!-- Form Canvas (Drop Zone) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Field Toolbox -->
      <div class="lg:col-span-1">
        <div class="card p-4">
          <h3 class="text-sm font-medium text-gray-900 mb-4">Available Fields</h3>
          <div class="space-y-2">
            <div
              v-for="fieldType in availableFieldTypes"
              :key="fieldType.type"
              draggable="true"
              @dragstart="onDragStart($event, fieldType)"
              @dragend="onDragEnd"
              class="form-builder-field cursor-move hover:shadow-md"
            >
              <div class="flex items-center">
                <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path v-if="fieldType.icon === 'text'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                  <path v-if="fieldType.icon === 'textarea'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h16M4 8h16M4 12h16M4 16h16M4 20h16" />
                  <path v-if="fieldType.icon === 'number'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M7 20h.01M17 20h.01" />
                  <path v-if="fieldType.icon === 'select'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  <path v-if="fieldType.icon === 'date'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h.01M16 20h-8m8-5v.01M12 9v.01M3 17a2 2 0 012 2h10a2 2 0 012-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  <path v-if="fieldType.icon === 'file'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m0 0l-3-3m6 0l3 3m-6-5h.01M12 12h.01M12 9h.01M12 16h.01" />
                </svg>
                <span class="text-sm text-gray-700">{{ fieldType.label }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Form Canvas -->
      <div class="lg:col-span-2">
        <div
          class="card p-6 min-h-96"
          @dragover.prevent="onDragOver"
          @dragenter.prevent="onDragEnter"
          @dragleave="onDragLeave"
          @drop.prevent="onDrop"
          :class="{ 'border-primary-500 border-2': isDragging }"
        >
          <div v-if="builderFields.length === 0" class="flex flex-col items-center justify-center h-64 text-gray-400">
            <svg class="h-12 w-12 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0 0h6m-6 5h.01M12 9h.01M12 15h.01" />
            </svg>
            <p class="text-sm">Drag fields here to build your form</p>
          </div>

          <div v-else class="space-y-4">
            <div
              v-for="(field, index) in builderFields"
              :key="field.id"
              class="form-builder-field relative group"
              :class="{ 'selected': selectedFieldId === field.id }"
            >
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-900">{{ field.label || 'Untitled Field' }}</span>
                <div class="flex space-x-2">
                  <button
                    @click="selectField(field.id)"
                    class="text-gray-400 hover:text-primary-600 transition-colors"
                    title="Edit Field"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414 9.414a1 1 0 01-.293.707l-3-3a1 1 0 01-.707-.293l-1.414 1.414A1 1 0 003 15v4a1 1 0 001 1h2a1 1 0 001 1v4a1 1 0 001 1h1a2 2 0 01-2 2V9a2 2 0 00-2-2H5a2 2 0 00-2 2v9a2 2 0 002 2h1.414a1 1 0 01.707-.293l2.414-2.414a1 1 0 01.293-.707L19 13.414z" />
                    </svg>
                  </button>
                  <button
                    @click="removeField(field.id)"
                    class="text-gray-400 hover:text-red-600 transition-colors"
                    title="Remove Field"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Field Configuration Panel -->
              <div v-if="selectedFieldId === field.id" class="mt-4 p-4 bg-gray-50 rounded-lg">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Field Label</label>
                    <input
                      v-model="field.label"
                      type="text"
                      class="input-field text-sm"
                      placeholder="Field Label"
                    >
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Field ID</label>
                    <input
                      v-model="field.id"
                      type="text"
                      class="input-field text-sm"
                      placeholder="field_id"
                    >
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Placeholder</label>
                    <input
                      v-model="field.placeholder"
                      type="text"
                      class="input-field text-sm"
                      placeholder="Placeholder text"
                    >
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Default Value</label>
                    <input
                      v-model="field.default"
                      type="text"
                      class="input-field text-sm"
                      placeholder="Default value"
                    >
                  </div>
                  <div class="flex items-center">
                    <input
                      v-model="field.required"
                      type="checkbox"
                      class="h-4 w-4 text-primary-600 rounded border-gray-300 focus:ring-primary-500"
                    >
                    <label class="ml-2 text-xs font-medium text-gray-700">Required Field</label>
                  </div>
                  <div v-if="field.type === 'select'">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Options (comma separated)</label>
                    <input
                      v-model="field.options"
                      type="text"
                      class="input-field text-sm"
                      placeholder="Option 1, Option 2, Option 3"
                    >
                  </div>
                </div>
                <div class="mt-4">
                  <label class="block text-xs font-medium text-gray-700 mb-1">Validation Rules</label>
                  <input
                    v-model="field.validation"
                    type="text"
                    class="input-field text-sm"
                    placeholder="e.g., required|string|max:255"
                  >
                </div>
              </div>

              <!-- Field Preview -->
              <div class="mt-4 p-3 bg-white border border-gray-200 rounded">
                <label class="block text-xs font-medium text-gray-700 mb-1">{{ field.label || 'Preview' }}</label>
                <input
                  v-if="field.type === 'text'"
                  type="text"
                  class="input-field text-sm"
                  :placeholder="field.placeholder"
                  :required="field.required"
                >
                <textarea
                  v-if="field.type === 'textarea'"
                  class="input-field text-sm"
                  rows="3"
                  :placeholder="field.placeholder"
                  :required="field.required"
                ></textarea>
                <input
                  v-if="field.type === 'number'"
                  type="number"
                  class="input-field text-sm"
                  :placeholder="field.placeholder"
                  :required="field.required"
                >
                <select
                  v-if="field.type === 'select'"
                  class="select-field text-sm"
                  :required="field.required"
                >
                  <option value="">Select an option</option>
                  <option v-for="(option, idx) in field.options?.split(',') || []" :key="idx" :value="option.trim()">
                    {{ option.trim() }}
                  </option>
                </select>
                <input
                  v-if="field.type === 'date'"
                  type="date"
                  class="input-field text-sm"
                  :required="field.required"
                >
                <input
                  v-if="field.type === 'file'"
                  type="file"
                  class="text-sm"
                  :required="field.required"
                >
              </div>
            </div>

            <!-- Add Field Between -->
            <div
              v-if="index < builderFields.length - 1"
              @click="addFieldAtIndex(index + 1)"
              class="flex items-center justify-center py-4 border-t border-dashed border-gray-300 cursor-pointer hover:bg-gray-50"
            >
              <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0 0h6m-6 5h.01M12 12h.01M12 9h.01" />
              </svg>
              <span class="text-sm text-gray-500">Add field here</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex justify-end space-x-3 mt-6">
      <button
        @click="saveForm"
        :disabled="isSaving || !form.name || builderFields.length === 0"
        class="btn-primary"
      >
        {{ isSaving ? 'Saving...' : (isEditing ? 'Update Form' : 'Create Form') }}
      </button>
      <router-link
        to="/forms"
        class="btn-secondary"
      >
        Cancel
      </router-link>
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

const isEditing = ref(false);
const isSaving = ref(false);
const isDragging = ref(false);
const selectedFieldId = ref(null);

const form = ref({
  name: '',
  description: '',
  workflow_id: '',
});

const builderFields = ref([]);

const workflows = ref([]);

const availableFieldTypes = [
  { type: 'text', label: 'Text Input', icon: 'text' },
  { type: 'textarea', label: 'Text Area', icon: 'textarea' },
  { type: 'number', label: 'Number Input', icon: 'number' },
  { type: 'select', label: 'Select Dropdown', icon: 'select' },
  { type: 'date', label: 'Date Picker', icon: 'date' },
  { type: 'file', label: 'File Upload', icon: 'file' },
];

const selectField = (fieldId) => {
  selectedFieldId.value = selectedFieldId.value === fieldId ? null : fieldId;
};

const addField = (type) => {
  const newField = {
    id: `field_${Date.now()}`,
    type: type,
    label: `${type.charAt(0).toUpperCase() + type.slice(1)} Field`,
    placeholder: '',
    required: false,
    validation: '',
    options: '',
  };
  builderFields.value.push(newField);
  selectField(newField.id);
};

const addFieldAtIndex = (index) => {
  const newField = {
    id: `field_${Date.now()}`,
    type: 'text',
    label: 'New Field',
    placeholder: '',
    required: false,
    validation: '',
    options: '',
  };
  builderFields.value.splice(index, 0, newField);
  selectField(newField.id);
};

const removeField = (fieldId) => {
  builderFields.value = builderFields.value.filter(field => field.id !== fieldId);
  if (selectedFieldId.value === fieldId) {
    selectedFieldId.value = null;
  }
};

const onDragStart = (event, fieldType) => {
  isDragging.value = true;
  event.dataTransfer.effectAllowed = 'copy';
  event.dataTransfer.setData('fieldType', JSON.stringify(fieldType));
};

const onDragOver = (event) => {
  event.preventDefault();
  event.dataTransfer.dropEffect = 'copy';
};

const onDragEnter = (event) => {
  event.preventDefault();
};

const onDragLeave = (event) => {
  event.preventDefault();
};

const onDrop = (event) => {
  event.preventDefault();
  isDragging.value = false;

  const fieldTypeData = event.dataTransfer.getData('fieldType');
  if (fieldTypeData) {
    const fieldType = JSON.parse(fieldTypeData);
    addField(fieldType.type);
  }
};

const onDragEnd = () => {
  isDragging.value = false;
};

const saveForm = async () => {
  if (isSaving.value) return;

  isSaving.value = true;

  try {
    const formData = {
      ...form.value,
      form_config: {
        fields: builderFields.value,
      },
    };

    if (route.params.id) {
      await formStore.updateForm(route.params.id, formData);
    } else {
      await formStore.createForm(formData);
    }

    router.push('/forms');
  } catch (error) {
    console.error('Error saving form:', error);
    alert('Failed to save form. Please try again.');
  } finally {
    isSaving.value = false;
  }
};

const loadForm = async () => {
  if (route.params.id) {
    isEditing.value = true;
    try {
      const formData = await formStore.fetchForm(route.params.id);
      form.value = {
        name: formData.name,
        description: formData.description,
        workflow_id: formData.workflow_id,
      };
      builderFields.value = formData.form_config?.fields || [];
    } catch (error) {
      console.error('Error loading form:', error);
      router.push('/forms');
    }
  }
};

onMounted(() => {
  loadForm();
});
</script>