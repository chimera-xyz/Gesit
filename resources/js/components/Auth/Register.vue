<template>
  <div class="relative min-h-screen overflow-hidden brand-canvas">
    <div class="pointer-events-none absolute inset-0">
      <div class="absolute -right-12 top-16 h-64 w-64 rounded-full bg-primary-100/70 blur-3xl"></div>
      <div class="absolute left-0 top-1/4 h-72 w-72 rounded-full bg-secondary-100/80 blur-3xl"></div>
      <div class="absolute bottom-0 right-1/3 h-56 w-56 rounded-full bg-primary-200/45 blur-3xl"></div>
    </div>

    <div class="relative mx-auto flex min-h-screen max-w-6xl items-center px-4 py-10 sm:px-6 lg:px-8">
      <div class="grid w-full gap-6 lg:grid-cols-2">
        <section class="brand-surface hidden h-full flex-col justify-between p-10 lg:flex">
          <div>
            <div class="inline-flex rounded-[1.75rem] bg-white p-4 ring-1 ring-primary-100">
              <img :src="companyLogo" alt="PT Yulie Sekuritas Indonesia Tbk." class="h-16 w-auto">
            </div>

            <p class="mt-8 text-xs font-semibold uppercase tracking-[0.34em] text-primary-700">Onboarding GESIT</p>
            <h1 class="mt-4 text-4xl font-semibold leading-tight text-secondary-900">
              Buat akun untuk mulai mengajukan kebutuhan dan mengikuti alur approval internal.
            </h1>
            <p class="mt-5 text-base leading-7 text-secondary-600">
              Setelah akun aktif, Anda bisa membuat permintaan, memantau status, dan berkoordinasi lewat notifikasi terpusat.
            </p>
          </div>

          <div class="space-y-4">
            <div class="rounded-3xl bg-primary-50/75 p-5 ring-1 ring-primary-100">
              <p class="text-sm font-semibold text-secondary-900">Akses cepat ke seluruh proses</p>
              <p class="mt-2 text-sm leading-6 text-secondary-600">Mulai dari pengajuan barang, approval direktur, hingga validasi akuntansi dalam satu platform.</p>
            </div>
            <div class="rounded-3xl bg-white/80 p-5 ring-1 ring-primary-100">
              <p class="text-sm font-semibold text-secondary-900">Tampilan lebih selaras dengan brand</p>
              <p class="mt-2 text-sm leading-6 text-secondary-600">Identitas warna dan logo kini mengikuti visual resmi perusahaan, bukan placeholder biru bawaan.</p>
            </div>
          </div>

          <p class="text-sm text-secondary-500">© 2026 PT Yulie Sekuritas Indonesia Tbk.</p>
        </section>

        <section class="brand-surface p-6 sm:p-8 lg:p-10">
          <div class="mb-8 lg:hidden">
            <img :src="companyLogo" alt="PT Yulie Sekuritas Indonesia Tbk." class="h-12 w-auto">
            <p class="mt-4 text-xs font-semibold uppercase tracking-[0.3em] text-primary-700">Onboarding GESIT</p>
          </div>

          <div>
            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-primary-700">Registrasi Internal</p>
            <h2 class="mt-3 text-3xl font-semibold text-secondary-900">Buat akun GESIT</h2>
            <p class="mt-3 text-sm leading-6 text-secondary-600">
              Lengkapi data dasar Anda untuk mulai menggunakan sistem pengajuan internal perusahaan.
            </p>
          </div>

          <div v-if="error" class="mt-6 rounded-3xl border border-danger-100 bg-danger-50 px-4 py-4">
            <div class="flex items-start gap-3">
              <svg class="mt-0.5 h-5 w-5 shrink-0 text-danger-600" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1.25 1.25 0 100-2.5A1.25 1.25 0 0010 15z" clip-rule="evenodd" />
              </svg>
              <div>
                <h3 class="text-sm font-semibold text-danger-700">Pendaftaran gagal</h3>
                <p class="mt-1 text-sm text-danger-700">{{ error }}</p>
              </div>
            </div>
          </div>

          <div v-if="success" class="mt-6 rounded-3xl border border-success-100 bg-success-50 px-4 py-4">
            <div class="flex items-start gap-3">
              <svg class="mt-0.5 h-5 w-5 shrink-0 text-success-600" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
              <div>
                <h3 class="text-sm font-semibold text-success-700">Pendaftaran berhasil</h3>
                <p class="mt-1 text-sm text-success-700">Akun Anda berhasil dibuat. Anda akan diarahkan ke halaman login.</p>
              </div>
            </div>
          </div>

          <form @submit.prevent="handleRegister" class="mt-8 space-y-5">
            <div>
              <label for="name" class="mb-2 block text-sm font-medium text-secondary-700">Nama lengkap</label>
              <input
                id="name"
                v-model="formData.name"
                type="text"
                required
                class="input-field placeholder:text-secondary-400"
                placeholder="Masukkan nama lengkap"
              >
            </div>

            <div>
              <label for="email" class="mb-2 block text-sm font-medium text-secondary-700">Email</label>
              <input
                id="email"
                v-model="formData.email"
                type="email"
                required
                class="input-field placeholder:text-secondary-400"
                placeholder="nama@perusahaan.com"
              >
            </div>

            <div>
              <label for="password" class="mb-2 block text-sm font-medium text-secondary-700">Password</label>
              <input
                id="password"
                v-model="formData.password"
                type="password"
                required
                class="input-field placeholder:text-secondary-400"
                placeholder="Masukkan password"
              >
            </div>

            <div>
              <label for="password_confirmation" class="mb-2 block text-sm font-medium text-secondary-700">Konfirmasi password</label>
              <input
                id="password_confirmation"
                v-model="formData.password_confirmation"
                type="password"
                required
                class="input-field placeholder:text-secondary-400"
                placeholder="Ulangi password"
              >
            </div>

            <button
              type="submit"
              :disabled="loading"
              class="btn-primary w-full justify-center"
            >
              <svg v-if="loading" class="mr-3 h-5 w-5 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ loading ? 'Menyimpan...' : 'Buat Akun' }}
            </button>
          </form>

          <div class="mt-8 border-t border-primary-100 pt-6 text-sm text-secondary-600">
            Sudah punya akun?
            <router-link to="/login" class="brand-link">
              Login sekarang
            </router-link>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import companyLogo from '../../assets/company-logo.svg';

const router = useRouter();

const formData = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
});

const loading = ref(false);
const error = ref(null);
const success = ref(false);

const handleRegister = async () => {
  loading.value = true;
  error.value = null;
  success.value = false;

  try {
    await axios.post('/api/auth/register', {
      name: formData.value.name,
      email: formData.value.email,
      password: formData.value.password,
      password_confirmation: formData.value.password_confirmation,
    });

    success.value = true;

    setTimeout(() => {
      router.push('/login');
    }, 2000);
  } catch (err) {
    console.error('Registration error:', err);
    error.value = err.response?.data?.message || 'Pendaftaran gagal. Silakan coba lagi.';
  } finally {
    loading.value = false;
  }
};
</script>
