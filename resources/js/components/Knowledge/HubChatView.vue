<template>
  <div class="flex h-full min-h-0 flex-col gap-4 overflow-hidden">
    <div v-if="error" class="rounded-[24px] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700">
      {{ error }}
    </div>

    <section v-if="loading" class="card px-6 py-12 text-center">
      <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600"></div>
      <p class="mt-4 text-sm font-medium text-secondary-700">Memuat AI Chat Assistant...</p>
    </section>

    <article v-else class="relative flex h-full min-h-[38rem] min-w-0 flex-col overflow-hidden rounded-[24px] border border-[#e8dcc9] bg-white shadow-[0_14px_30px_rgba(41,28,9,0.05)]">
      <div
        v-if="historyLoading"
        class="pointer-events-none absolute inset-x-0 top-0 z-10 flex items-center justify-center bg-gradient-to-b from-white via-white/95 to-transparent px-4 py-4"
      >
        <div class="inline-flex items-center gap-3 rounded-full border border-[#efe5d7] bg-white px-4 py-2 text-sm text-[#6b7280] shadow-[0_10px_24px_rgba(41,28,9,0.05)]">
          <div class="h-4 w-4 animate-spin rounded-full border-2 border-[#f3e4bf] border-t-[#9b6b17]"></div>
          <p>Memuat obrolan...</p>
        </div>
      </div>

      <div ref="messageViewport" class="min-h-0 flex-1 overflow-y-auto bg-[#fcfbf8] px-6 py-6 sm:px-8">
        <div v-if="!hasConversation" class="flex h-full min-h-[28rem] items-center justify-center">
          <div class="w-full max-w-3xl text-center">
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-[#a57e3a]">AI Chat Assistant</p>
            <h2 class="mt-4 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.4rem]">
              Apa yang ingin Anda ketahui?
            </h2>
            <p class="mt-3 text-sm text-[#6b7280]">
              Tanya SOP, panduan operasional, atau knowledge internal perusahaan.
            </p>
          </div>
        </div>

        <div v-else class="mx-auto w-full max-w-4xl space-y-8 py-2">
          <article
            v-for="message in visibleMessages"
            :key="message.id"
            :class="message.role === 'assistant' ? 'max-w-3xl' : 'ml-auto max-w-2xl'"
          >
            <div
              class="rounded-[24px] px-5 py-4"
              :class="message.role === 'assistant' ? 'bg-white text-[#374151]' : 'bg-[#f6efe0] text-[#3b2c13]'"
            >
              <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold uppercase tracking-[0.18em]" :class="message.role === 'assistant' ? 'text-[#a57e3a]' : 'text-[#7b5a24]'">
                  {{ message.role === 'assistant' ? 'Assistant' : 'Anda' }}
                </p>
                <span
                  v-if="message.scopeLabel"
                  class="rounded-full bg-[#f4efe6] px-3 py-1 text-[11px] font-semibold text-[#8f6115]"
                >
                  {{ message.scopeLabel }}
                </span>
              </div>

              <div class="mt-3 whitespace-pre-wrap text-sm leading-7">
                {{ message.content }}
              </div>
            </div>

            <div v-if="message.sources?.length" class="mt-4 space-y-3">
              <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#9ca3af]">Evidence</p>
              <button
                v-for="source in message.sources"
                :key="`${message.id}-${source.id}`"
                type="button"
                class="w-full rounded-[18px] border border-[#efe5d7] bg-white px-4 py-3 text-left transition hover:border-[#d8bc84]"
                @click="openSource(source.id)"
              >
                <p class="text-sm font-semibold text-[#111827]">{{ source.title }}</p>
                <p class="mt-1 text-xs uppercase tracking-[0.16em] text-[#9ca3af]">
                  {{ source.path_label || source.space_name }} · {{ source.type_label }} · {{ source.version_label }}
                </p>
                <p class="mt-2 text-sm text-[#6b7280]">{{ source.summary }}</p>
              </button>
            </div>
          </article>

          <div v-if="chatLoading" class="max-w-3xl rounded-[24px] bg-white px-5 py-4 text-sm text-[#6b7280]">
            <div class="flex items-center gap-3">
              <div class="h-4 w-4 animate-spin rounded-full border-2 border-[#f3e4bf] border-t-[#9b6b17]"></div>
              <p>Assistant sedang mencari evidence...</p>
            </div>
          </div>
        </div>
      </div>

      <div class="border-t border-[#f0e6d7] bg-white px-6 py-5 sm:px-8">
        <div class="mx-auto w-full max-w-4xl">
          <div class="rounded-[28px] border border-[#e8dcc9] bg-[#fcfbf8] px-5 py-4">
            <textarea
              ref="composerRef"
              v-model="chatInput"
              rows="1"
              class="max-h-52 min-h-[1.75rem] w-full resize-none border-0 bg-transparent p-0 text-sm leading-7 text-[#374151] outline-none placeholder:text-[#9ca3af] focus:ring-0"
              placeholder="Tanyakan knowledge perusahaan..."
              @input="resizeComposer"
              @keydown.enter.exact.prevent="handleSubmit()"
            ></textarea>

            <div class="mt-3 flex items-center justify-between gap-3">
              <p class="text-xs text-[#9ca3af]">Enter untuk kirim, Shift + Enter untuk baris baru.</p>
              <button
                type="button"
                class="inline-flex h-11 items-center justify-center rounded-full bg-[#9b6b17] px-5 text-sm font-semibold text-white transition hover:bg-[#83580f] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="chatLoading || chatInput.trim() === ''"
                @click="handleSubmit()"
              >
                {{ chatLoading ? 'Memproses...' : 'Kirim' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </article>
  </div>
</template>

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { injectKnowledgeHubWorkspace } from '../../composables/useKnowledgeHubWorkspace';

const router = useRouter();
const {
  loading,
  error,
  chatLoading,
  historyLoading,
  chatInput,
  messages,
  visibleMessages,
  hasConversation,
  selectedPreviewPage,
  openSourceFromChat,
  submitQuestion,
} = injectKnowledgeHubWorkspace();

const messageViewport = ref(null);
const composerRef = ref(null);

const handleSubmit = (question = '') => {
  submitQuestion(question);
};

const resizeComposer = async () => {
  await nextTick();

  if (!composerRef.value) {
    return;
  }

  composerRef.value.style.height = '0px';
  composerRef.value.style.height = `${composerRef.value.scrollHeight}px`;
};

const openSource = async (entryId) => {
  const entry = openSourceFromChat(entryId);

  if (!entry) {
    return;
  }

  await router.push({
    name: 'knowledge-hub-documents',
    query: {
      entry: String(entry.id),
      ...(selectedPreviewPage.value ? { page: String(selectedPreviewPage.value) } : {}),
    },
  });
};

watch([() => messages.value.length, chatLoading], async () => {
  await nextTick();

  if (messageViewport.value) {
    messageViewport.value.scrollTop = messageViewport.value.scrollHeight;
  }
}, { immediate: true });

watch(chatInput, () => {
  resizeComposer();
});

onMounted(() => {
  resizeComposer();
});
</script>
