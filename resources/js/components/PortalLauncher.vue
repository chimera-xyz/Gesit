<template>
  <div class="space-y-8">
    <section class="overflow-hidden rounded-[32px] border border-[#eadfc7] bg-[linear-gradient(135deg,#fff8ea_0%,#ffffff_46%,#f6efe3_100%)] shadow-[0_22px_50px_rgba(41,28,9,0.08)]">
      <div class="grid gap-6 px-6 py-7 sm:px-8 sm:py-8 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)] lg:items-end">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">Unified Access Portal</p>
          <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.4rem]">
            Satu gerbang untuk workspace internal Yulie.
          </h1>
          <p class="mt-4 max-w-2xl text-sm leading-7 text-[#5f6672]">
            Login tetap di Gesit, lalu pilih aplikasi kerja yang memang aktif untuk akun Anda.
            Dashboard default akan mengikuti akses utama yang sudah diatur admin.
          </p>
        </div>

        <div class="rounded-[28px] border border-[#eadfc7] bg-white/90 p-5 shadow-[0_16px_30px_rgba(41,28,9,0.06)]">
          <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-[#9b6b17]">Ringkasan Akses</p>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="rounded-[22px] bg-[#fcf7ee] px-4 py-4">
              <p class="text-xs uppercase tracking-[0.18em] text-[#9ca3af]">App aktif</p>
              <p class="mt-2 text-2xl font-semibold text-[#111827]">{{ apps.length }}</p>
            </div>
            <div class="rounded-[22px] bg-[#f4f8ff] px-4 py-4">
              <p class="text-xs uppercase tracking-[0.18em] text-[#9ca3af]">Default login</p>
              <p class="mt-2 text-base font-semibold text-[#111827]">{{ homeAppName }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <div
      v-if="portalNotice"
      class="rounded-[24px] border px-5 py-4 text-sm"
      :class="portalNotice.tone === 'danger'
        ? 'border-red-200 bg-red-50 text-red-700'
        : 'border-amber-200 bg-amber-50 text-amber-800'"
    >
      {{ portalNotice.message }}
    </div>

    <section class="grid gap-4 lg:grid-cols-2">
      <button
        v-for="app in apps"
        :key="app.key"
        type="button"
        class="group relative overflow-hidden rounded-[30px] border p-6 text-left shadow-[0_18px_36px_rgba(41,28,9,0.06)] transition duration-200 hover:-translate-y-0.5"
        :class="cardClass(app.key)"
        @click="launch(app)"
      >
        <div class="absolute inset-x-0 top-0 h-1.5 opacity-90" :class="barClass(app.key)"></div>

        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]" :class="chipClass(app.key)">
                {{ app.badge || 'Internal App' }}
              </span>
              <span
                v-if="app.is_home"
                class="rounded-full bg-[#111827] px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white"
              >
                Default
              </span>
            </div>

            <h2 class="mt-4 text-2xl font-semibold text-[#111827]">{{ app.name }}</h2>
            <p class="mt-3 max-w-xl text-sm leading-7 text-[#5f6672]">{{ app.description }}</p>
          </div>

          <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[20px] border bg-white/80" :class="iconShellClass(app.key)">
            <svg v-if="app.key === 'gesit'" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4.5 8.25h15m-15 0V6.75A2.25 2.25 0 0 1 6.75 4.5h10.5a2.25 2.25 0 0 1 2.25 2.25v1.5m-15 0V17.25A2.25 2.25 0 0 0 6.75 19.5h10.5a2.25 2.25 0 0 0 2.25-2.25V8.25m-9 3h3m-6 4.5h9" />
            </svg>
            <svg v-else class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4.5 6.75A2.25 2.25 0 0 1 6.75 4.5h10.5a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 17.25V6.75Zm3.75 2.25h7.5m-7.5 3.75h7.5m-7.5 3.75h4.5" />
            </svg>
          </div>
        </div>

        <div class="mt-6 inline-flex items-center gap-3 text-sm font-semibold" :class="ctaClass(app.key)">
          <span>{{ app.open_mode === 'internal' ? 'Buka workspace' : 'Lanjut dengan akses portal' }}</span>
          <svg class="h-4 w-4 transition group-hover:translate-x-1" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M3.5 10a.75.75 0 0 1 .75-.75h9.69l-3.22-3.22a.75.75 0 1 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 0 1-1.06-1.06l3.22-3.22H4.25A.75.75 0 0 1 3.5 10Z" clip-rule="evenodd" />
          </svg>
        </div>
      </button>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const apps = computed(() => authStore.portalApps);

const homeAppName = computed(() => (
  apps.value.find((app) => app.key === authStore.portal.homeApp)?.name || 'Belum diatur'
));

const portalNotice = computed(() => {
  if (typeof route.query.access_denied === 'string' && route.query.access_denied !== '') {
    return {
      tone: 'danger',
      message: `Akun Anda belum punya akses ke aplikasi "${route.query.access_denied}". Hubungi admin untuk mengaktifkannya.`,
    };
  }

  if (typeof route.query.launch_unavailable === 'string' && route.query.launch_unavailable !== '') {
    return {
      tone: 'warning',
      message: `Aplikasi "${route.query.launch_unavailable}" belum dikonfigurasi penuh di portal.`,
    };
  }

  if (typeof route.query.inventory_error === 'string' && route.query.inventory_error !== '') {
    return {
      tone: 'danger',
      message: 'Login ke Inventaris IT belum berhasil diproses. Coba buka lagi aplikasinya dari portal, atau cek konfigurasi SSO jika error ini terus muncul.',
    };
  }

  return null;
});

const launch = async (app) => {
  if (!app?.launch_path) {
    return;
  }

  if (app.open_mode === 'internal') {
    await router.replace(app.launch_path);
    return;
  }

  window.location.replace(app.launch_path);
};

const cardClass = (key) => ({
  gesit: 'border-[#eadfc7] bg-[linear-gradient(135deg,#fffdf8_0%,#ffffff_100%)] hover:border-[#d8bc84]',
  inventaris: 'border-[#dce8f4] bg-[linear-gradient(135deg,#f5fbff_0%,#ffffff_100%)] hover:border-[#9ec5ea]',
}[key] || 'border-[#eadfc7] bg-white hover:border-[#d8bc84]');

const barClass = (key) => ({
  gesit: 'bg-[linear-gradient(90deg,#9b6b17_0%,#d7ae58_100%)]',
  inventaris: 'bg-[linear-gradient(90deg,#1f5f8b_0%,#68a9d8_100%)]',
}[key] || 'bg-[#9b6b17]');

const chipClass = (key) => ({
  gesit: 'bg-[#fbf5ea] text-[#8f6115]',
  inventaris: 'bg-[#eaf4ff] text-[#1f5f8b]',
}[key] || 'bg-[#f3f4f6] text-[#374151]');

const iconShellClass = (key) => ({
  gesit: 'border-[#efe5d7] text-[#8f6115]',
  inventaris: 'border-[#dbeafe] text-[#1f5f8b]',
}[key] || 'border-[#e5e7eb] text-[#374151]');

const ctaClass = (key) => ({
  gesit: 'text-[#8f6115]',
  inventaris: 'text-[#1f5f8b]',
}[key] || 'text-[#374151]');
</script>
