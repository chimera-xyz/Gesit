<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Admin User Center</p>
        <h1 class="mt-2 text-3xl font-bold text-gray-900">Kelola User</h1>
        <p class="mt-2 text-gray-600">Tambahkan akun internal baru</p>
      </div>

      <button type="button" class="btn-primary" @click="openCreateModal">
        Tambah User
      </button>
    </div>

    <div class="card p-5">
      <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr_1fr_auto]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Cari User</label>
          <input v-model="filters.search" type="text" class="input-field" placeholder="Cari nama, email, divisi, employee ID, atau UserID S21Plus">
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Filter Role</label>
          <select v-model="filters.role" class="select-field">
            <option value="">Semua role</option>
            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
          </select>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
          <select v-model="filters.status" class="select-field">
            <option value="">Semua status</option>
            <option value="active">Aktif</option>
            <option value="inactive">Nonaktif</option>
          </select>
        </div>

        <div class="flex items-end">
          <button type="button" class="btn-secondary w-full" @click="resetFilters">Reset</button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
        <h2 class="text-lg font-medium text-red-900">Error</h2>
        <p class="mt-2 text-red-700">{{ error }}</p>
        <button type="button" class="btn-primary mt-4" @click="loadUsers">Coba Lagi</button>
      </div>
    </div>

    <div v-else class="card overflow-visible">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#efe5d7] px-6 py-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">Daftar User</h2>
          <p class="mt-1 text-sm text-gray-500">{{ filteredUsers.length }} user tampil dari {{ users.length }} user yang tercatat di sistem.</p>
        </div>
        <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
          Admin only
        </span>
      </div>

      <div v-if="filteredUsers.length === 0" class="px-6 py-12 text-center">
        <h3 class="text-lg font-medium text-gray-900">Tidak ada user yang cocok</h3>
        <p class="mt-2 text-sm text-gray-500">Coba ubah filter atau tambahkan user baru.</p>
      </div>

      <div v-else class="overflow-x-auto overflow-y-visible">
        <table class="min-w-full divide-y divide-[#efe5d7]">
          <thead class="bg-[#fffaf1]">
            <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-[#8f6115]">
              <th class="px-6 py-4">User</th>
              <th class="px-6 py-4">Divisi</th>
              <th class="px-6 py-4">Role</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4">Update Terakhir</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[#f3ecdf] bg-white">
            <tr v-for="user in filteredUsers" :key="user.id" class="align-top">
              <td class="px-6 py-5">
                <div class="flex items-start gap-4">
                  <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#9b6b17] text-sm font-semibold text-white">
                    {{ initials(user.name) }}
                  </div>
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-900">{{ user.name }}</p>
                    <p class="mt-1 text-sm text-gray-600">{{ user.email }}</p>
                    <p v-if="user.employee_id" class="mt-1 text-xs text-gray-500">Employee ID: {{ user.employee_id }}</p>
                    <p v-if="user.s21plus_user_id" class="mt-1 text-xs text-gray-500">S21Plus ID: {{ user.s21plus_user_id }}</p>
                    <span v-if="user.is_current_user" class="mt-2 inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                      Akun Anda
                    </span>
                  </div>
                </div>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p>{{ user.department || '-' }}</p>
                <p v-if="user.phone_number" class="mt-2 text-xs text-gray-500">{{ user.phone_number }}</p>
              </td>
              <td class="px-6 py-5">
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="role in user.roles"
                    :key="role"
                    class="rounded-full bg-[#fff4dd] px-3 py-1 text-xs font-semibold text-[#8f6115]"
                  >
                    {{ role }}
                  </span>
                </div>
              </td>
              <td class="px-6 py-5">
                <span
                  class="rounded-full px-3 py-1 text-xs font-semibold"
                  :class="user.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
                >
                  {{ user.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="px-6 py-5 text-sm text-gray-600">
                {{ formatDate(user.updated_at) }}
              </td>
              <td class="relative px-6 py-5 text-right">
                <button
                  type="button"
                  class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-[#efe5d7] bg-white text-gray-500 transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                  @click.stop="toggleMenu(user.id)"
                >
                  <span class="sr-only">Aksi user</span>
                  <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 4.75a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                    <path d="M10 11.25a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                    <path d="M10 17.75a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                  </svg>
                </button>

                <div
                  v-if="openMenuId === user.id"
                  class="absolute right-6 top-16 z-20 w-56 overflow-hidden rounded-3xl border border-[#efe5d7] bg-white p-2 shadow-2xl"
                  @click.stop
                >
                  <button type="button" class="menu-item" @click="openEditModal(user)">
                    Edit User
                  </button>
                  <button
                    type="button"
                    class="menu-item"
                    :disabled="isProcessing(user.id) || user.is_current_user"
                    @click="toggleUserStatus(user)"
                  >
                    {{ isProcessing(user.id) && processingAction === 'toggle'
                      ? 'Memproses...'
                      : (user.is_active ? 'Nonaktifkan User' : 'Aktifkan User') }}
                  </button>
                  <button
                    type="button"
                    class="menu-item menu-item-danger"
                    :disabled="isProcessing(user.id) || user.is_current_user"
                    @click="deleteUser(user)"
                  >
                    {{ isProcessing(user.id) && processingAction === 'delete' ? 'Menghapus...' : 'Arsipkan User' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <UserFormModal
      :open="showModal"
      :mode="modalMode"
      :user="selectedUser"
      :roles="roles"
      :saving="isSaving"
      :errors="formErrors"
      @close="closeModal"
      @submit="submitUser"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useUserStore } from '../../stores/users';
import { useAuthStore } from '../../stores/auth';
import UserFormModal from './UserFormModal.vue';

const userStore = useUserStore();
const authStore = useAuthStore();

const loading = ref(false);
const error = ref(null);
const openMenuId = ref(null);
const processingUserId = ref(null);
const processingAction = ref('');
const showModal = ref(false);
const modalMode = ref('create');
const selectedUser = ref(null);
const isSaving = ref(false);
const formErrors = ref({});

const filters = ref({
  search: '',
  role: '',
  status: '',
});

const users = computed(() => userStore.users);
const roles = computed(() => userStore.roles);

const filteredUsers = computed(() => {
  const search = filters.value.search.trim().toLowerCase();

  return users.value.filter((user) => {
    const matchesSearch = !search || [
      user.name,
      user.email,
      user.department,
      user.employee_id,
      user.s21plus_user_id,
    ].filter(Boolean).some((value) => String(value).toLowerCase().includes(search));

    const matchesRole = !filters.value.role || user.roles.includes(filters.value.role);
    const matchesStatus = !filters.value.status
      || (filters.value.status === 'active' && user.is_active)
      || (filters.value.status === 'inactive' && !user.is_active);

    return matchesSearch && matchesRole && matchesStatus;
  });
});

const initials = (name) => (
  (name || 'User')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')
);

const formatDate = (value) => {
  if (!value) {
    return '-';
  }

  return new Date(value).toLocaleString('id-ID', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const closeMenu = () => {
  openMenuId.value = null;
};

const toggleMenu = (userId) => {
  openMenuId.value = openMenuId.value === userId ? null : userId;
};

const isProcessing = (userId) => processingUserId.value === userId;

const resetFilters = () => {
  filters.value = {
    search: '',
    role: '',
    status: '',
  };
};

const resetFormErrors = () => {
  formErrors.value = {};
};

const openCreateModal = () => {
  modalMode.value = 'create';
  selectedUser.value = null;
  resetFormErrors();
  showModal.value = true;
  closeMenu();
};

const openEditModal = (user) => {
  modalMode.value = 'edit';
  selectedUser.value = user;
  resetFormErrors();
  showModal.value = true;
  closeMenu();
};

const closeModal = () => {
  if (isSaving.value) {
    return;
  }

  showModal.value = false;
  selectedUser.value = null;
  resetFormErrors();
};

const sanitizePayload = (payload) => {
  const cleaned = {
    name: payload.name,
    email: payload.email,
    department: payload.department,
    employee_id: payload.employee_id,
    s21plus_user_id: payload.s21plus_user_id,
    phone_number: payload.phone_number,
    roles: payload.roles,
    is_active: payload.is_active,
  };

  if (payload.password) {
    cleaned.password = payload.password;
    cleaned.password_confirmation = payload.password_confirmation;
  }

  return cleaned;
};

const normalizeErrors = (err) => {
  const messages = err.response?.data?.errors || err.response?.data?.messages || {};
  const mapped = {};

  Object.entries(messages).forEach(([key, value]) => {
    mapped[key] = Array.isArray(value) ? value[0] : value;
  });

  if (!Object.keys(mapped).length && err.response?.data?.error) {
    mapped.general = err.response.data.error;
  }

  return mapped;
};

const submitUser = async (payload) => {
  isSaving.value = true;
  resetFormErrors();

  try {
    const data = sanitizePayload(payload);

    let response;

    if (modalMode.value === 'edit' && selectedUser.value) {
      response = await userStore.updateUser(selectedUser.value.id, data);
    } else {
      response = await userStore.createUser(data);
    }

    if (response.user?.is_current_user) {
      await authStore.fetchUser();
    }

    closeModal();
  } catch (err) {
    console.error('Error saving user:', err);
    formErrors.value = normalizeErrors(err);
  } finally {
    isSaving.value = false;
  }
};

const toggleUserStatus = async (user) => {
  const nextState = !user.is_active;
  const label = nextState ? 'mengaktifkan' : 'menonaktifkan';

  if (!window.confirm(`Yakin ingin ${label} user "${user.name}"?`)) {
    return;
  }

  processingUserId.value = user.id;
  processingAction.value = 'toggle';

  try {
    const response = await userStore.updateUser(user.id, {
      is_active: nextState,
    });

    if (response.user?.is_current_user) {
      await authStore.fetchUser();
    }

    closeMenu();
  } catch (err) {
    console.error('Error toggling user status:', err);
    alert(err.response?.data?.error || 'Status user gagal diperbarui.');
  } finally {
    processingUserId.value = null;
    processingAction.value = '';
  }
};

const deleteUser = async (user) => {
  if (!window.confirm(`Arsipkan user "${user.name}"? User akan hilang dari daftar aktif dan tidak bisa login.`)) {
    return;
  }

  processingUserId.value = user.id;
  processingAction.value = 'delete';

  try {
    await userStore.deleteUser(user.id);
    closeMenu();
  } catch (err) {
    console.error('Error deleting user:', err);
    alert(err.response?.data?.error || 'User gagal diarsipkan.');
  } finally {
    processingUserId.value = null;
    processingAction.value = '';
  }
};

const loadUsers = async () => {
  loading.value = true;
  error.value = null;

  try {
    await userStore.fetchUsers();
  } catch (err) {
    console.error('Error loading users:', err);
    error.value = err.response?.data?.error || 'Daftar user gagal dimuat.';
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', closeMenu);
  loadUsers();
});

onUnmounted(() => {
  document.removeEventListener('click', closeMenu);
});
</script>

<style scoped>
.menu-item {
  width: 100%;
  border: 0;
  background: transparent;
  border-radius: 1rem;
  padding: 0.85rem 1rem;
  text-align: left;
  font-size: 0.95rem;
  color: #334155;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.menu-item:hover:not(:disabled) {
  background: #fbf5ea;
  color: #8f6115;
}

.menu-item:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.menu-item-danger {
  color: #b42318;
}

.menu-item-danger:hover:not(:disabled) {
  background: #fef3f2;
  color: #b42318;
}
</style>
