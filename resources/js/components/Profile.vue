<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">Profil Saya</h1>
      <p class="mt-2 text-gray-600">Ringkasan akun yang sedang aktif di sistem GESIT.</p>
    </div>

    <div class="card p-6">
      <div class="grid gap-6 md:grid-cols-2">
        <div>
          <p class="text-sm font-medium text-gray-500">Nama</p>
          <p class="mt-1 text-lg font-semibold text-gray-900">{{ authStore.user?.name || '-' }}</p>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-500">Email</p>
          <p class="mt-1 text-lg font-semibold text-gray-900">{{ authStore.user?.email || '-' }}</p>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-500">Department</p>
          <p class="mt-1 text-lg font-semibold text-gray-900">{{ authStore.user?.department || '-' }}</p>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-500">Employee ID</p>
          <p class="mt-1 text-lg font-semibold text-gray-900">{{ authStore.user?.employee_id || '-' }}</p>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-500">UserID S21Plus</p>
          <p class="mt-1 text-lg font-semibold text-gray-900">{{ authStore.user?.s21plus_user_id || '-' }}</p>
        </div>
      </div>
    </div>

    <div class="card p-6">
      <h2 class="text-lg font-semibold text-gray-900">Role</h2>
      <div class="mt-4 flex flex-wrap gap-2">
        <span
          v-for="role in authStore.roles"
          :key="role"
          class="rounded-full bg-primary-100 px-3 py-1 text-sm font-medium text-primary-700"
        >
          {{ role }}
        </span>
        <span v-if="authStore.roles.length === 0" class="text-sm text-gray-500">Belum ada role.</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useAuthStore } from '../stores/auth';

const authStore = useAuthStore();
</script>
