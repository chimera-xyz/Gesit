<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-[#1f2937]/45 px-4 py-6">
    <div class="card max-h-[92vh] w-full max-w-5xl overflow-y-auto p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Admin User Management</p>
          <h2 class="mt-2 text-2xl font-semibold text-gray-900">{{ title }}</h2>
          <p class="mt-2 text-sm text-gray-600">
            {{ isEdit
              ? 'Perbarui data akun, role, dan aplikasi kerja yang bisa diakses user.'
              : 'Tambahkan user internal baru lengkap dengan role, akses aplikasi, dan kredensial awal.' }}
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
          <label class="mb-2 block text-sm font-medium text-gray-700">UserID S21Plus</label>
          <input v-model="form.s21plus_user_id" type="text" class="input-field" placeholder="Contoh: lina">
          <p class="mt-2 text-xs text-gray-500">Dipakai untuk fitur self-service unblock akun S21Plus.</p>
          <p v-if="errors.s21plus_user_id" class="mt-2 text-sm text-red-700">{{ errors.s21plus_user_id }}</p>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Nomor Telepon</label>
          <input v-model="form.phone_number" type="text" class="input-field" placeholder="Opsional">
          <p v-if="errors.phone_number" class="mt-2 text-sm text-red-700">{{ errors.phone_number }}</p>
        </div>

        <div class="rounded-[1.25rem] border border-[#efe5d7] bg-[#fffaf1] px-5 py-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-gray-900">Status Akun</p>
              <p class="mt-1 text-xs text-gray-500">User nonaktif tidak bisa login ke portal.</p>
            </div>
            <label class="inline-flex items-center gap-3 text-sm font-medium text-gray-700">
              <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[#ccb58e] text-[#9b6b17] focus:ring-[#ead39a]">
              Aktif
            </label>
          </div>
        </div>

        <div class="rounded-[1.25rem] border border-[#efe5d7] bg-[#fcfbf8] px-5 py-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-gray-900">Role Internal</p>
              <p class="mt-1 text-xs text-gray-500">Tentukan menu dan fitur Gesit yang boleh diakses user.</p>
            </div>
            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-[#8f6115]">
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

        <div class="rounded-[1.4rem] border border-[#dbeafe] bg-[#f7fbff] px-5 py-5 lg:col-span-2">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-sm font-semibold text-gray-900">Akses Aplikasi</p>
              <p class="mt-1 text-xs text-gray-500">
                Pilih aplikasi yang boleh dibuka dari portal Gesit dan atur landing default setelah login.
              </p>
            </div>
            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-[#1f5f8b]">
              {{ form.allowed_apps.length }} aplikasi aktif
            </span>
          </div>

          <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <label
              v-for="app in appCatalog"
              :key="app.key"
              class="flex items-start gap-3 rounded-[1.2rem] border px-4 py-4 transition"
              :class="form.allowed_apps.includes(app.key) ? 'border-[#9ec5ea] bg-white' : 'border-[#d7e6f3] bg-[#fbfdff]'"
            >
              <input
                :checked="form.allowed_apps.includes(app.key)"
                type="checkbox"
                class="mt-1 h-4 w-4 rounded border-[#7ca8c9] text-[#1f5f8b] focus:ring-[#bfdbfe]"
                @change="toggleAppAccess(app.key)"
              >
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-sm font-semibold text-gray-900">{{ app.name }}</p>
                  <span
                    v-if="app.badge"
                    class="rounded-full bg-[#eef6ff] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-[#1f5f8b]"
                  >
                    {{ app.badge }}
                  </span>
                </div>
                <p class="mt-1 text-sm leading-6 text-[#5f6672]">{{ app.description }}</p>
              </div>
            </label>
          </div>
          <p v-if="errors.allowed_apps" class="mt-3 text-sm text-red-700">{{ errors.allowed_apps }}</p>

          <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,20rem)]">
            <div class="rounded-[1.2rem] border border-[#d7e6f3] bg-white px-4 py-4">
              <p class="text-sm font-semibold text-gray-900">Catatan portal</p>
              <p class="mt-2 text-sm leading-6 text-[#5f6672]">
                User inventaris-only tetap login lewat Gesit, tetapi sesudah login akan langsung diarahkan ke dashboard Inventaris IT.
              </p>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-gray-700">Landing Default</label>
              <select v-model="form.home_app" class="select-field" :disabled="availableHomeApps.length === 0">
                <option value="" disabled>Pilih aplikasi default</option>
                <option v-for="app in availableHomeApps" :key="app.key" :value="app.key">{{ app.name }}</option>
              </select>
              <p class="mt-2 text-xs text-gray-500">Default ini dipakai setelah login jika user tidak membuka halaman tertentu dulu.</p>
              <p v-if="errors.home_app" class="mt-2 text-sm text-red-700">{{ errors.home_app }}</p>
            </div>
          </div>
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
  appCatalog: {
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

const defaultAllowedApps = () => (
  props.appCatalog.some((app) => app.key === 'gesit') ? ['gesit'] : props.appCatalog.slice(0, 1).map((app) => app.key)
);

const defaultHomeApp = (allowedApps) => (
  allowedApps.includes('gesit') ? 'gesit' : (allowedApps[0] || '')
);

const emptyForm = () => {
  const allowedApps = defaultAllowedApps();

  return {
    name: '',
    email: '',
    department: '',
    employee_id: '',
    s21plus_user_id: '',
    phone_number: '',
    roles: [],
    is_active: true,
    allowed_apps: allowedApps,
    home_app: defaultHomeApp(allowedApps),
    password: '',
    password_confirmation: '',
  };
};

const form = reactive(emptyForm());

const availableHomeApps = computed(() => (
  props.appCatalog.filter((app) => form.allowed_apps.includes(app.key))
));

const syncHomeApp = () => {
  if (form.home_app && form.allowed_apps.includes(form.home_app)) {
    return;
  }

  form.home_app = defaultHomeApp(form.allowed_apps);
};

const syncForm = () => {
  const nextForm = props.user
    ? {
        name: props.user.name || '',
        email: props.user.email || '',
        department: props.user.department || '',
        employee_id: props.user.employee_id || '',
        s21plus_user_id: props.user.s21plus_user_id || '',
        phone_number: props.user.phone_number || '',
        roles: Array.isArray(props.user.roles) ? [...props.user.roles] : [],
        is_active: props.user.is_active ?? true,
        allowed_apps: Array.isArray(props.user.allowed_apps) && props.user.allowed_apps.length
          ? [...props.user.allowed_apps]
          : defaultAllowedApps(),
        home_app: props.user.home_app || '',
        password: '',
        password_confirmation: '',
      }
    : emptyForm();

  Object.assign(form, nextForm);
  syncHomeApp();
};

const toggleAppAccess = (appKey) => {
  if (form.allowed_apps.includes(appKey)) {
    form.allowed_apps = form.allowed_apps.filter((value) => value !== appKey);
  } else {
    form.allowed_apps = [...form.allowed_apps, appKey];
  }

  syncHomeApp();
};

watch(
  () => [props.open, props.user, props.mode, props.appCatalog],
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
    s21plus_user_id: form.s21plus_user_id,
    phone_number: form.phone_number,
    roles: form.roles,
    is_active: form.is_active,
    allowed_apps: form.allowed_apps,
    home_app: form.home_app || null,
    password: form.password,
    password_confirmation: form.password_confirmation,
  });
};
</script>
