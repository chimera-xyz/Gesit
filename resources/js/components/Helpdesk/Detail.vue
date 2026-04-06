<template>
  <div class="space-y-6 pb-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <button type="button" class="text-sm font-medium text-[#8b6316] transition hover:text-[#704d10]" @click="goBack">
          ← Kembali ke Helpdesk
        </button>
        <p v-if="ticket" class="mt-4 text-xs font-semibold uppercase tracking-[0.24em] text-[#a57e3a]">{{ ticket.ticket_number }}</p>
        <h1 class="mt-2 text-3xl font-semibold text-[#111827]">
          {{ ticket?.subject || 'Detail Ticket Helpdesk' }}
        </h1>
        <p class="mt-3 max-w-3xl text-sm leading-7 text-[#6b7280]">
          {{ ticket?.description || 'Pantau progres penanganan, balas update terbaru, dan kelola status ticket dari satu halaman.' }}
        </p>
      </div>

      <div v-if="ticket" class="flex flex-wrap items-center gap-3">
        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="getPriorityClass(ticket.priority)">
          {{ ticket.priority_label }}
        </span>
        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="getStatusClass(ticket.status)">
          {{ ticket.status_label }}
        </span>
        <button
          v-if="canManage && ticket.can_assign_to_me"
          type="button"
          class="btn-secondary"
          :disabled="savingManager"
          @click="assignToMe"
        >
          {{ savingManager ? 'Memproses...' : 'Assign ke saya' }}
        </button>
        <button
          v-if="ticket.can_close"
          type="button"
          class="btn-success"
          :disabled="savingRequesterAction"
          @click="submitRequesterAction('close')"
        >
          {{ savingRequesterAction ? 'Memproses...' : 'Sudah beres, tutup ticket' }}
        </button>
        <button
          v-if="ticket.can_reopen"
          type="button"
          class="btn-secondary"
          :disabled="savingRequesterAction"
          @click="submitRequesterAction('reopen')"
        >
          {{ savingRequesterAction ? 'Memproses...' : 'Masih bermasalah' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-[#9b6b17]"></div>
    </div>

    <div v-else-if="error" class="card p-6">
      <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
        <h2 class="text-lg font-semibold text-red-900">Gagal memuat detail ticket</h2>
        <p class="mt-2 text-sm text-red-700">{{ error }}</p>
        <button type="button" class="btn-primary mt-4" @click="loadTicket">Coba Lagi</button>
      </div>
    </div>

    <template v-else-if="ticket">
      <section class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
        <div class="space-y-6">
          <article class="card p-6">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Ringkasan Kendala</p>
                <h2 class="mt-2 text-xl font-semibold text-gray-900">Yang dilaporkan</h2>
              </div>
              <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
                {{ ticket.category_label }}
              </span>
            </div>

            <div class="mt-5 rounded-[1.35rem] border border-[#efe5d7] bg-[#fffdf9] p-5">
              <p class="text-sm leading-7 text-gray-700">{{ ticket.description }}</p>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
              <div class="rounded-[1.25rem] border border-[#efe5d7] px-4 py-4">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Requester</p>
                <p class="mt-2 text-sm font-semibold text-gray-900">{{ ticket.requester?.name || '-' }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ ticket.requester?.email || '-' }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ ticket.requester?.department || '-' }}</p>
              </div>

              <div class="rounded-[1.25rem] border border-[#efe5d7] px-4 py-4">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Assignment</p>
                <p class="mt-2 text-sm font-semibold text-gray-900">{{ ticket.assignee?.name || 'Belum diambil' }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ ticket.channel_label }}</p>
                <p class="mt-1 text-sm text-gray-500">Update terakhir {{ formatDate(ticket.last_activity_at) }}</p>
              </div>
            </div>

            <div v-if="ticket.attachment_url" class="mt-5">
              <a
                :href="ticket.attachment_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center rounded-[1rem] border border-[#e2d3b8] bg-white px-4 py-3 text-sm font-medium text-[#7b5a24] transition hover:border-[#cfae72] hover:text-[#946815]"
              >
                Buka lampiran {{ ticket.attachment_name ? `· ${ticket.attachment_name}` : '' }}
              </a>
            </div>
          </article>

          <article class="card p-6">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Timeline</p>
                <h2 class="mt-2 text-xl font-semibold text-gray-900">Aktivitas ticket</h2>
              </div>
              <span class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]">
                {{ ticket.updates.length }} aktivitas
              </span>
            </div>

            <div class="mt-6 space-y-4">
              <div
                v-for="update in ticket.updates"
                :key="update.id"
                class="rounded-[1.4rem] border px-5 py-4"
                :class="update.is_internal ? 'border-[#ead8ff] bg-[#faf7ff]' : 'border-[#efe5d7] bg-white'"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]" :class="getUpdateTypeClass(update.type, update.is_internal)">
                        {{ getUpdateTypeLabel(update.type, update.is_internal) }}
                      </span>
                      <p class="text-sm font-semibold text-gray-900">{{ update.user?.name || 'Sistem' }}</p>
                    </div>
                    <p v-if="update.user?.roles?.length" class="mt-1 text-xs text-gray-500">
                      {{ update.user.roles.join(' · ') }}
                    </p>
                  </div>
                  <p class="text-xs font-medium text-gray-400">{{ formatDate(update.created_at) }}</p>
                </div>
                <p v-if="update.message" class="mt-3 text-sm leading-7 text-gray-700">{{ update.message }}</p>
              </div>
            </div>
          </article>
        </div>

        <div class="space-y-6">
          <article class="card p-6">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Balas Ticket</p>
            <h2 class="mt-2 text-xl font-semibold text-gray-900">{{ canManage ? 'Kirim update ke requester' : 'Kirim balasan ke IT' }}</h2>

            <div v-if="replyError" class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {{ replyError }}
            </div>

            <div class="mt-5">
              <textarea
                v-model="replyForm.message"
                class="input-field min-h-[10rem] resize-y"
                :placeholder="canManage ? 'Tulis progres kerja, instruksi ke user, atau update penanganan ticket...' : 'Tambahkan informasi baru, hasil pengecekan Anda, atau respons ke tim IT...'"
              ></textarea>
              <p v-if="replyFieldErrors.message" class="mt-2 text-sm text-red-700">{{ replyFieldErrors.message }}</p>
            </div>

            <label v-if="canManage" class="mt-4 flex items-start gap-3 rounded-[1.2rem] border border-[#efe5d7] bg-[#fffdf9] px-4 py-4">
              <input v-model="replyForm.is_internal" type="checkbox" class="mt-1">
              <span>
                <span class="block text-sm font-medium text-gray-800">Catatan internal IT</span>
                <span class="mt-1 block text-xs leading-5 text-gray-500">Catatan ini hanya terlihat oleh petugas helpdesk dan admin, tidak tampil ke requester.</span>
              </span>
            </label>

            <div class="mt-5 flex justify-end">
              <button type="button" class="btn-primary" :disabled="savingReply" @click="submitReply">
                {{ savingReply ? 'Mengirim...' : 'Kirim Update' }}
              </button>
            </div>
          </article>

          <article class="card p-6">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Metadata</p>
            <h2 class="mt-2 text-xl font-semibold text-gray-900">Informasi ticket</h2>

            <dl class="mt-5 space-y-4">
              <div class="rounded-[1.2rem] border border-[#efe5d7] px-4 py-4">
                <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Dibuat</dt>
                <dd class="mt-2 text-sm text-gray-700">{{ formatDate(ticket.created_at) }}</dd>
              </div>

              <div class="rounded-[1.2rem] border border-[#efe5d7] px-4 py-4">
                <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Halaman saat lapor</dt>
                <dd class="mt-2 break-all text-sm text-gray-700">{{ ticket.context?.page || '-' }}</dd>
              </div>

              <div class="rounded-[1.2rem] border border-[#efe5d7] px-4 py-4">
                <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Device / Browser</dt>
                <dd class="mt-2 break-words text-sm text-gray-700">{{ ticket.context?.user_agent || '-' }}</dd>
              </div>

              <div class="rounded-[1.2rem] border border-[#efe5d7] px-4 py-4">
                <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-[#a57e3a]">Blocking</dt>
                <dd class="mt-2 text-sm text-gray-700">{{ ticket.context?.is_blocking ? 'Ya, pekerjaan terhambat' : 'Tidak' }}</dd>
              </div>
            </dl>
          </article>

          <article v-if="canManage" class="card p-6">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">Panel IT</p>
            <h2 class="mt-2 text-xl font-semibold text-gray-900">Kelola ticket</h2>

            <div v-if="managerError" class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {{ managerError }}
            </div>

            <div class="mt-5 space-y-4">
              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Assignee</label>
                <select v-model="managerForm.assigned_to" class="select-field">
                  <option value="">Belum di-assign</option>
                  <option v-for="assignee in assignees" :key="assignee.id" :value="String(assignee.id)">
                    {{ assignee.name }}{{ assignee.department ? ` · ${assignee.department}` : '' }}
                  </option>
                </select>
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Prioritas</label>
                <select v-model="managerForm.priority" class="select-field">
                  <option v-for="option in filterOptions.priorities" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </div>

              <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
                <select v-model="managerForm.status" class="select-field">
                  <option v-for="option in filterOptions.statuses" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </div>
            </div>

            <div class="mt-5 flex flex-wrap justify-end gap-3">
              <button type="button" class="btn-secondary" :disabled="savingManager" @click="syncManagerForm">
                Reset
              </button>
              <button type="button" class="btn-primary" :disabled="savingManager || !hasManagerChanges" @click="saveManagerChanges">
                {{ savingManager ? 'Menyimpan...' : 'Simpan Perubahan' }}
              </button>
            </div>
          </article>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpdeskStore } from '../../stores/helpdesk';

