<template>
  <div class="space-y-4 pb-8">
    <section class="border-b border-[#eadfcf] pb-4">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight text-[#111827]">Buat Workflow Form</h1>
          <p class="mt-2 text-sm text-[#6b7280]">
            Atur alur approval form secara ringkas dan konsisten.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <div class="flex flex-wrap gap-3 text-sm text-[#6b7280]">
            <span>{{ workflows.length }} workflow</span>
            <span>{{ activeWorkflowCount }} aktif</span>
          </div>
          <button type="button" class="btn-secondary" @click="router.push('/settings')">Kembali</button>
          <button type="button" class="btn-primary" @click="startCreateWorkflow">Workflow Baru</button>
        </div>
      </div>
    </section>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="h-12 w-12 animate-spin rounded-full border-b-2 border-gray-900"></div>
    </div>

    <div v-else-if="error" class="rounded-[28px] border border-red-200 bg-red-50 p-6 text-red-800">
      <h2 class="text-lg font-semibold">Workflow gagal dimuat</h2>
      <p class="mt-2 text-sm">{{ error }}</p>
      <button type="button" class="btn-primary mt-4" @click="loadPage">Coba Lagi</button>
    </div>

    <div v-else class="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
      <section class="rounded-[20px] border border-[#e8dcc9] bg-white p-4 sm:p-5">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-[#111827]">Workflow</h2>
            <p class="mt-1 text-sm text-[#6b7280]">Pilih untuk edit.</p>
          </div>
          <span class="rounded-full border border-[#eadfcf] bg-white px-3 py-1 text-xs font-medium text-[#6b7280]">
            {{ activeWorkflowCount }} aktif
          </span>
        </div>

        <div v-if="workflows.length === 0" class="mt-5 rounded-[18px] border border-dashed border-[#d8c7aa] px-5 py-10 text-center">
          <p class="text-base font-semibold text-[#111827]">Belum ada workflow</p>
          <p class="mt-2 text-sm text-[#6b7280]">Mulai dari template default lalu sesuaikan approval sesuai SOP.</p>
        </div>

        <div v-else class="mt-5 divide-y divide-[#f0e6d7]">
          <button
            v-for="workflow in workflows"
            :key="workflow.id"
            type="button"
            class="w-full px-2 py-4 text-left transition"
            :class="selectedWorkflowId === workflow.id
              ? 'bg-[#fff8eb]'
              : 'bg-white hover:bg-[#fffdf9]'"
            @click="selectWorkflow(workflow)"
          >
            <div class="flex items-start gap-3">
              <span
                class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full"
                :class="workflow.is_active ? 'bg-emerald-500' : 'bg-gray-300'"
              ></span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-[#111827]">{{ workflow.name }}</p>
                <p class="mt-1 truncate text-xs text-[#6b7280]">
                  {{ workflow.steps_count }} step · {{ workflow.forms_count }} form · {{ workflow.slug }}
                </p>
              </div>
            </div>
          </button>
        </div>
      </section>

      <section class="rounded-[20px] border border-[#e8dcc9] bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <p class="text-sm font-medium text-[#8f6115]">{{ form.id ? 'Edit workflow' : 'Workflow baru' }}</p>
            <h2 class="mt-1 text-xl font-semibold text-[#111827]">
              {{ form.name || 'Draft Workflow Baru' }}
            </h2>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-[#6b7280]">
              Definisikan urutan approval, siapa aktornya, dan status yang dipakai submission.
            </p>
          </div>

          <div class="flex shrink-0 flex-row flex-wrap items-center justify-start gap-2 lg:justify-end">
            <button
              v-if="form.id"
              type="button"
              class="inline-flex h-10 items-center justify-center rounded-full border border-red-200 bg-white px-4 text-sm font-medium text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isDeleting"
              @click="deleteWorkflow"
            >
              {{ isDeleting ? 'Menghapus...' : 'Hapus Workflow' }}
            </button>
            <button
              type="button"
              class="inline-flex h-10 items-center justify-center rounded-full border border-[#d8bc84] bg-[#fffaf0] px-4 text-sm font-semibold text-[#8f6115] transition hover:border-[#cfa65a] hover:bg-[#fff6e8] disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isSaving"
              @click="saveWorkflow"
            >
              {{ isSaving ? 'Menyimpan...' : (form.id ? 'Simpan Perubahan' : 'Buat Workflow') }}
            </button>
          </div>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Nama Workflow</label>
            <input v-model="form.name" type="text" class="input-field" placeholder="Contoh: SOP Persetujuan Vendor Baru">
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-[#374151]">Slug</label>
            <input v-model="form.slug" type="text" class="input-field" placeholder="Opsional, otomatis bila dikosongkan">
          </div>

          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-[#374151]">Deskripsi</label>
            <textarea
              v-model="form.description"
              rows="3"
              class="input-field"
              placeholder="Jelaskan SOP, tipe approval, atau kebutuhan bisnis yang ditangani workflow ini."
            ></textarea>
          </div>

          <div class="md:col-span-2">
            <label class="inline-flex items-center gap-3 text-sm text-[#4b5563]">
              <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
              Workflow aktif dan bisa dipasang ke form
            </label>
          </div>
        </div>

        <div class="mt-8 border-t border-[#f0e6d7] pt-6">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 class="text-base font-semibold text-[#111827]">Step Approval</h3>
              <p class="mt-1 text-sm text-[#6b7280]">Isi konfigurasi utama dulu. Pengaturan teknis bisa dibuka saat dibutuhkan.</p>
            </div>
            <button type="button" class="btn-secondary" @click="addStep">Tambah Step</button>
          </div>

          <div class="mt-4 space-y-3">
            <article
              v-for="(step, index) in form.workflow_config.steps"
              :key="step.local_id"
              class="rounded-[18px] border border-[#e8dcc9] bg-white p-4"
            >
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 gap-3">
                  <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[#eadfcf] bg-[#fcfbf8] text-sm font-semibold text-[#8f6115]">
                    {{ index + 1 }}
                  </span>
                  <div class="min-w-0">
                    <h4 class="truncate text-base font-semibold text-[#111827]">{{ step.name || `Langkah ${index + 1}` }}</h4>
                    <p class="mt-1 text-xs text-[#6b7280]">
                      {{ actorTypeLabel(step.actor_type) }} · {{ step.action || 'approve' }} · {{ step.entry_status || 'status belum diisi' }}
                    </p>
                    <div v-if="step.requires_signature || step.notes_required || step.allow_form_edit" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-[#8f6115]">
                      <span v-if="step.requires_signature">TTD</span>
                      <span v-if="step.notes_required">Catatan wajib</span>
                      <span v-if="step.allow_form_edit">Edit form</span>
                    </div>
                  </div>
                </div>

                <div class="flex shrink-0 flex-wrap gap-2">
                  <button type="button" class="rounded-full border border-[#eadfcf] px-3 py-1.5 text-xs font-medium text-[#4b5563] transition hover:border-[#d8bc84] disabled:cursor-not-allowed disabled:opacity-40" :disabled="index === 0" @click="moveStep(index, index - 1)">
                    Naik
                  </button>
                  <button type="button" class="rounded-full border border-[#eadfcf] px-3 py-1.5 text-xs font-medium text-[#4b5563] transition hover:border-[#d8bc84] disabled:cursor-not-allowed disabled:opacity-40" :disabled="index === form.workflow_config.steps.length - 1" @click="moveStep(index, index + 1)">
                    Turun
                  </button>
                  <button type="button" class="rounded-full border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50" @click="removeStep(index)">
                    Hapus
                  </button>
                </div>
              </div>

              <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                  <label class="mb-2 block text-sm font-medium text-[#374151]">Nama Step</label>
                  <input v-model="step.name" type="text" class="input-field" placeholder="Contoh: Review Accounting">
                </div>

                <div>
                  <label class="mb-2 block text-sm font-medium text-[#374151]">Actor Type</label>
                  <select v-model="step.actor_type" class="select-field" @change="syncActorDefaults(step)">
                    <option value="requester">Requester</option>
                    <option value="role">Role</option>
                    <option value="user">User Spesifik</option>
                    <option value="system">System</option>
                  </select>
                </div>

                <div>
                  <label class="mb-2 block text-sm font-medium text-[#374151]">Actor Target</label>
                  <select v-if="step.actor_type === 'role'" v-model="step.actor_value" class="select-field">
                    <option value="">Pilih role</option>
                    <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                  </select>

                  <select v-else-if="step.actor_type === 'user'" v-model="step.actor_value" class="select-field">
                    <option value="">Pilih user</option>
                    <option v-for="user in users" :key="user.id" :value="String(user.id)">
                      {{ user.name }} · {{ user.roles.join(', ') || 'Tanpa role' }}
                    </option>
                  </select>

                  <input
                    v-else
                    :value="step.actor_type === 'requester' ? 'Pemohon submit' : 'Diproses otomatis oleh sistem'"
                    type="text"
                    class="input-field bg-gray-50"
                    readonly
                  >
                </div>

                <div>
                  <label class="mb-2 block text-sm font-medium text-[#374151]">Action</label>
                  <select v-model="step.action" class="select-field" @change="syncActionDefaults(step)">
                    <option v-for="action in actionOptions" :key="action.value" :value="action.value">
                      {{ action.label }}
                    </option>
                  </select>
                </div>
              </div>

              <details class="mt-4 rounded-[16px] border border-[#f0e6d7] bg-[#fcfbf8]">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-[#374151]">
                  Pengaturan lanjutan
                </summary>

                <div class="grid gap-4 border-t border-[#f0e6d7] p-4 md:grid-cols-2">
                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Step Key</label>
                    <input v-model="step.step_key" type="text" class="input-field" placeholder="Contoh: accounting_review">
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Next Step</label>
                    <select v-model="step.next_step_key" class="select-field">
                      <option value="">Ikuti urutan default</option>
                      <option
                        v-for="option in nextStepOptions(step.local_id)"
                        :key="option.step_key"
                        :value="option.step_key"
                      >
                        Step {{ option.step_number }} · {{ option.name }}
                      </option>
                    </select>
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Entry Status</label>
                    <input v-model="step.entry_status" type="text" class="input-field" placeholder="Contoh: pending_accounting">
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Approve Status</label>
                    <input
                      v-model="step.approve_status"
                      type="text"
                      class="input-field"
                      placeholder="Kosongkan agar mengikuti status step berikutnya"
                    >
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Reject Status</label>
                    <input v-model="step.reject_status" type="text" class="input-field" placeholder="Default: rejected">
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">CTA Label</label>
                    <input v-model="step.cta_label" type="text" class="input-field" placeholder="Contoh: Setujui untuk Finance">
                  </div>

                  <div>
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Label Tombol Reject</label>
                    <input v-model="step.reject_label" type="text" class="input-field" placeholder="Contoh: Kembalikan">
                  </div>

                  <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-[#374151]">Placeholder Catatan</label>
                    <textarea
                      v-model="step.notes_placeholder"
                      rows="3"
                      class="input-field"
                      placeholder="Petunjuk catatan yang harus diisi approver pada step ini."
                    ></textarea>
                  </div>

                  <div class="md:col-span-2 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label class="flex items-center gap-3 rounded-xl border border-[#eadfcf] bg-white px-4 py-3">
                      <input v-model="step.auto_complete" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                      <span class="text-sm text-[#4b5563]">Auto complete</span>
                    </label>
                    <label class="flex items-center gap-3 rounded-xl border border-[#eadfcf] bg-white px-4 py-3">
                      <input v-model="step.requires_signature" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]" :disabled="step.actor_type === 'system'">
                      <span class="text-sm text-[#4b5563]">Perlu tanda tangan</span>
                    </label>
                    <label class="flex items-center gap-3 rounded-xl border border-[#eadfcf] bg-white px-4 py-3">
                      <input v-model="step.notes_required" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]">
                      <span class="text-sm text-[#4b5563]">Catatan wajib</span>
                    </label>
                    <label class="flex items-center gap-3 rounded-xl border border-[#eadfcf] bg-white px-4 py-3">
                      <input v-model="step.allow_form_edit" type="checkbox" class="h-4 w-4 rounded border-[#d7bc84] text-[#9b6b17]" :disabled="step.actor_type === 'system'">
                      <span class="text-sm text-[#4b5563]">Boleh edit form</span>
                    </label>
                  </div>
                </div>
              </details>
            </article>
          </div>
        </div>

        <div class="mt-8 border-t border-[#f0e6d7] pt-6">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 class="text-base font-semibold text-[#111827]">Preview Status Workflow</h3>
              <p class="mt-1 text-sm text-[#6b7280]">Status di bawah akan dipakai untuk pelacakan submission dan pencarian.</p>
            </div>
            <span class="rounded-full border border-[#eadfcf] bg-white px-3 py-1 text-xs font-medium text-[#6b7280]">
              {{ statusPreview.length }} status
            </span>
          </div>

          <div class="mt-4 flex flex-wrap gap-2">
            <span
              v-for="status in statusPreview"
              :key="status"
              class="rounded-full border border-[#eadfcf] bg-white px-3 py-1 text-xs font-medium text-[#6b7280]"
            >
              {{ status }}
            </span>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useWorkflowStore } from '../../stores/workflows';

const router = useRouter();
const workflowStore = useWorkflowStore();

const loading = ref(false);
const error = ref(null);
const isSaving = ref(false);
const isDeleting = ref(false);
const selectedWorkflowId = ref(null);
const stepCounter = ref(0);

const actionOptions = [
  { value: 'submit', label: 'Submit' },
  { value: 'review', label: 'Review' },
  { value: 'approve', label: 'Approve' },
  { value: 'process', label: 'Process' },
  { value: 'process_payment', label: 'Process Payment' },
  { value: 'mark_paid', label: 'Mark Paid' },
  { value: 'complete', label: 'Complete' },
];

const form = reactive(makeEmptyWorkflow());

const workflows = computed(() => workflowStore.workflows);
const roles = computed(() => workflowStore.roles);
const users = computed(() => workflowStore.users);
const activeWorkflowCount = computed(() => workflows.value.filter((workflow) => workflow.is_active).length);
const statusPreview = computed(() => {
  const statuses = [];

  form.workflow_config.steps.forEach((step) => {
    [step.entry_status, step.approve_status, step.reject_status]
      .map((value) => typeof value === 'string' ? value.trim() : '')
      .filter(Boolean)
      .forEach((status) => {
        if (!statuses.includes(status)) {
          statuses.push(status);
        }
      });
  });

  return statuses;
});

function nextStepLocalId() {
  stepCounter.value += 1;

  return `workflow-step-${Date.now()}-${stepCounter.value}`;
}

function makeStep(seed = {}) {
  return {
    local_id: seed.local_id || nextStepLocalId(),
    step_key: seed.step_key || '',
    name: seed.name || '',
    actor_type: seed.actor_type || 'role',
    actor_value: seed.actor_value != null ? String(seed.actor_value) : '',
    action: seed.action || 'approve',
    entry_status: seed.entry_status || '',
    approve_status: seed.approve_status || '',
    reject_status: seed.reject_status || 'rejected',
    auto_complete: Boolean(seed.auto_complete),
    requires_signature: Boolean(seed.requires_signature),
    notes_required: Boolean(seed.notes_required),
    allow_form_edit: Boolean(seed.allow_form_edit),
    cta_label: seed.cta_label || '',
    reject_label: seed.reject_label || 'Tolak',
    notes_placeholder: seed.notes_placeholder || 'Tambahkan catatan untuk langkah ini.',
    next_step_key: seed.next_step_key || '',
  };
}

function makeEmptyWorkflow() {
  return {
    id: null,
    name: '',
    slug: '',
    description: '',
    is_active: true,
    workflow_config: {
      steps: [
        makeStep({
          step_key: 'submit_request',
          name: 'Pengajuan Dibuat',
          actor_type: 'requester',
          action: 'submit',
          entry_status: 'submitted',
          auto_complete: true,
          cta_label: 'Kirim Pengajuan',
          reject_label: 'Tolak',
        }),
        makeStep({
          step_key: 'review_step',
          name: 'Review Approval',
          actor_type: 'role',
          action: 'approve',
          entry_status: 'pending_review',
          cta_label: 'Setujui',
          reject_label: 'Tolak',
        }),
        makeStep({
          step_key: 'complete',
          name: 'Selesai',
          actor_type: 'system',
          action: 'complete',
          entry_status: 'completed',
          auto_complete: true,
          cta_label: 'Selesai',
          reject_label: 'Tolak',
        }),
      ],
    },
  };
}

function replaceForm(nextValue) {
  const empty = makeEmptyWorkflow();

  Object.assign(form, {
    ...empty,
    ...nextValue,
    workflow_config: {
      steps: (nextValue.workflow_config?.steps || empty.workflow_config.steps).map((step) => makeStep(step)),
    },
  });
}

function effectiveStepKey(step, index) {
  const rawKey = typeof step.step_key === 'string' ? step.step_key.trim() : '';

  if (rawKey !== '') {
    return rawKey;
  }

  return `step_${index + 1}`;
}

function actorTypeLabel(actorType) {
  return {
    requester: 'Requester',
    role: 'Role',
    user: 'User Spesifik',
    system: 'System',
  }[actorType] || 'Role';
}

function selectWorkflow(workflow) {
  selectedWorkflowId.value = workflow.id;
  replaceForm(workflow);
}

function startCreateWorkflow() {
  selectedWorkflowId.value = null;
  replaceForm(makeEmptyWorkflow());
}

function addStep() {
  const steps = form.workflow_config.steps;
  const insertIndex = steps.findIndex((step) => step.action === 'complete' && step.actor_type === 'system');
  const nextNumber = steps.length + 1;
  const newStep = makeStep({
    step_key: `step_${nextNumber}`,
    name: `Langkah ${nextNumber}`,
    actor_type: 'role',
    action: 'approve',
    entry_status: `pending_step_${nextNumber}`,
    cta_label: 'Setujui',
  });

  if (insertIndex === -1) {
    steps.push(newStep);
    return;
  }

  steps.splice(insertIndex, 0, newStep);
}

function removeStep(index) {
  form.workflow_config.steps.splice(index, 1);

  if (form.workflow_config.steps.length === 0) {
    addStep();
  }
}

function moveStep(fromIndex, toIndex) {
  const steps = form.workflow_config.steps;

  if (toIndex < 0 || toIndex >= steps.length) {
    return;
  }

  const [movedStep] = steps.splice(fromIndex, 1);
  steps.splice(toIndex, 0, movedStep);
}

function syncActorDefaults(step) {
  if (step.actor_type === 'requester' || step.actor_type === 'system') {
    step.actor_value = '';
  }

  if (step.actor_type === 'system') {
    step.auto_complete = true;
    step.requires_signature = false;
    step.allow_form_edit = false;
  }
}

function syncActionDefaults(step) {
  if (step.action === 'submit') {
    step.actor_type = 'requester';
    step.auto_complete = true;
    step.requires_signature = false;
    step.allow_form_edit = false;
    step.entry_status = step.entry_status || 'submitted';
    step.cta_label = step.cta_label || 'Kirim Pengajuan';
    step.reject_label = step.reject_label || 'Tolak';
    step.actor_value = '';
    return;
  }

  if (step.action === 'complete') {
    step.actor_type = 'system';
    step.auto_complete = true;
    step.requires_signature = false;
    step.allow_form_edit = false;
    step.entry_status = step.entry_status || 'completed';
    step.cta_label = step.cta_label || 'Selesai';
    step.actor_value = '';
    return;
  }

  if (!step.cta_label) {
    step.cta_label = step.action === 'review' ? 'Simpan Review' : 'Setujui';
  }

  if (!step.reject_label) {
    step.reject_label = 'Tolak';
  }
}

function nextStepOptions(currentLocalId) {
  return form.workflow_config.steps
    .map((step, index) => ({
      local_id: step.local_id,
      step_key: effectiveStepKey(step, index),
      step_number: index + 1,
      name: step.name || `Langkah ${index + 1}`,
    }))
    .filter((step) => step.local_id !== currentLocalId);
}

function humanizeUser(user) {
  const secondary = [user.department, user.email].filter(Boolean).join(' · ');

  return secondary ? `${user.name} · ${secondary}` : user.name;
}

function validateForm() {
  if (!form.name.trim()) {
    return 'Nama workflow wajib diisi.';
  }

  if (form.workflow_config.steps.length === 0) {
    return 'Workflow wajib memiliki minimal satu step.';
  }

  const usedKeys = new Set();

  for (const [index, step] of form.workflow_config.steps.entries()) {
    if (!step.name.trim()) {
      return `Nama step ${index + 1} wajib diisi.`;
    }

    const stepKey = effectiveStepKey(step, index);

    if (usedKeys.has(stepKey)) {
      return `Step key '${stepKey}' duplikat.`;
    }

    usedKeys.add(stepKey);

    if (!step.entry_status.trim()) {
      return `Entry status step ${index + 1} wajib diisi.`;
    }

    if (step.actor_type === 'role' && !step.actor_value) {
      return `Role untuk step ${index + 1} wajib dipilih.`;
    }

    if (step.actor_type === 'user' && !step.actor_value) {
      return `User untuk step ${index + 1} wajib dipilih.`;
    }

    if (step.next_step_key && step.next_step_key === stepKey) {
      return `Step ${index + 1} tidak boleh menunjuk ke dirinya sendiri.`;
    }
  }

  return null;
}

function buildPayload() {
  return {
    name: form.name.trim(),
    slug: form.slug.trim() || null,
    description: form.description.trim() || null,
    is_active: Boolean(form.is_active),
    workflow_config: {
      steps: form.workflow_config.steps.map((step, index) => {
        const actorType = step.action === 'complete'
          ? 'system'
          : (step.action === 'submit' ? 'requester' : step.actor_type);

        return {
          step_key: effectiveStepKey(step, index),
          step_number: index + 1,
          name: step.name.trim(),
          actor_type: actorType,
          actor_value: ['role', 'user'].includes(actorType) ? (step.actor_value || null) : null,
          action: step.action,
          entry_status: step.entry_status.trim(),
          approve_status: step.approve_status.trim() || null,
          reject_status: step.reject_status.trim() || 'rejected',
          auto_complete: step.action === 'complete' || step.action === 'submit' ? true : Boolean(step.auto_complete),
          requires_signature: actorType === 'system' ? false : Boolean(step.requires_signature),
          notes_required: Boolean(step.notes_required),
          allow_form_edit: actorType === 'system' ? false : Boolean(step.allow_form_edit),
          cta_label: step.cta_label.trim() || null,
          reject_label: step.reject_label.trim() || 'Tolak',
          notes_placeholder: step.notes_placeholder.trim() || 'Tambahkan catatan untuk langkah ini.',
          next_step_key: step.next_step_key || null,
        };
      }),
      statuses: statusPreview.value,
    },
  };
}

async function saveWorkflow() {
  const validationMessage = validateForm();

  if (validationMessage) {
    window.alert(validationMessage);
    return;
  }

  isSaving.value = true;

  try {
    const payload = buildPayload();
    let response;

    if (form.id) {
      response = await workflowStore.updateWorkflow(form.id, payload);
    } else {
      response = await workflowStore.createWorkflow(payload);
    }

    selectWorkflow(response.workflow);
  } catch (err) {
    console.error('Error saving workflow:', err);
    window.alert(err.response?.data?.error || 'Workflow gagal disimpan.');
  } finally {
    isSaving.value = false;
  }
}

async function deleteWorkflow() {
  if (!form.id) {
    startCreateWorkflow();
    return;
  }

  if (!window.confirm(`Hapus workflow "${form.name}"? Hanya aman bila belum dipakai form.`)) {
    return;
  }

  isDeleting.value = true;

  try {
    await workflowStore.deleteWorkflow(form.id);

    if (workflowStore.workflows.length > 0) {
      selectWorkflow(workflowStore.workflows[0]);
    } else {
      startCreateWorkflow();
    }
  } catch (err) {
    console.error('Error deleting workflow:', err);
    window.alert(err.response?.data?.error || 'Workflow gagal dihapus.');
  } finally {
    isDeleting.value = false;
  }
}

async function loadPage() {
  loading.value = true;
  error.value = null;

  try {
    await Promise.all([
      workflowStore.fetchWorkflows(),
      workflowStore.fetchCatalog(),
    ]);

    if (workflowStore.workflows.length > 0) {
      selectWorkflow(workflowStore.workflows[0]);
    } else {
      startCreateWorkflow();
    }
  } catch (err) {
    console.error('Error loading workflows page:', err);
    error.value = err.response?.data?.error || 'Data workflow tidak dapat dimuat.';
  } finally {
    loading.value = false;
  }
}

onMounted(loadPage);
</script>
