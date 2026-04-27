<template>
  <div class="space-y-5 pb-10">
    <section class="rounded-[24px] border border-[#e8dcc9] bg-white p-5 shadow-[0_16px_36px_rgba(41,28,9,0.06)] sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-5">
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Mobile App Release</p>
          <h1 class="mt-2 text-2xl font-semibold tracking-tight text-[#111827]">Distribusi APK Android</h1>
          <p class="mt-2 max-w-2xl text-sm leading-6 text-[#6b7280]">
            Upload APK, pilih kebijakan update, lalu publish. Metadata APK akan dibaca server agar version code tidak salah input.
          </p>
        </div>

        <button
          type="button"
          class="rounded-full border border-[#e8dcc9] px-4 py-2 text-sm font-semibold text-[#8f6115] transition hover:border-[#d8bc84] hover:bg-[#fbf5ea]"
          @click="loadReleases"
        >
          Refresh
        </button>
      </div>

      <div class="mt-5 grid gap-3 md:grid-cols-3">
        <div class="rounded-[18px] border border-[#efe5d7] bg-[#fffdf8] p-4">
          <p class="text-xs font-semibold text-[#8f6115]">Release aktif</p>
          <p class="mt-2 text-xl font-semibold text-[#111827]">
            {{ latestPublishedRelease ? versionLabel(latestPublishedRelease) : '-' }}
          </p>
        </div>

        <div class="rounded-[18px] border border-[#efe5d7] bg-[#fffdf8] p-4">
          <p class="text-xs font-semibold text-[#8f6115]">Kebijakan</p>
          <p class="mt-2 text-xl font-semibold text-[#111827]">
            {{ latestPublishedRelease ? releaseModeLabel(latestPublishedRelease) : '-' }}
          </p>
        </div>

        <div class="rounded-[18px] border border-[#efe5d7] bg-[#fffdf8] p-4">
          <p class="text-xs font-semibold text-[#8f6115]">Saran version code</p>
          <p class="mt-2 text-xl font-semibold text-[#111827]">{{ meta.next_version_code_suggestion || 1 }}</p>
        </div>
      </div>
    </section>

    <section class="grid gap-5 xl:grid-cols-[0.9fr_1.25fr]">
      <div class="rounded-[24px] border border-[#e8dcc9] bg-white p-5 shadow-[0_16px_36px_rgba(41,28,9,0.06)] sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">
              {{ isEditing ? 'Edit Release' : 'Release Baru' }}
            </p>
            <h2 class="mt-2 text-xl font-semibold text-[#111827]">
              {{ isEditing ? versionLabel(formPreviewRelease) : 'Upload APK' }}
            </h2>
          </div>

          <button
            v-if="isEditing"
            type="button"
            class="rounded-full border border-[#e8dcc9] px-4 py-2 text-sm font-semibold text-[#8f6115] transition hover:bg-[#fbf5ea]"
            @click="resetForm"
          >
            Batal
          </button>
        </div>

        <form class="mt-5 space-y-5" @submit.prevent="submitRelease">
          <div>
            <label class="mb-2 block text-sm font-semibold text-[#111827]">File APK</label>
            <label class="block cursor-pointer rounded-[20px] border border-dashed border-[#d8bc84] bg-[#fffdf8] p-4 transition hover:bg-[#fff9ef]">
              <input
                type="file"
                class="hidden"
                accept=".apk,application/vnd.android.package-archive"
                @change="handleFileChange"
              >

              <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-[#8f6115]">
                    {{ selectedFile ? selectedFile.name : (isEditing ? 'Pilih APK baru jika file diganti' : 'Pilih APK release') }}
                  </p>
                  <p class="mt-1 text-xs text-[#6b7280]">
                    {{ selectedFile ? `${formatFileSize(selectedFile.size)} siap diunggah` : 'Server memakai version code dari APK jika metadata tersedia.' }}
                  </p>
                </div>

                <span class="shrink-0 rounded-full bg-white px-3 py-2 text-xs font-semibold text-[#8f6115] shadow-sm">
                  Browse
                </span>
              </div>
            </label>
            <p v-if="fieldError('apk_file')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('apk_file') }}</p>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-semibold text-[#111827]">Version name</label>
              <input
                v-model.trim="form.versionName"
                type="text"
                class="input-field"
                placeholder="1.4.0"
              >
              <p v-if="fieldError('version_name')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('version_name') }}</p>
            </div>

            <div>
              <label class="mb-2 block text-sm font-semibold text-[#111827]">Version code</label>
              <input
                v-model.number="form.versionCode"
                type="number"
                min="1"
                step="1"
                class="input-field"
              >
              <p class="mt-2 text-xs text-[#6b7280]">Fallback jika APK tidak bisa dibaca.</p>
              <p v-if="fieldError('version_code')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('version_code') }}</p>
            </div>
          </div>

          <div class="rounded-[20px] border border-[#efe5d7] bg-[#fffdf8] p-4">
            <label class="mb-3 block text-sm font-semibold text-[#111827]">Kebijakan update</label>
            <div class="grid gap-3 sm:grid-cols-2">
              <button
                type="button"
                class="rounded-[16px] border px-4 py-3 text-left transition"
                :class="!form.forceUpdate ? 'border-[#9b6b17] bg-[#fbf5ea]' : 'border-[#e8dcc9] bg-white hover:bg-[#fffaf2]'"
                @click="setPolicy(false)"
              >
                <span class="block text-sm font-semibold text-[#111827]">Opsional</span>
                <span class="mt-1 block text-xs leading-5 text-[#6b7280]">User bisa pilih Nanti Saja.</span>
              </button>

              <button
                type="button"
                class="rounded-[16px] border px-4 py-3 text-left transition"
                :class="form.forceUpdate ? 'border-[#be123c] bg-[#fff1f2]' : 'border-[#e8dcc9] bg-white hover:bg-[#fffaf2]'"
                @click="setPolicy(true)"
              >
                <span class="block text-sm font-semibold text-[#111827]">Wajib</span>
                <span class="mt-1 block text-xs leading-5 text-[#6b7280]">Aplikasi lama diblok sampai update.</span>
              </button>
            </div>
            <p v-if="fieldError('force_update')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('force_update') }}</p>

            <details class="mt-4 rounded-[16px] border border-[#efe5d7] bg-white px-4 py-3">
              <summary class="cursor-pointer text-sm font-semibold text-[#8f6115]">Opsi teknis</summary>
              <div class="mt-3">
                <label class="mb-2 block text-sm font-medium text-gray-700">Minimum supported version code</label>
                <input
                  v-model.number="resolvedMinimumSupportedVersionCode"
                  type="number"
                  min="1"
                  step="1"
                  class="input-field"
                  :disabled="form.forceUpdate"
                >
                <p class="mt-2 text-xs text-[#6b7280]">
                  Mode wajib otomatis menyamakan angka ini dengan version code release.
                </p>
                <p v-if="fieldError('minimum_supported_version_code')" class="mt-2 text-xs font-medium text-red-600">
                  {{ fieldError('minimum_supported_version_code') }}
                </p>
              </div>
            </details>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-semibold text-[#111827]">Platform</label>
              <select v-model="form.platform" class="select-field">
                <option v-for="platform in meta.platforms" :key="platform" :value="platform">{{ platform }}</option>
              </select>
            </div>

            <div>
              <label class="mb-2 block text-sm font-semibold text-[#111827]">Channel</label>
              <select v-model="form.channel" class="select-field">
                <option v-for="channel in meta.channels" :key="channel" :value="channel">{{ channel }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="mb-2 block text-sm font-semibold text-[#111827]">Catatan release</label>
            <textarea
              v-model.trim="form.releaseNotes"
              rows="4"
              class="input-field min-h-[112px]"
              placeholder="Ringkasan perubahan untuk user."
            ></textarea>
            <p v-if="fieldError('release_notes')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('release_notes') }}</p>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-4 rounded-[18px] border border-[#efe5d7] bg-[#fffdf9] px-4 py-3">
            <label class="inline-flex items-center gap-3">
              <input
                v-model="form.publishNow"
                type="checkbox"
                class="h-4 w-4 rounded border-[#cfb98b] text-[#9b6b17] focus:ring-[#e8d2a4]"
              >
              <span class="text-sm font-medium text-gray-700">Publish setelah simpan</span>
            </label>

            <button
              type="submit"
              class="btn-primary min-w-[180px]"
              :disabled="saving"
            >
              {{ saving ? 'Menyimpan...' : (isEditing ? 'Simpan' : 'Upload') }}
            </button>
          </div>
        </form>
      </div>

      <div class="space-y-5">
        <section v-if="loading" class="rounded-[24px] border border-[#e8dcc9] bg-white p-8 shadow-[0_16px_36px_rgba(41,28,9,0.06)]">
          <div class="flex items-center justify-center py-10">
            <div class="h-10 w-10 animate-spin rounded-full border-4 border-[#f1e4c9] border-t-[#9b6b17]"></div>
          </div>
        </section>

        <section v-else-if="error" class="rounded-[24px] border border-[#f3c4c4] bg-[#fff5f5] p-5 shadow-[0_16px_36px_rgba(41,28,9,0.04)]">
          <h2 class="text-base font-semibold text-[#991b1b]">Gagal memuat release</h2>
          <p class="mt-2 text-sm leading-6 text-[#b91c1c]">{{ error }}</p>
          <button type="button" class="btn-primary mt-4" @click="loadReleases">Coba Lagi</button>
        </section>

        <template v-else>
          <section
            v-if="latestPublishedRelease"
            class="rounded-[24px] border border-[#e8dcc9] bg-white p-5 shadow-[0_16px_36px_rgba(41,28,9,0.06)]"
          >
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Aktif</p>
                <h2 class="mt-2 text-xl font-semibold text-[#111827]">{{ versionLabel(latestPublishedRelease) }}</h2>
                <p class="mt-1 text-sm text-[#6b7280]">{{ latestPublishedRelease.apk_file_name }}</p>
              </div>

              <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="policyBadgeClass(latestPublishedRelease)">
                {{ releaseModeLabel(latestPublishedRelease) }}
              </span>
            </div>
          </section>

          <section class="rounded-[24px] border border-[#e8dcc9] bg-white shadow-[0_16px_36px_rgba(41,28,9,0.06)]">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[#efe5d7] px-5 py-4">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">History</p>
                <h2 class="mt-1 text-lg font-semibold text-[#111827]">Daftar APK</h2>
              </div>
              <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
                {{ releases.length }} release
              </span>
            </div>

            <div v-if="releases.length === 0" class="px-5 py-10 text-center">
              <h3 class="text-base font-semibold text-gray-900">Belum ada release</h3>
              <p class="mt-2 text-sm text-gray-500">Upload APK pertama untuk mulai distribusi internal.</p>
            </div>

            <div v-else class="divide-y divide-[#f3ecdf]">
              <article v-for="release in releases" :key="release.id" class="px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                      <h3 class="text-base font-semibold text-[#111827]">{{ versionLabel(release) }}</h3>
                      <span
                        class="rounded-full px-3 py-1 text-[11px] font-semibold"
                        :class="release.is_published ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#52525b]'"
                      >
                        {{ release.is_published ? 'Published' : 'Draft' }}
                      </span>
                      <span class="rounded-full px-3 py-1 text-[11px] font-semibold" :class="policyBadgeClass(release)">
                        {{ releaseModeLabel(release) }}
                      </span>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-[#6b7280]">
                      <span>{{ formatFileSize(release.file_size) }}</span>
                      <span v-if="release.published_at">{{ formatDateTime(release.published_at) }}</span>
                      <span v-if="release.uploaded_by?.name">{{ release.uploaded_by.name }}</span>
                    </div>

                    <p class="mt-2 truncate text-sm font-medium text-[#6b7280]">{{ release.apk_file_name }}</p>
                    <p v-if="release.release_notes" class="mt-3 line-clamp-3 whitespace-pre-line rounded-[16px] bg-[#fffaf2] p-3 text-sm leading-6 text-[#4b5563]">
                      {{ release.release_notes }}
                    </p>
                  </div>

                  <div class="flex flex-wrap gap-2">
                    <button
                      type="button"
                      class="rounded-full border border-[#e8dcc9] px-3 py-2 text-sm font-semibold text-[#8f6115] transition hover:bg-[#fbf5ea]"
                      @click="startEdit(release)"
                    >
                      Edit
                    </button>

                    <button
                      v-if="!release.is_published"
                      type="button"
                      class="rounded-full border border-[#d9f2e1] px-3 py-2 text-sm font-semibold text-[#15803d] transition hover:bg-[#f0fdf4]"
                      :disabled="processingId === release.id && processingAction === 'publish'"
                      @click="publishRelease(release)"
                    >
                      {{ processingId === release.id && processingAction === 'publish' ? '...' : 'Publish' }}
                    </button>

                    <button
                      v-else
                      type="button"
                      class="rounded-full border border-[#e8dcc9] px-3 py-2 text-sm font-semibold text-[#6b7280] transition hover:bg-[#f8fafc]"
                      :disabled="processingId === release.id && processingAction === 'unpublish'"
                      @click="unpublishRelease(release)"
                    >
                      {{ processingId === release.id && processingAction === 'unpublish' ? '...' : 'Draft' }}
                    </button>

                    <button
                      type="button"
                      class="rounded-full border border-[#f5c2c7] px-3 py-2 text-sm font-semibold text-[#b91c1c] transition hover:bg-[#fff1f2]"
                      :disabled="processingId === release.id && processingAction === 'delete'"
                      @click="deleteRelease(release)"
                    >
                      {{ processingId === release.id && processingAction === 'delete' ? '...' : 'Hapus' }}
                    </button>
                  </div>
                </div>
              </article>
            </div>
          </section>
        </template>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useMobileAppReleaseStore } from '../../stores/mobileAppReleases';

const releaseStore = useMobileAppReleaseStore();

const loading = ref(false);
const saving = ref(false);
const error = ref(null);
const formErrors = ref({});
const editingId = ref(null);
const selectedFile = ref(null);
const processingId = ref(null);
const processingAction = ref('');

const meta = computed(() => releaseStore.meta);
const releases = computed(() => releaseStore.releases);
const latestPublishedRelease = computed(() => releaseStore.latestPublishedRelease);
const isEditing = computed(() => editingId.value !== null);

const createEmptyForm = () => ({
  platform: 'android',
  channel: 'production',
  versionName: '',
  versionCode: releaseStore.meta.next_version_code_suggestion || 1,
  minimumSupportedVersionCode: releaseStore.meta.minimum_supported_version_code_suggestion || 1,
  forceUpdate: false,
  releaseNotes: '',
  publishNow: true,
});

const form = ref(createEmptyForm());

const formPreviewRelease = computed(() => ({
  version_name: form.value.versionName || '-',
  version_code: form.value.versionCode || '-',
}));

watch(
  () => form.value.forceUpdate,
  (forceUpdate) => {
    if (forceUpdate && form.value.versionCode) {
      form.value.minimumSupportedVersionCode = Number(form.value.versionCode);
    }
  },
);

watch(
  () => form.value.versionCode,
  (versionCode) => {
    if (form.value.forceUpdate && versionCode) {
      form.value.minimumSupportedVersionCode = Number(versionCode);
    }
  },
);

const resolvedMinimumSupportedVersionCode = computed({
  get() {
    if (form.value.forceUpdate) {
      return Number(form.value.versionCode || 1);
    }

    return Number(form.value.minimumSupportedVersionCode || 1);
  },
  set(value) {
    form.value.minimumSupportedVersionCode = Number(value || 1);
  },
});

const fieldError = (key) => formErrors.value?.[key]?.[0] || null;

const loadReleases = async () => {
  loading.value = true;
  error.value = null;

  try {
    await releaseStore.fetchReleases();
    if (!isEditing.value) {
      form.value = createEmptyForm();
    }
  } catch (requestError) {
    error.value = requestError.response?.data?.error || 'Data release mobile app belum bisa dimuat.';
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  editingId.value = null;
  selectedFile.value = null;
  formErrors.value = {};
  form.value = createEmptyForm();
};

const setPolicy = (forceUpdate) => {
  form.value.forceUpdate = forceUpdate;
};

const handleFileChange = (event) => {
  selectedFile.value = event.target.files?.[0] || null;
};

const buildFormData = () => {
  const payload = new FormData();
  payload.append('platform', form.value.platform);
  payload.append('channel', form.value.channel);
  payload.append('version_name', form.value.versionName || '');
  payload.append('version_code', String(form.value.versionCode || ''));
  payload.append(
    'minimum_supported_version_code',
    String(form.value.forceUpdate ? form.value.versionCode || '' : form.value.minimumSupportedVersionCode || ''),
  );
  payload.append('force_update', form.value.forceUpdate ? '1' : '0');
  payload.append('release_notes', form.value.releaseNotes || '');
  payload.append('publish_now', form.value.publishNow ? '1' : '0');

  if (selectedFile.value) {
    payload.append('apk_file', selectedFile.value);
  }

  return payload;
};

const submitRelease = async () => {
  saving.value = true;
  formErrors.value = {};
  error.value = null;

  try {
    const payload = buildFormData();

    if (isEditing.value) {
      await releaseStore.updateRelease(editingId.value, payload);
    } else {
      await releaseStore.createRelease(payload);
    }

    await releaseStore.fetchReleases();
    resetForm();
  } catch (requestError) {
    formErrors.value = requestError.response?.data?.errors || {};
    if (!Object.keys(formErrors.value).length) {
      error.value = requestError.response?.data?.error || 'Release gagal disimpan.';
    }
  } finally {
    saving.value = false;
  }
};

const startEdit = (release) => {
  editingId.value = release.id;
  selectedFile.value = null;
  formErrors.value = {};
  form.value = {
    platform: release.platform,
    channel: release.channel,
    versionName: release.version_name,
    versionCode: release.version_code,
    minimumSupportedVersionCode: release.minimum_supported_version_code,
    forceUpdate: release.is_force_update === true || release.update_mode === 'force',
    releaseNotes: release.release_notes || '',
    publishNow: release.is_published,
  };
  window.scrollTo({ top: 0, behavior: 'smooth' });
};

const withProcessing = async (releaseId, action, callback) => {
  processingId.value = releaseId;
  processingAction.value = action;
  error.value = null;

  try {
    await callback();
    await releaseStore.fetchReleases();
  } catch (requestError) {
    error.value = requestError.response?.data?.error || 'Aksi release gagal diproses.';
  } finally {
    processingId.value = null;
    processingAction.value = '';
  }
};

const publishRelease = (release) => withProcessing(release.id, 'publish', () => releaseStore.publishRelease(release.id));
const unpublishRelease = (release) => withProcessing(release.id, 'unpublish', () => releaseStore.unpublishRelease(release.id));

const deleteRelease = async (release) => {
  const confirmed = window.confirm(`Hapus release ${versionLabel(release)} dari sistem? File APK juga akan dihapus.`);
  if (!confirmed) {
    return;
  }

  await withProcessing(release.id, 'delete', async () => {
    await releaseStore.deleteRelease(release.id);

    if (editingId.value === release.id) {
      resetForm();
    }
  });
};

const versionLabel = (release) => `${release.version_name} (${release.version_code})`;

const releaseModeLabel = (release) => (
  release.is_force_update === true || release.update_mode === 'force'
    ? 'Wajib'
    : 'Opsional'
);

const policyBadgeClass = (release) => (
  release.is_force_update === true || release.update_mode === 'force'
    ? 'bg-[#fff1f2] text-[#be123c]'
    : 'bg-[#eff6ff] text-[#1d4ed8]'
);

const formatFileSize = (size) => {
  const normalizedSize = Number(size || 0);

  if (normalizedSize < 1024) {
    return `${normalizedSize} B`;
  }

  if (normalizedSize < 1024 * 1024) {
    return `${(normalizedSize / 1024).toFixed(1)} KB`;
  }

  return `${(normalizedSize / (1024 * 1024)).toFixed(2)} MB`;
};

const formatDateTime = (value) => {
  if (!value) {
    return '-';
  }

  try {
    return new Intl.DateTimeFormat('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value));
  } catch (formatError) {
    return value;
  }
};

onMounted(loadReleases);
</script>