const route = useRoute();
const router = useRouter();
const helpdeskStore = useHelpdeskStore();

const loading = ref(false);
const error = ref(null);
const savingManager = ref(false);
const savingReply = ref(false);
const savingRequesterAction = ref(false);
const managerError = ref('');
const replyError = ref('');
const replyFieldErrors = ref({});

const managerForm = ref({
  assigned_to: '',
  priority: '',
  status: '',
});

const replyForm = ref({
  message: '',
  is_internal: false,
});

const ticket = computed(() => helpdeskStore.activeTicket);
const canManage = computed(() => helpdeskStore.canManage);
const filterOptions = computed(() => helpdeskStore.filters);
const assignees = computed(() => helpdeskStore.assignees);

const syncManagerForm = () => {
  if (!ticket.value) {
    return;
  }

  managerForm.value = {
    assigned_to: ticket.value.assignee?.id ? String(ticket.value.assignee.id) : '',
    priority: ticket.value.priority,
    status: ticket.value.status,
  };
};

watch(
  () => ticket.value,
  (currentTicket) => {
    if (!currentTicket) {
      return;
    }

    syncManagerForm();
  },
  { immediate: true }
);

const hasManagerChanges = computed(() => {
  if (!ticket.value) {
    return false;
  }

  const assignedTo = ticket.value.assignee?.id ? String(ticket.value.assignee.id) : '';

  return assignedTo !== managerForm.value.assigned_to
    || ticket.value.priority !== managerForm.value.priority
    || ticket.value.status !== managerForm.value.status;
});

