<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-primary-700">Akun Internal</p>
        <h1 class="mt-2 text-3xl font-bold text-secondary-900">Profil Saya</h1>
      </div>

      <button
        type="button"
        class="btn-primary inline-flex items-center justify-center gap-2 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="saving"
        @click="saveProfile"
      >
        <svg v-if="saving" class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4Z" />
        </svg>
        <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="m5 13 4 4L19 7" />
        </svg>
        Simpan Profil
      </button>
    </div>

    <div class="card overflow-hidden p-0">
      <div class="grid gap-0 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <section class="border-b border-primary-100 p-6 lg:border-b-0 lg:border-r">
          <div class="flex flex-col gap-5 sm:flex-row sm:items-center lg:flex-col lg:items-start">
            <div class="relative h-28 w-28 shrink-0 overflow-hidden rounded-[2rem] bg-primary-700 text-3xl font-bold text-white shadow-xl shadow-primary-100">
              <img v-if="avatarPreview" :src="avatarPreview" alt="" class="h-full w-full object-cover">
              <div v-else class="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary-700 to-primary-500">
                {{ userInitials }}
              </div>
            </div>

            <div class="min-w-0 flex-1">
              <h2 class="truncate text-2xl font-bold text-secondary-900">{{ authStore.user?.name || '-' }}</h2>
              <p class="mt-1 truncate text-sm font-semibold text-secondary-600">{{ primaryRole }}</p>
              <p class="mt-1 truncate text-sm text-secondary-500">{{ departmentValue }}</p>

              <div class="mt-4 flex flex-wrap gap-2">
                <span class="rounded-full bg-success-50 px-3 py-1 text-xs font-semibold text-success-700">2FA Active</span>
                <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Managed Device</span>
              </div>
            </div>
          </div>

          <div class="mt-6 flex flex-wrap gap-3">
            <label class="btn-secondary inline-flex cursor-pointer items-center justify-center gap-2">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 16.5V19a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2.5M7 10l5-5m0 0 5 5m-5-5v12" />
              </svg>
              Ganti Foto
              <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="handlePhotoChange">
            </label>
          </div>

          <p v-if="message" class="mt-5 rounded-2xl bg-success-50 px-4 py-3 text-sm font-medium text-success-700">{{ message }}</p>
          <p v-if="error" class="mt-5 rounded-2xl bg-danger-50 px-4 py-3 text-sm font-medium text-danger-700">{{ error }}</p>
        </section>

        <section class="p-6">
          <label for="profile-bio" class="text-sm font-semibold text-secondary-900">Bio Internal</label>
          <textarea
            id="profile-bio"
            v-model="form.bio"
            rows="5"
            maxlength="180"
            class="input-field mt-3 resize-none leading-6"
            placeholder="Tulis bio singkat untuk profil internal."
          ></textarea>
          <div class="mt-2 flex justify-end text-xs font-medium text-secondary-400">
            {{ form.bio.length }}/180
          </div>
        </section>
      </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.6fr)]">
      <div class="card p-6">
        <div class="mb-6 flex items-center justify-between gap-4">
          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-primary-700">Identitas</p>
            <h2 class="mt-2 text-xl font-semibold text-secondary-900">Data Corporate</h2>
          </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          <ProfileFact label="Nama" :value="authStore.user?.name" />
          <ProfileFact label="Email GESIT" :value="authStore.user?.email" />
          <ProfileFact label="Username S21Plus" :value="authStore.user?.s21plus_user_id" />
          <ProfileFact label="Employee ID" :value="authStore.user?.employee_id" />
          <ProfileFact label="Divisi" :value="divisionValue" />
          <ProfileFact label="Department" :value="authStore.user?.department" />
          <ProfileFact label="Phone" :value="authStore.user?.phone_number" />
        </div>
      </div>

      <div class="card p-6">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.22em] text-primary-700">Akses</p>
          <h2 class="mt-2 text-xl font-semibold text-secondary-900">Role</h2>
        </div>

        <div class="mt-5 flex flex-wrap gap-2">
          <span
            v-for="role in authStore.roles"
            :key="role"
            class="rounded-full bg-primary-100 px-3 py-1 text-sm font-medium text-primary-700"
          >
            {{ role }}
          </span>
          <span v-if="authStore.roles.length === 0" class="text-sm text-secondary-500">Belum ada role.</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import axios from 'axios';
import { computed, defineComponent, h, reactive, ref, watch } from 'vue';
import { useAuthStore } from '../stores/auth';

const authStore = useAuthStore();
const saving = ref(false);
const message = ref('');
const error = ref('');
const photoFile = ref(null);
const photoPreview = ref('');
const form = reactive({
  bio: authStore.user?.bio || '',
});

const userInitials = computed(() => {
  const name = authStore.user?.name || 'User';

  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
});

const primaryRole = computed(() => authStore.roles[0] || 'Internal User');
const departmentValue = computed(() => authStore.user?.department || 'Internal Workspace');
const divisionValue = computed(() => {
  const raw = authStore.user?.department || '';
  const match = raw.match(/\s[-/]\s/);

  if (!match) {
    return raw || 'Internal Workspace';
  }

  return raw.slice(0, match.index).trim() || raw;
});
const avatarPreview = computed(() => photoPreview.value || authStore.user?.profile_photo_url || '');

watch(
  () => authStore.user?.bio,
  (nextBio) => {
    form.bio = nextBio || '';
  }
);

const handlePhotoChange = (event) => {
  const file = event.target.files?.[0] || null;

  if (photoPreview.value) {
    URL.revokeObjectURL(photoPreview.value);
  }

  photoFile.value = file;
  photoPreview.value = file ? URL.createObjectURL(file) : '';
};

const saveProfile = async () => {
  saving.value = true;
  message.value = '';
  error.value = '';

  try {
    const payload = new FormData();
    payload.append('bio', form.bio || '');

    if (photoFile.value) {
      payload.append('profile_photo', photoFile.value);
    }

    const response = await axios.post('/api/user/profile', payload, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    authStore.hydrate(response.data);
    photoFile.value = null;

    if (photoPreview.value) {
      URL.revokeObjectURL(photoPreview.value);
      photoPreview.value = '';
    }

    message.value = 'Profil berhasil diperbarui.';
  } catch (saveError) {
    error.value = saveError.response?.data?.message
      || saveError.response?.data?.error
      || 'Profil belum bisa diperbarui.';
  } finally {
    saving.value = false;
  }
};

const ProfileFact = defineComponent({
  props: {
    label: { type: String, required: true },
    value: { type: [String, Number], default: '' },
  },
  setup(props) {
    return () => h('div', { class: 'rounded-2xl border border-primary-100 bg-white px-4 py-4' }, [
      h('p', { class: 'text-xs font-semibold uppercase tracking-[0.18em] text-secondary-400' }, props.label),
      h('p', { class: 'mt-2 break-words text-base font-semibold text-secondary-900' }, props.value || '-'),
    ]);
  },
});
</script>
