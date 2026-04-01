<template>
  <div class="flex min-h-screen items-center justify-center bg-white px-4 py-10 sm:px-6 lg:px-8">
    <div class="w-full max-w-md">
      <section class="rounded-[28px] border border-[#e8dcc9] bg-white px-6 py-7 shadow-[0_18px_40px_rgba(41,28,9,0.08)] sm:px-8 sm:py-8">
        <div class="mb-7">
          <img :src="companyLogo" alt="PT. Yulie Sekuritas Indonesia Tbk." class="h-12 w-auto sm:h-[3.4rem]">
          <p class="mt-6 text-[11px] font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">Internal Access</p>
          <h1 class="mt-3 text-[1.82rem] font-semibold leading-tight text-[#111827]">
            Masuk ke SiGesit
          </h1>
          <p class="mt-2 text-sm leading-6 text-[#6b7280]">
            Gunakan akun internal perusahaan untuk mengakses SiGesit.
          </p>
        </div>

        <div v-if="error" class="mb-5 rounded-2xl border border-[#f1d4d4] bg-white px-4 py-3">
          <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-[#c24141]" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1.25 1.25 0 100-2.5A1.25 1.25 0 0010 15z" clip-rule="evenodd" />
            </svg>
            <div>
              <p class="text-sm font-semibold text-[#9f2f2f]">Gagal login</p>
              <p class="mt-1 text-sm text-[#b14040]">{{ error }}</p>
            </div>
          </div>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-5">
          <div>
            <label for="email" class="mb-2 block text-sm font-medium text-[#374151]">Email</label>
            <input
              id="email"
              v-model="formData.email"
              type="email"
              required
              class="block h-12 w-full rounded-2xl border border-[#d7dce3] bg-white px-4 text-sm text-[#1f2937] outline-none transition focus:border-[#c99f51] focus:ring-2 focus:ring-[#ead39a]"
              placeholder="nama@perusahaan.com"
            >
          </div>

          <div>
            <label for="password" class="mb-2 block text-sm font-medium text-[#374151]">Password</label>
            <input
              id="password"
              v-model="formData.password"
              type="password"
              required
              class="block h-12 w-full rounded-2xl border border-[#d7dce3] bg-white px-4 text-sm text-[#1f2937] outline-none transition focus:border-[#c99f51] focus:ring-2 focus:ring-[#ead39a]"
              placeholder="Masukkan password"
            >
          </div>

          <label class="flex items-center gap-3 text-sm text-[#4b5563]">
            <input
              id="remember-me"
              v-model="formData.remember"
              type="checkbox"
              class="h-4 w-4 rounded border-[#ccb58e] text-[#a86f10] focus:ring-[#ead39a]"
            >
            <span>Ingat sesi login ini</span>
          </label>

          <button
            type="submit"
            :disabled="loading"
            class="flex h-12 w-full items-center justify-center rounded-2xl bg-[#9b6b17] px-4 text-sm font-semibold text-white transition hover:bg-[#865b12] disabled:cursor-not-allowed disabled:opacity-70"
          >
            <svg v-if="loading" class="mr-3 h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ loading ? 'Memproses...' : 'Masuk' }}
          </button>
        </form>

        <p class="mt-6 text-center text-xs leading-5 text-[#8a8f98]">
          Jika membutuhkan akses, hubungi administrator internal.
        </p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import axios from 'axios';
import companyLogo from '../../assets/company-login-lockup.svg';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const formData = ref({
  email: '',
  password: '',
  remember: false,
});

const loading = ref(false);
const error = ref(null);

const handleLogin = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await axios.post('/api/auth/login', {
      email: formData.value.email,
      password: formData.value.password,
    });

    authStore.hydrate(response.data);
    router.replace(route.query.redirect || '/');
  } catch (err) {
    console.error('Login error:', err);
    error.value = err.response?.data?.message || 'Email atau password salah. Silakan coba lagi.';
  } finally {
    loading.value = false;
  }
};
</script>