const loadTicket = async () => {
  loading.value = true;
  error.value = null;

  try {
    await helpdeskStore.fetchTicket(route.params.id);
  } catch (err) {
    error.value = err.response?.data?.error || 'Terjadi kesalahan saat memuat detail ticket helpdesk.';
  } finally {
    loading.value = false;
  }
};

watch(
  () => route.params.id,
  () => {
    loadTicket();
  }
);

const normalizeErrors = (errors) => {
  return Object.fromEntries(
    Object.entries(errors || {}).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]),
  );
};

const saveManagerChanges = async () => {
  if (!ticket.value) {
    return;
  }

  savingManager.value = true;
  managerError.value = '';

  try {
    await helpdeskStore.updateTicket(ticket.value.id, {
      assigned_to: managerForm.value.assigned_to || null,
      priority: managerForm.value.priority,
      status: managerForm.value.status,
    });
    syncManagerForm();
  } catch (err) {
    managerError.value = err.response?.data?.error || 'Perubahan ticket gagal disimpan.';
  } finally {
    savingManager.value = false;
  }
};

const assignToMe = async () => {
  if (!ticket.value) {
    return;
  }

  savingManager.value = true;
  managerError.value = '';

  try {
    await helpdeskStore.updateTicket(ticket.value.id, {
      assign_to_me: true,
    });
    syncManagerForm();
  } catch (err) {
    managerError.value = err.response?.data?.error || 'Ticket gagal di-assign ke Anda.';
  } finally {
    savingManager.value = false;
  }
};

