<template>
  <div class="space-y-6 pb-8">
    <section class="rounded-[30px] border border-[#e8dcc9] bg-white p-6 shadow-[0_20px_48px_rgba(41,28,9,0.07)] sm:p-8">
      <div class="max-w-3xl">
        <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-[#a57e3a]">Pengaturan Admin</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#111827] sm:text-[2.1rem]">
          Setting aplikasi
        </h1>
        <p class="mt-3 max-w-2xl text-sm leading-7 text-[#6b7280] sm:text-[0.96rem]">
          Pusat pengelolaan akses dan struktur aplikasi internal.
        </p>
      </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
      <router-link
        v-for="item in settingMenus"
        :key="item.id"
        :to="item.to"
        class="group rounded-[28px] border border-[#e8dcc9] bg-white p-6 shadow-[0_16px_36px_rgba(41,28,9,0.06)] transition hover:-translate-y-0.5 hover:border-[#d8bc84] hover:bg-[#fffdf9]"
      >
        <div class="flex items-start justify-between gap-4">
          <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[22px] bg-[#fbf5ea] text-[#9b6b17]">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                v-if="item.icon === 'users'"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M17 20h5V9H2v11h5m10 0v-2a4 4 0 00-4-4H11a4 4 0 00-4 4v2m10 0H7m8-11a3 3 0 11-6 0 3 3 0 016 0Z"
              />
              <path
                v-else
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M10.325 4.317a1.724 1.724 0 013.35 0 1.724 1.724 0 002.573 1.066 1.724 1.724 0 012.875 1.925 1.724 1.724 0 001.066 2.573 1.724 1.724 0 010 3.35 1.724 1.724 0 00-1.066 2.573 1.724 1.724 0 01-2.875 1.925 1.724 1.724 0 00-2.573 1.066 1.724 1.724 0 01-3.35 0 1.724 1.724 0 00-2.573-1.066 1.724 1.724 0 01-2.875-1.925 1.724 1.724 0 00-1.066-2.573 1.724 1.724 0 010-3.35 1.724 1.724 0 001.066-2.573 1.724 1.724 0 012.875-1.925 1.724 1.724 0 002.573-1.066ZM12 15.25a3.25 3.25 0 100-6.5 3.25 3.25 0 000 6.5Z"
              />
            </svg>
          </div>

          <span class="text-sm font-medium text-[#8b6316] transition group-hover:text-[#704d10]">
            Buka
          </span>
        </div>

        <h2 class="mt-6 text-xl font-semibold text-[#111827]">{{ item.title }}</h2>
        <p class="mt-3 text-sm leading-7 text-[#6b7280]">{{ item.description }}</p>

        <div class="mt-5 flex flex-wrap gap-2">
          <span
            v-for="tag in item.tags"
            :key="tag"
            class="rounded-full bg-[#fbf5ea] px-3 py-1 text-xs font-semibold text-[#8f6115]"
          >
            {{ tag }}
          </span>
        </div>
      </router-link>
    </section>
  </div>
</template>

<script setup>
const settingMenus = [
  {
    id: 1,
    title: 'Manage User',
    description: 'Tambah user baru, ubah data akun, atur multi-role, dan aktifkan atau arsipkan user internal.',
    to: '/users',
    icon: 'users',
    tags: ['User', 'Divisi', 'Status'],
  },
  {
    id: 2,
    title: 'Manage Role',
    description: 'Buat role custom, sesuaikan permission, edit role yang ada, lalu nonaktifkan role bila sudah tidak dipakai.',
    to: '/roles',
    icon: 'settings',
    tags: ['Role', 'Permission', 'Akses'],
  },
];
</script>
