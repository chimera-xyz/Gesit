<template>
  <div class="space-y-6 pb-10">
    <section class="overflow-hidden rounded-[30px] border border-[#e8dcc9] bg-white shadow-[0_20px_48px_rgba(41,28,9,0.07)]">
      <div class="grid gap-0 lg:grid-cols-[1.35fr_0.95fr]">
        <div class="px-6 py-7 sm:px-8">
          <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">Android Release Center</p>
          <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.1rem]">
            Distribusi update APK internal
          </h1>
          <p class="mt-3 max-w-2xl text-sm leading-7 text-[#6b7280] sm:text-[0.96rem]">
            Upload APK release, atur versi minimum yang masih didukung, lalu publish agar aplikasi GESIT di Android otomatis meminta user update.
          </p>

          <div class="mt-6 flex flex-wrap gap-3">
            <span class="rounded-full bg-[#fbf5ea] px-4 py-2 text-xs font-semibold text-[#8f6115]">
              Self-hosted update
            </span>
            <span class="rounded-full bg-[#f5f7fb] px-4 py-2 text-xs font-semibold text-[#475569]">
              Signed download URL
            </span>
            <span class="rounded-full bg-[#f0fdf4] px-4 py-2 text-xs font-semibold text-[#15803d]">
              Force update supported
            </span>
          </div>
        </div>

        <div class="grid gap-4 border-t border-[#f1e9dc] bg-[#fffaf2] px-6 py-7 sm:grid-cols-3 lg:border-l lg:border-t-0 lg:grid-cols-1 lg:px-7">
          <div class="rounded-[24px] border border-[#eadfc9] bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Latest Published</p>
            <p class="mt-3 text-2xl font-semibold text-[#111827]">
              {{ latestPublishedRelease ? versionLabel(latestPublishedRelease) : '-' }}
            </p>
            <p class="mt-2 text-sm text-[#6b7280]">
              {{ latestPublishedRelease ? releaseModeLabel(latestPublishedRelease) : 'Belum ada release aktif.' }}
            </p>
          </div>

          <div class="rounded-[24px] border border-[#eadfc9] bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Total Release</p>
            <p class="mt-3 text-2xl font-semibold text-[#111827]">{{ releases.length }}</p>
            <p class="mt-2 text-sm text-[#6b7280]">Draft dan published tercatat dalam histori.</p>
          </div>

          <div class="rounded-[24px] border border-[#eadfc9] bg-white p-5">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Current Minimum</p>
            <p class="mt-3 text-2xl font-semibold text-[#111827]">
              {{ latestPublishedRelease?.minimum_supported_version_code ?? '-' }}
            </p>
            <p class="mt-2 text-sm text-[#6b7280]">Versi di bawah angka ini akan diblok sampai update.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[0.95fr_1.25fr]">
      <div class="rounded-[28px] border border-[#e8dcc9] bg-white p-6 shadow-[0_16px_36px_rgba(41,28,9,0.06)] sm:p-7">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">
              {{ isEditing ? 'Edit Release' : 'Upload Release Baru' }}
            </p>
            <h2 class="mt-2 text-2xl font-semibold text-[#111827]">
              {{ isEditing ? `Versi ${form.versionName || '-'}` : 'APK Android Release' }}
            </h2>
            <p class="mt-2 text-sm leading-7 text-[#6b7280]">
              Upload APK final hasil `flutter build apk --release`, lalu tentukan apakah update ini wajib atau opsional.
            </p>
          </div>

          <button
            v-if="isEditing"
            type="button"
            class="rounded-full border border-[#e8dcc9] px-4 py-2 text-sm font-semibold text-[#8f6115] transition hover:border-[#d8bc84] hover:bg-[#fbf5ea]"
            @click="resetForm"
          >
            Batal Edit
          </button>
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="submitRelease">
          <div class="grid gap-5 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-medium text-gray-700">Versi App</label>
              <input
                v-model.trim="form.versionName"
                type="text"
                class="input-field"
                placeholder="Contoh: 1.4.0"
              >
              <p v-if="fieldError('version_name')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('version_name') }}</p>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-gray-700">Version Code</label>
              <input
                v-model.number="form.versionCode"
                type="number"
                min="1"
                step="1"
                class="input-field"
                placeholder="Contoh: 14"
              >
              <p class="mt-2 text-xs text-gray-500">Harus selalu lebih tinggi dari release sebelumnya.</p>
              <p v-if="fieldError('version_code')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('version_code') }}</p>
            </div>
          </div>

          <div class="grid gap-5 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-medium text-gray-700">Platform</label>
              <select v-model="form.platform" class="select-field">
                <option v-for="platform in meta.platforms" :key="platform" :value="platform">{{ platform }}</option>
              </select>
            </div>

            <div>
              <label class="mb-2 block text-sm font-medium text-gray-700">Channel</label>
              <select v-model="form.channel" class="select-field">
                <option v-for="channel in meta.channels" :key="channel" :value="channel">{{ channel }}</option>
              </select>
            </div>
          </div>

          <div class="rounded-[24px] border border-[#efe5d7] bg-[#fffaf2] p-5">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h3 class="text-base font-semibold text-[#111827]">Kebijakan Update</h3>
                <p class="mt-2 text-sm leading-7 text-[#6b7280]">
                  Jika release ini wajib, aplikasi lama akan diblok sampai user menginstal APK terbaru.
                </p>
              </div>

              <label class="inline-flex items-center gap-3 rounded-full bg-white px-4 py-2 shadow-sm">
                <input
                  v-model="form.forceUpdate"
                  type="checkbox"
                  class="h-4 w-4 rounded border-[#cfb98b] text-[#9b6b17] focus:ring-[#e8d2a4]"
                >
                <span class="text-sm font-semibold text-[#8f6115]">Force update</span>
              </label>
            </div>

            <div class="mt-5">
              <label class="mb-2 block text-sm font-medium text-gray-700">Minimum Supported Version Code</label>
              <input
                v-model.number="resolvedMinimumSupportedVersionCode"
                type="number"
                min="1"
                step="1"
                class="input-field"
                :disabled="form.forceUpdate"
              >
              <p class="mt-2 text-xs text-gray-500">
                {{ form.forceUpdate
                  ? 'Saat force update aktif, minimum supported version otomatis sama dengan version code release ini.'
                  : 'User dengan version code di bawah angka ini akan diminta update wajib.' }}
              </p>
              <p v-if="fieldError('minimum_supported_version_code')" class="mt-2 text-xs font-medium text-red-600">
                {{ fieldError('minimum_supported_version_code') }}
              </p>
            </div>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Release Notes</label>
            <textarea
              v-model.trim="form.releaseNotes"
              rows="5"
              class="input-field min-h-[138px]"
              placeholder="Tulis ringkasan pembaruan, perbaikan bug, atau instruksi penting untuk user."
            ></textarea>
            <p v-if="fieldError('release_notes')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('release_notes') }}</p>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">
              File APK
              <span class="text-xs text-gray-500">{{ isEditing ? '(opsional jika hanya ubah metadata)' : '(wajib)' }}</span>
            </label>

            <label class="block cursor-pointer rounded-[24px] border border-dashed border-[#d8bc84] bg-[#fffdf8] p-5 transition hover:bg-[#fff9ef]">
              <input
                type="file"
                class="hidden"
                accept=".apk,application/vnd.android.package-archive"
                @change="handleFileChange"
              >

              <div class="flex items-start justify-between gap-4">
                <div>
                  <p class="text-sm font-semibold text-[#8f6115]">
                    {{ selectedFile ? selectedFile.name : (isEditing ? 'Pilih APK baru jika ingin mengganti file.' : 'Pilih file APK release.') }}
                  </p>
                  <p class="mt-2 text-sm text-[#6b7280]">
                    {{ selectedFile
                      ? `${formatFileSize(selectedFile.size)} siap diunggah`
                      : 'Pastikan APK ditandatangani dengan key yang sama dan version code sudah dinaikkan.' }}
                  </p>
                </div>

                <span class="rounded-full bg-white px-4 py-2 text-xs font-semibold text-[#8f6115] shadow-sm">
                  Browse APK
                </span>
              </div>
            </label>

            <p v-if="fieldError('apk_file')" class="mt-2 text-xs font-medium text-red-600">{{ fieldError('apk_file') }}</p>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-4 rounded-[24px] border border-[#efe5d7] bg-[#fffdf9] px-5 py-4">
            <label class="inline-flex items-center gap-3">
              <input
                v-model="form.publishNow"
                type="checkbox"
                class="h-4 w-4 rounded border-[#cfb98b] text-[#9b6b17] focus:ring-[#e8d2a4]"
              >
              <span class="text-sm font-medium text-gray-700">Langsung publish setelah disimpan</span>
            </label>

            <button
              type="submit"
              class="btn-primary min-w-[210px]"
              :disabled="saving"
            >
              {{ saving ? 'Menyimpan...' : (isEditing ? 'Simpan Perubahan Release' : 'Upload Release') }}
            </button>
          </div>
        </form>
      </div>

      <div class="space-y-6">
        <section v-if="loading" class="rounded-[28px] border border-[#e8dcc9] bg-white p-8 shadow-[0_16px_36px_rgba(41,28,9,0.06)]">
          <div class="flex items-center justify-center py-10">
            <div class="h-12 w-12 animate-spin rounded-full border-4 border-[#f1e4c9] border-t-[#9b6b17]"></div>
          </div>
        </section>

        <section v-else-if="error" class="rounded-[28px] border border-[#f3c4c4] bg-[#fff5f5] p-6 shadow-[0_16px_36px_rgba(41,28,9,0.04)]">
          <h2 class="text-lg font-semibold text-[#991b1b]">Gagal memuat release</h2>
          <p class="mt-2 text-sm leading-7 text-[#b91c1c]">{{ error }}</p>
          <button type="button" class="btn-primary mt-5" @click="loadReleases">Coba Lagi</button>
        </section>

        <template v-else>
          <section
            v-if="latestPublishedRelease"
            class="rounded-[28px] border border-[#e8dcc9] bg-white p-6 shadow-[0_16px_36px_rgba(41,28,9,0.06)]"
          >
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Release Aktif Saat Ini</p>
                <h2 class="mt-2 text-2xl font-semibold text-[#111827]">{{ versionLabel(latestPublishedRelease) }}</h2>
                <p class="mt-2 text-sm text-[#6b7280]">
                  Minimum supported version:
                  <span class="font-semibold text-[#111827]">{{ latestPublishedRelease.minimum_supported_version_code }}</span>
                </p>
              </div>

              <span
                class="rounded-full px-4 py-2 text-xs font-semibold"
                :class="latestPublishedRelease.update_mode === 'force'
                  ? 'bg-[#fff1f2] text-[#be123c]'
                  : 'bg-[#eff6ff] text-[#1d4ed8]'"
              >
                {{ releaseModeLabel(latestPublishedRelease) }}
              </span>
            </div>

            <div v-if="latestPublishedRelease.release_notes" class="mt-4 rounded-[22px] bg-[#fffaf2] p-4">
              <p class="text-sm leading-7 text-[#4b5563] whitespace-pre-line">{{ latestPublishedRelease.release_notes }}</p>
            </div>
          </section>

          <section class="rounded-[28px] border border-[#e8dcc9] bg-white shadow-[0_16px_36px_rgba(41,28,9,0.06)]">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[#efe5d7] px-6 py-5">
              <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">Release History</p>
                <h2 class="mt-2 text-xl font-semibold text-[#111827]">APK yang tersedia di sistem</h2>
              </div>

              <span class="rounded-full bg-[#fbf5ea] px-4 py-2 text-xs font-semibold text-[#8f6115]">
                {{ releases.length }} release
              </span>
            </div>

            <div v-if="releases.length === 0" class="px-6 py-12 text-center">
              <h3 class="text-lg font-medium text-gray-900">Belum ada release</h3>
              <p class="mt-2 text-sm text-gray-500">Upload APK pertama untuk mulai distribusi update internal.</p>
            </div>

            <div v-else class="divide-y divide-[#f3ecdf]">
              <article
                v-for="release in releases"
                :key="release.id"
                class="px-6 py-5"
              >
                <div class="flex flex-wrap items-start justify-between gap-4">
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                      <h3 class="text-lg font-semibold text-[#111827]">{{ versionLabel(release) }}</h3>
                      <span
                        class="rounded-full px-3 py-1 text-[11px] font-semibold"
                        :class="release.is_published
                          ? 'bg-[#f0fdf4] text-[#15803d]'
                          : 'bg-[#f4f4f5] text-[#52525b]'"
                      >
                        {{ release.is_published ? 'Published' : 'Draft' }}
                      </span>
                      <span
                        class="rounded-full px-3 py-1 text-[11px] font-semibold"
                        :class="release.update_mode === 'force'
                          ? 'bg-[#fff1f2] text-[#be123c]'
                          : 'bg-[#eff6ff] text-[#1d4ed8]'"
                      >
                        {{ releaseModeLabel(release) }}
                      </span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-3 text-sm text-[#6b7280]">
                      <span>Minimum supported: <strong class="text-[#111827]">{{ release.minimum_supported_version_code }}</strong></span>
                      <span>File: <strong class="text-[#111827]">{{ formatFileSize(release.file_size) }}</strong></span>
                      <span v-if="release.published_at">Publish: <strong class="text-[#111827]">{{ formatDateTime(release.published_at) }}</strong></span>
                      <span v-if="release.uploaded_by?.name">Uploader: <strong class="text-[#111827]">{{ release.uploaded_by.name }}</strong></span>
                    </div>

                    <p class="mt-3 text-sm font-medium text-[#6b7280]">{{ release.apk_file_name }}</p>
                    <p class="mt-2 text-xs text-[#6b7280] break-all">SHA-256: {{ release.sha256 }}</p>

                    <div v-if="release.release_notes" class="mt-4 rounded-[22px] bg-[#fffaf2] p-4">
                      <p class="text-sm leading-7 text-[#4b5563] whitespace-pre-line">{{ release.release_notes }}</p>
                    </div>
                  </div>

                  <div class="flex flex-wrap gap-2">
                    <button
                      type="button"
                      class="rounded-full border border-[#e8dcc9] px-4 py-2 text-sm font-semibold text-[#8f6115] transition hover:border-[#d8bc84] hover:bg-[#fbf5ea]"
                      @click="startEdit(release)"
                    >
                      Edit
                    </button>

                    <button
                      v-if="!release.is_published"
                      type="button"
                      class="rounded-full border border-[#d9f2e1] px-4 py-2 text-sm font-semibold text-[#15803d] transition hover:bg-[#f0fdf4]"
                      :disabled="processingId === release.id && processingAction === 'publish'"
                      @click="publishRelease(release)"
                    >
                      {{ processingId === release.id && processingAction === 'publish' ? 'Publishing...' : 'Publish' }}
                    </button>

                    <button
                      v-else
                      type="button"
                      class="rounded-full border border-[#e8dcc9] px-4 py-2 text-sm font-semibold text-[#6b7280] transition hover:bg-[#f8fafc]"
                      :disabled="processingId === release.id && processingAction === 'unpublish'"
                      @click="unpublishRelease(release)"
                    >
                      {{ processingId === release.id && processingAction === 'unpublish' ? 'Memproses...' : 'Jadikan Draft' }}
                    </button>

                    <button
                      type="button"
                      class="rounded-full border border-[#f5c2c7] px-4 py-2 text-sm font-semibold text-[#b91c1c] transition hover:bg-[#fff1f2]"
                      :disabled="processingId === release.id && processingAction === 'delete'"
                      @click="deleteRelease(release)"
                    >
                      {{ processingId === release.id && processingAction === 'delete' ? 'Menghapus...' : 'Hapus' }}
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

const handleFileChange = (event) => {
  const file = event.target.files?.[0] || null;
  selectedFile.value = file;
};

const buildFormData = () => {
  const payload = new FormData();
  payload.append('platform', form.value.platform);
  payload.append('channel', form.value.channel);
  payload.append('version_name', form.value.versionName);
  payload.append('version_code', String(form.value.versionCode || ''));
  payload.append(
    'minimum_supported_version_code',
    String(form.value.forceUpdate ? form.value.versionCode || '' : form.value.minimumSupportedVersionCode || ''),
  );
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
    forceUpdate: release.update_mode === 'force',
    releaseNotes: release.release_notes || '',
    publishNow: release.is_published,
  };
  window.scrollTo({ top: 0, behavior: 'smooth' });
};

const withProcessing = async (releaseId, action, callback) => {
  processingId.value = releaseId;
  processingAction.value = action;

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
  release.update_mode === 'force'
    ? 'Mandatory update'
    : 'Optional update'
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