const submitRequesterAction = async (action) => {
  if (!ticket.value) {
    return;
  }

  savingRequesterAction.value = true;
  replyError.value = '';

  try {
    await helpdeskStore.updateTicket(ticket.value.id, { action });
  } catch (err) {
    replyError.value = err.response?.data?.error || 'Aksi ticket gagal diproses.';
  } finally {
    savingRequesterAction.value = false;
  }
};

const submitReply = async () => {
  if (!ticket.value) {
    return;
  }

  savingReply.value = true;
  replyError.value = '';
  replyFieldErrors.value = {};

  try {
    await helpdeskStore.addUpdate(ticket.value.id, {
      message: replyForm.value.message,
      is_internal: replyForm.value.is_internal,
    });

    replyForm.value = {
      message: '',
      is_internal: false,
    };
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      replyFieldErrors.value = normalizeErrors(err.response.data.errors);
      return;
    }

    replyError.value = err.response?.data?.error || 'Update ticket gagal dikirim.';
  } finally {
    savingReply.value = false;
  }
};

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

const getStatusClass = (status) => {
  const classes = {
    open: 'bg-[#fff4dd] text-[#8f6115]',
    in_progress: 'bg-[#eef4ff] text-[#315ea8]',
    waiting_user: 'bg-[#f3ecff] text-[#6b46c1]',
    resolved: 'bg-[#edf8f1] text-[#1f8f51]',
    closed: 'bg-[#f3f4f6] text-[#4b5563]',
  };

  return classes[status] || 'bg-[#f3f4f6] text-[#4b5563]';
};

const getPriorityClass = (priority) => {
  const classes = {
    low: 'bg-[#f3f4f6] text-[#4b5563]',
    normal: 'bg-[#fff4dd] text-[#8f6115]',
    high: 'bg-[#fff1e8] text-[#c05621]',
    critical: 'bg-[#fff3f3] text-[#c24141]',
  };

  return classes[priority] || 'bg-[#f3f4f6] text-[#4b5563]';
};

const getUpdateTypeLabel = (type, isInternal = false) => {
  if (isInternal) {
    return 'Catatan Internal';
  }

  const labels = {
    created: 'Dibuat',
    assigned: 'Assignment',
    comment: 'Komentar',
    internal_note: 'Catatan Internal',
    status_changed: 'Status',
    priority_changed: 'Prioritas',
  };

  return labels[type] || 'Aktivitas';
};

const getUpdateTypeClass = (type, isInternal = false) => {
  if (isInternal) {
    return 'bg-[#efe7ff] text-[#6b46c1]';
  }

  const classes = {
    created: 'bg-[#fbf5ea] text-[#8f6115]',
    assigned: 'bg-[#eef4ff] text-[#315ea8]',
    comment: 'bg-[#edf8f1] text-[#1f8f51]',
    status_changed: 'bg-[#fff1e8] text-[#c05621]',
    priority_changed: 'bg-[#fff3f3] text-[#c24141]',
  };

  return classes[type] || 'bg-[#f3f4f6] text-[#4b5563]';
};

const goBack = () => {
  router.push('/helpdesk');
};

onMounted(() => {
  loadTicket();
});

onUnmounted(() => {
  helpdeskStore.resetActiveTicket();
});
</script>
