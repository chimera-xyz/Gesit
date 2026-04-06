<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-[#1f2937]/45 px-4 py-6">
    <div class="card max-h-[92vh] w-full max-w-5xl overflow-y-auto p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Admin Role Management</p>
          <h2 class="mt-2 text-2xl font-semibold text-gray-900">{{ title }}</h2>
          <p class="mt-2 text-sm text-gray-600">
            {{ isEdit
              ? 'Atur nama role, status aktif, dan permission yang dimiliki role ini.'
              : 'Buat role custom baru lalu tentukan permission yang boleh diakses.' }}
          </p>
        </div>

        <button type="button" class="text-sm font-medium text-gray-500" :disabled="saving" @click="$emit('close')">
          Tutup
        </button>
      </div>

      <div v-if="errors.general" class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errors.general }}
      </div>

      <div v-if="isSystemRole" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Ini adalah role sistem. Nama dan statusnya dikunci agar alur approval inti tidak rusak, tetapi permission-nya tetap bisa Anda sesuaikan.
      </div>

      <div class="mt-6 grid gap-5 lg:grid-cols-[1.25fr_0.75fr]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Nama Role</label>
          <input
            v-model="form.name"
            type="text"
            class="input-field"
            placeholder="Contoh: Procurement Reviewer"
            :disabled="isSystemRole"
          >
          <p v-if="errors.name" class="mt-2 text-sm text-red-700">{{ errors.name }}</p>
        </div>

        <div class="rounded-[1.25rem] border border-gray-200 bg-[#fffdf9] px-4 py-4">
          <label class="flex items-center gap-3">
            <input v-model="form.is_active" type="checkbox" :disabled="isSystemRole">
            <span class="text-sm font-medium text-gray-800">Role aktif dan bisa dipakai</span>
          </label>
          <p class="mt-2 text-xs leading-5 text-gray-500">Role yang nonaktif tidak muncul di assignment user dan workflow baru.</p>
        </div>

        <div class="lg:col-span-2">
          <div class="flex items-center justify-between gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Permission Role</label>
              <p class="mt-1 text-xs text-gray-500">Pilih permission sesuai akses yang benar-benar dibutuhkan role ini.</p>
            </div>
            <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
              {{ form.permissions.length }} permission
            </span>
          </div>

          <div class="mt-4 space-y-5">
            <section
              v-for="group in groupedPermissions"
              :key="group.name"
              class="rounded-[1.25rem] border border-gray-200 bg-white p-4"
            >
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-[#8f6115]">{{ group.name }}</h3>
                  <p class="mt-1 text-xs text-gray-500">{{ group.permissions.length }} permission tersedia</p>
                </div>

                <button type="button" class="text-sm font-medium text-[#8f6115]" @click="toggleGroup(group.permissions)">
                  {{ isGroupFullySelected(group.permissions) ? 'Batalkan Semua' : 'Pilih Semua' }}
                </button>
              </div>

              <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <label
                  v-for="permission in group.permissions"
                  :key="permission"
                  class="flex items-center gap-3 rounded-[1rem] border px-4 py-3 transition"
                  :class="form.permissions.includes(permission) ? 'border-primary-300 bg-primary-50' : 'border-gray-200 bg-white'"
                >
                  <input v-model="form.permissions" type="checkbox" :value="permission">
                  <span class="text-sm font-medium text-gray-800">{{ permission }}</span>
                </label>
              </div>
            </section>
          </div>

          <p v-if="errors.permissions" class="mt-2 text-sm text-red-700">{{ errors.permissions }}</p>
        </div>
      </div>

      <div class="mt-8 flex flex-wrap justify-end gap-3">
        <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">Batal</button>
        <button type="button" class="btn-primary" :disabled="saving" @click="submit">
          {{ saving ? 'Menyimpan...' : (isEdit ? 'Simpan Role' : 'Buat Role') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'create',
  },
  role: {
    type: Object,
    default: null,
  },
  permissions: {
    type: Array,
    default: () => [],
  },
  saving: {
    type: Boolean,
    default: false,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
});

const emit = defineEmits(['close', 'submit']);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => isEdit.value ? 'Edit Role' : 'Tambah Role Baru');
const isSystemRole = computed(() => Boolean(props.role?.is_system));

const emptyForm = () => ({
  name: '',
  is_active: true,
  permissions: [],
});

const form = reactive(emptyForm());

const groupedPermissions = computed(() => {
  const groups = new Map();

  props.permissions.forEach((permission) => {
    const parts = permission.split(' ');
    const groupName = parts[parts.length - 1] || 'lainnya';
    const label = groupName.charAt(0).toUpperCase() + groupName.slice(1);

    if (!groups.has(label)) {
      groups.set(label, []);
    }

    groups.get(label).push(permission);
  });

  return [...groups.entries()]
    .map(([name, permissions]) => ({
      name,
      permissions: [...permissions].sort((left, right) => left.localeCompare(right, 'id')),
    }))
    .sort((left, right) => left.name.localeCompare(right.name, 'id'));
});

const syncForm = () => {
  const nextForm = props.role
    ? {
        name: props.role.name || '',
        is_active: props.role.is_active ?? true,
        permissions: Array.isArray(props.role.permissions) ? [...props.role.permissions] : [],
      }
    : emptyForm();

  Object.assign(form, nextForm);
};

watch(
  () => [props.open, props.role, props.mode],
  () => {
    if (!props.open) {
      return;
    }

    syncForm();
  },
  { deep: true, immediate: true }
);

const isGroupFullySelected = (permissions) => permissions.every((permission) => form.permissions.includes(permission));

const toggleGroup = (permissions) => {
  if (isGroupFullySelected(permissions)) {
    form.permissions = form.permissions.filter((permission) => !permissions.includes(permission));
    return;
  }

  form.permissions = [...new Set([...form.permissions, ...permissions])];
};

const submit = () => {
  emit('submit', {
    name: form.name,
    is_active: form.is_active,
    permissions: form.permissions,
  });
};
</script>
