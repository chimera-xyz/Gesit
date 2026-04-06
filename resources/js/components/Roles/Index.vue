<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Admin Role Center</p>
        <h1 class="mt-2 text-3xl font-bold text-gray-900">Kelola Role</h1>
        <p class="mt-2 text-gray-600">Manage Role System</p>
      </div>

      <button type="button" class="btn-primary" @click="openCreateModal">
        Tambah Role
      </button>
    </div>

    <div class="card p-5">
      <div class="grid gap-4 lg:grid-cols-[1.5fr_1fr_1fr_auto]">
        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Cari Role</label>
          <input v-model="filters.search" type="text" class="input-field" placeholder="Cari nama role atau permission">
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
          <select v-model="filters.status" class="select-field">
            <option value="">Semua status</option>
            <option value="active">Aktif</option>
            <option value="inactive">Nonaktif</option>
          </select>
        </div>

        <div>
          <label class="mb-2 block text-sm font-medium text-gray-700">Tipe Role</label>
          <select v-model="filters.type" class="select-field">
            <option value="">Semua role</option>
            <option value="system">System role</option>
            <option value="custom">Custom role</option>
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
        <button type="button" class="btn-primary mt-4" @click="loadRoles">Coba Lagi</button>
      </div>
    </div>

    <div v-else class="card overflow-visible">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#efe5d7] px-6 py-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">Daftar Role</h2>
          <p class="mt-1 text-sm text-gray-500">{{ filteredRoles.length }} role tampil dari {{ roles.length }} role yang tercatat di sistem.</p>
        </div>
        <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
          Permission aware
        </span>
      </div>

      <div v-if="filteredRoles.length === 0" class="px-6 py-12 text-center">
        <h3 class="text-lg font-medium text-gray-900">Tidak ada role yang cocok</h3>
        <p class="mt-2 text-sm text-gray-500">Coba ubah filter atau buat role custom baru.</p>
      </div>

      <div v-else class="overflow-x-auto overflow-y-visible">
        <table class="min-w-full divide-y divide-[#efe5d7]">
          <thead class="bg-[#fffaf1]">
            <tr class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-[#8f6115]">
              <th class="px-6 py-4">Role</th>
              <th class="px-6 py-4">Permission</th>
              <th class="px-6 py-4">Pemakaian</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4">Update Terakhir</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[#f3ecdf] bg-white">
            <tr v-for="role in filteredRoles" :key="role.id" class="align-top">
              <td class="px-6 py-5">
                <div class="flex items-start gap-3">
                  <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#9b6b17] text-sm font-semibold text-white">
                    {{ role.name.slice(0, 2).toUpperCase() }}
                  </div>
                  <div>
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="font-semibold text-gray-900">{{ role.name }}</p>
                      <span v-if="role.is_system" class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                        System
                      </span>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">
                      {{ role.is_system
                        ? 'Role inti sistem. Nama dan status dikunci, permission masih bisa disesuaikan.'
                        : 'Role custom yang bisa Anda rename, nonaktifkan, atau hapus jika aman.' }}
                    </p>
                  </div>
                </div>
              </td>
              <td class="px-6 py-5">
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="permission in role.permissions"
                    :key="permission"
                    class="rounded-full bg-[#fff4dd] px-3 py-1 text-xs font-semibold text-[#8f6115]"
                  >
                    {{ permission }}
                  </span>
                  <span v-if="role.permissions.length === 0" class="text-sm text-gray-500">Belum ada permission.</span>
                </div>
              </td>
              <td class="px-6 py-5 text-sm text-gray-700">
                <p>{{ role.users_count }} user</p>
                <p class="mt-2">{{ role.workflows_count }} workflow</p>
                <p class="mt-2 text-xs text-gray-500">{{ role.pending_approvals_count }} approval aktif</p>
              </td>
              <td class="px-6 py-5">
                <span
                  class="rounded-full px-3 py-1 text-xs font-semibold"
                  :class="role.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
                >
                  {{ role.is_active ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="px-6 py-5 text-sm text-gray-600">
                {{ formatDate(role.updated_at) }}
              </td>
              <td class="relative px-6 py-5 text-right">
                <button
                  type="button"
                  class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-[#efe5d7] bg-white text-gray-500 transition hover:border-[#d8bc84] hover:text-[#8f6115]"
                  @click.stop="toggleMenu(role.id)"
                >
                  <span class="sr-only">Aksi role</span>
                  <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 4.75a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                    <path d="M10 11.25a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                    <path d="M10 17.75a1.25 1.25 0 1 0 0-2.5 1.25 1.25 0 0 0 0 2.5Z" />
                  </svg>
                </button>

                <div
                  v-if="openMenuId === role.id"
                  class="absolute right-6 top-16 z-20 w-56 overflow-hidden rounded-3xl border border-[#efe5d7] bg-white p-2 shadow-2xl"
                  @click.stop
                >
                  <button type="button" class="menu-item" @click="openEditModal(role)">
                    Edit Role
                  </button>
                  <button
                    type="button"
                    class="menu-item"
                    :disabled="isProcessing(role.id) || !role.can_toggle_active"
                    @click="toggleRoleStatus(role)"
                  >
                    {{ isProcessing(role.id) && processingAction === 'toggle'
                      ? 'Memproses...'
                      : (role.is_active ? 'Nonaktifkan Role' : 'Aktifkan Role') }}
                  </button>
                  <button
                    type="button"
                    class="menu-item menu-item-danger"
                    :disabled="isProcessing(role.id) || !role.can_delete"
                    @click="deleteRole(role)"
                  >
                    {{ isProcessing(role.id) && processingAction === 'delete' ? 'Menghapus...' : 'Hapus Role' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <RoleFormModal
      :open="showModal"
      :mode="modalMode"
      :role="selectedRole"
      :permissions="permissions"
      :saving="isSaving"
      :errors="formErrors"
      @close="closeModal"
      @submit="submitRole"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoleStore } from '../../stores/roles';
import { useUserStore } from '../../stores/users';
import RoleFormModal from './RoleFormModal.vue';

const roleStore = useRoleStore();
const userStore = useUserStore();

const loading = ref(false);
const error = ref(null);
const filters = ref({
  search: '',
  status: '',
  type: '',
});
const openMenuId = ref(null);
const processingRoleId = ref(null);
const processingAction = ref('');
const showModal = ref(false);
const modalMode = ref('create');
const selectedRole = ref(null);
const isSaving = ref(false);
const formErrors = ref({});

const roles = computed(() => roleStore.roles);
const permissions = computed(() => roleStore.permissions);

const filteredRoles = computed(() => {
  const search = filters.value.search.trim().toLowerCase();

  return roles.value.filter((role) => {
    const matchesSearch = !search || [
      role.name,
      ...(role.permissions || []),
    ].some((value) => String(value).toLowerCase().includes(search));

    const matchesStatus = !filters.value.status
      || (filters.value.status === 'active' && role.is_active)
      || (filters.value.status === 'inactive' && !role.is_active);

    const matchesType = !filters.value.type
      || (filters.value.type === 'system' && role.is_system)
      || (filters.value.type === 'custom' && !role.is_system);

    return matchesSearch && matchesStatus && matchesType;
  });
});

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

const toggleMenu = (roleId) => {
  openMenuId.value = openMenuId.value === roleId ? null : roleId;
};

const isProcessing = (roleId) => processingRoleId.value === roleId;

const resetFilters = () => {
  filters.value = {
    search: '',
    status: '',
    type: '',
  };
};

const resetFormErrors = () => {
  formErrors.value = {};
};

const openCreateModal = () => {
  modalMode.value = 'create';
  selectedRole.value = null;
  resetFormErrors();
  showModal.value = true;
  closeMenu();
};

const openEditModal = (role) => {
  modalMode.value = 'edit';
  selectedRole.value = role;
  resetFormErrors();
  showModal.value = true;
  closeMenu();
};

const closeModal = () => {
  if (isSaving.value) {
    return;
  }

  showModal.value = false;
  selectedRole.value = null;
  resetFormErrors();
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

const submitRole = async (payload) => {
  isSaving.value = true;
  resetFormErrors();

  try {
    let response;

    if (modalMode.value === 'edit' && selectedRole.value) {
      response = await roleStore.updateRole(selectedRole.value.id, payload);
    } else {
      response = await roleStore.createRole(payload);
    }

    await userStore.fetchUsers();

    if (response.role?.name === 'Admin') {
      // No-op, but keeps future extension explicit.
    }

    closeModal();
  } catch (err) {
    console.error('Error saving role:', err);
    formErrors.value = normalizeErrors(err);
  } finally {
    isSaving.value = false;
  }
};

const toggleRoleStatus = async (role) => {
  const nextState = !role.is_active;
  const label = nextState ? 'mengaktifkan' : 'menonaktifkan';

  if (!window.confirm(`Yakin ingin ${label} role "${role.name}"?`)) {
    return;
  }

  processingRoleId.value = role.id;
  processingAction.value = 'toggle';

  try {
    await roleStore.updateRole(role.id, {
      is_active: nextState,
    });
    await userStore.fetchUsers();
    closeMenu();
  } catch (err) {
    console.error('Error toggling role:', err);
    alert(err.response?.data?.error || 'Status role gagal diperbarui.');
  } finally {
    processingRoleId.value = null;
    processingAction.value = '';
  }
};

const deleteRole = async (role) => {
  if (!window.confirm(`Hapus role "${role.name}"? Aksi ini hanya aman bila role tidak lagi dipakai.`)) {
    return;
  }

  processingRoleId.value = role.id;
  processingAction.value = 'delete';

  try {
    await roleStore.deleteRole(role.id);
    await userStore.fetchUsers();
    closeMenu();
  } catch (err) {
    console.error('Error deleting role:', err);
    alert(err.response?.data?.error || 'Role gagal dihapus.');
  } finally {
    processingRoleId.value = null;
    processingAction.value = '';
  }
};

const loadRoles = async () => {
  loading.value = true;
  error.value = null;

  try {
    await Promise.all([
      roleStore.fetchRoles(),
      userStore.fetchUsers(),
    ]);
  } catch (err) {
    console.error('Error loading roles:', err);
    error.value = err.response?.data?.error || 'Daftar role gagal dimuat.';
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', closeMenu);
  loadRoles();
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
