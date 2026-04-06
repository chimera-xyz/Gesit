<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-[#1f2937]/45 px-4 py-6">
    <div class="card max-h-[92vh] w-full max-w-4xl overflow-y-auto p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Admin User Management</p>
          <h2 class="mt-2 text-2xl font-semibold text-gray-900">{{ title }}</h2>
          <p class="mt-2 text-sm text-gray-600">
            {{ isEdit
              ? 'Perbarui data akun, role, dan status akses user.'
              : 'Tambahkan user internal baru lengkap dengan role dan kredensial awal.' }}
          </p>
        </div>

        <button type="button" class="text-sm font-medium text-gray-500" :disabled="saving" @click="$emit('close')">
          Tutup
        </button>
      </div>

      <div v-if="errors.general" class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errors.general }}
      </div>

      <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Nama Lengkap</label>
          <input v-model="form.name" type="text" class="input-field" placeholder="Contoh: Rais Adam">
          <p v-if="errors.name" class="mt-2 text-sm text-red-700">{{ errors.name }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Email Login</label>
          <input v-model="form.email" type="email" class="input-field" placeholder="nama@company.com">
          <p v-if="errors.email" class="mt-2 text-sm text-red-700">{{ errors.email }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Divisi</label>
          <input v-model="form.department" type="text" class="input-field" placeholder="Contoh: IT, Finance, General Affairs">
          <p v-if="errors.department" class="mt-2 text-sm text-red-700">{{ errors.department }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Employee ID</label>
          <input v-model="form.employee_id" type="text" class="input-field" placeholder="Opsional">
          <p v-if="errors.employee_id" class="mt-2 text-sm text-red-700">{{ errors.employee_id }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Nomor Telepon</label>
          <input v-model="form.phone_number" type="text" class="input-field" placeholder="Opsional">
          <p v-if="errors.phone_number" class="mt-2 text-sm text-red-700">{{ errors.phone_number }}</p>
        </div>

        <div class="rounded-[1.25rem] border border-gray-200 bg-[#fffdf9] px-4 py-4">
          <label class="flex items-center gap-3">
            <input v-model="form.is_active" type="checkbox">
            <span class="text-sm font-medium text-gray-800">User aktif dan bisa login</span>
          </label>
          <p class="mt-2 text-xs leading-5 text-gray-500">Jika dinonaktifkan, sesi user akan diputus dan akun tidak bisa login.</p>
        </div>

        <div class="lg:col-span-2">
          <div class="flex items-center justify-between gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Role User</label>
              <p class="mt-1 text-xs text-gray-500">Bisa pilih lebih dari satu role sesuai kebutuhan operasional.</p>
            </div>
            <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
              {{ form.roles.length }} role dipilih
            </span>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label
              v-for="role in roles"
              :key="role"
              class="flex items-center gap-3 rounded-[1.1rem] border px-4 py-3 transition"
              :class="form.roles.includes(role) ? 'border-primary-300 bg-primary-50' : 'border-gray-200 bg-white'"
            >
              <input v-model="form.roles" type="checkbox" :value="role">
              <span class="text-sm font-medium text-gray-800">{{ role }}</span>
            </label>
          </div>
          <p v-if="errors.roles" class="mt-2 text-sm text-red-700">{{ errors.roles }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">
            {{ isEdit ? 'Password Baru' : 'Password Awal' }}
          </label>
          <input
            v-model="form.password"
            type="password"
            class="input-field"
            :placeholder="isEdit ? 'Kosongkan jika tidak ingin mengganti password' : 'Minimal 8 karakter'"
          >
          <p class="mt-2 text-xs text-gray-500">
            {{ isEdit ? 'User lama tidak wajib diganti password-nya.' : 'Password ini akan dipakai user saat login pertama.' }}
          </p>
          <p v-if="errors.password" class="mt-2 text-sm text-red-700">{{ errors.password }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">
            {{ isEdit ? 'Konfirmasi Password Baru' : 'Konfirmasi Password Awal' }}
          </label>
          <input
            v-model="form.password_confirmation"
            type="password"
            class="input-field"
            :placeholder="isEdit ? 'Isi jika mengganti password' : 'Ulangi password awal'"
          >
          <p v-if="errors.password_confirmation" class="mt-2 text-sm text-red-700">{{ errors.password_confirmation }}</p>
        </div>
      </div>

      <div class="mt-8 flex flex-wrap justify-end gap-3">
        <button type="button" class="btn-secondary" :disabled="saving" @click="$emit('close')">Batal</button>
        <button type="button" class="btn-primary" :disabled="saving" @click="submit">
          {{ saving ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan' : 'Buat User') }}
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
  user: {
    type: Object,
    default: null,
  },
  roles: {
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
const title = computed(() => isEdit.value ? 'Edit User' : 'Tambah User Baru');

const emptyForm = () => ({
  name: '',
  email: '',
  department: '',
  employee_id: '',
  phone_number: '',
  roles: [],
  is_active: true,
  password: '',
  password_confirmation: '',
});

const form = reactive(emptyForm());

const syncForm = () => {
  const nextForm = props.user
    ? {
        name: props.user.name || '',
        email: props.user.email || '',
        department: props.user.department || '',
        employee_id: props.user.employee_id || '',
        phone_number: props.user.phone_number || '',
        roles: Array.isArray(props.user.roles) ? [...props.user.roles] : [],
        is_active: props.user.is_active ?? true,
        password: '',
        password_confirmation: '',
      }
    : emptyForm();

  Object.assign(form, nextForm);
};

watch(
  () => [props.open, props.user, props.mode],
  () => {
    if (!props.open) {
      return;
    }

    syncForm();
  },
  { deep: true, immediate: true }
);

const submit = () => {
  emit('submit', {
    name: form.name,
    email: form.email,
    department: form.department,
    employee_id: form.employee_id,
    phone_number: form.phone_number,
    roles: form.roles,
    is_active: form.is_active,
    password: form.password,
    password_confirmation: form.password_confirmation,
  });
};
</script>
