<?php

namespace Database\Seeders;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSection;
use App\Models\KnowledgeSpace;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class KnowledgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminId = User::query()->where('email', 'admin@gesit.com')->value('id');
        $adminRoleId = Role::query()->where('name', 'Admin')->value('id');
        $itRoleId = Role::query()->where('name', 'IT Staff')->value('id');
        $financeRoleId = Role::query()->where('name', 'Accounting')->value('id');

        $spaces = [
            [
                'name' => 'IT',
                'description' => 'Panduan aplikasi internal, troubleshooting, dan onboarding tim teknologi.',
                'icon' => 'cpu',
                'sort_order' => 1,
                'sections' => [
                    [
                        'name' => 'Aplikasi Internal',
                        'description' => 'Panduan operasional aplikasi harian.',
                        'sort_order' => 1,
                        'entries' => [
                            [
                                'title' => 'Panduan Akses RTBO+ Harian',
                                'summary' => 'Langkah login, pengecekan data, dan validasi awal sebelum operasional dimulai.',
                                'body' => "1. Pastikan VPN kantor aktif.\n2. Buka aplikasi RTBO+ dengan akun operasional.\n3. Cek data sinkron pada menu monitoring.\n4. Jika ada mismatch, catat jam kejadian dan eskalasi ke PIC IT.\n5. Simpan screenshot bila ada error.",
                                'scope' => 'internal',
                                'type' => 'sop',
                                'source_kind' => 'article',
                                'owner_name' => 'IT Operations',
                                'reviewer_name' => 'Head of IT',
                                'version_label' => 'v1.2',
                                'effective_date' => '2026-04-01',
                                'reference_notes' => 'Halaman 2-4',
                                'tags' => ['rtbo+', 'operasional', 'aplikasi'],
                                'access_mode' => 'all',
                                'sort_order' => 1,
                            ],
                        ],
                    ],
                    [
                        'name' => 'Troubleshooting',
                        'description' => 'Checklist error umum dan langkah pemulihan cepat.',
                        'sort_order' => 2,
                        'entries' => [
                            [
                                'title' => 'Troubleshooting Login RTBO+',
                                'summary' => 'Urutan cek jika login gagal atau aplikasi hang saat startup.',
                                'body' => "1. Verifikasi koneksi jaringan dan VPN.\n2. Pastikan akun belum locked di panel admin.\n3. Clear cache lokal aplikasi.\n4. Restart service bila diperlukan.\n5. Eskalasi ke vendor bila error berulang lebih dari 2 kali.",
                                'scope' => 'internal',
                                'type' => 'troubleshooting',
                                'source_kind' => 'article',
                                'owner_name' => 'IT Support',
                                'reviewer_name' => 'Head of IT',
                                'version_label' => 'v1.0',
                                'effective_date' => '2026-03-26',
                                'reference_notes' => 'Quick fix checklist',
                                'tags' => ['rtbo+', 'error', 'login'],
                                'access_mode' => 'role_based',
                                'role_ids' => array_filter([$adminRoleId, $itRoleId]),
                                'sort_order' => 1,
                            ],
                            [
                                'title' => 'Onboarding IT 7 Hari Pertama',
                                'summary' => 'Daftar materi wajib untuk anggota baru tim IT.',
                                'body' => "Hari 1: akses akun dan struktur aplikasi.\nHari 2: monitoring ticket helpdesk.\nHari 3: review SOP deployment dan backup.\nHari 4: shadowing troubleshooting harian.\nHari 5-7: latihan incident response dasar dan dokumentasi.",
                                'scope' => 'internal',
                                'type' => 'onboarding',
                                'source_kind' => 'article',
                                'owner_name' => 'IT Manager',
                                'reviewer_name' => 'HRBP',
                                'version_label' => 'v1.1',
                                'effective_date' => '2026-03-20',
                                'reference_notes' => 'Learning path 7 hari',
                                'tags' => ['onboarding', 'it', 'learning path'],
                                'access_mode' => 'all',
                                'sort_order' => 2,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Finance',
                'description' => 'SOP keuangan, reimbursement, dan pengetahuan operasional finance.',
                'icon' => 'wallet',
                'sort_order' => 2,
                'sections' => [
                    [
                        'name' => 'Reimburse & Approval',
                        'description' => 'Dokumen reimburse dan approval flow internal.',
                        'sort_order' => 1,
                        'entries' => [
                            [
                                'title' => 'SOP Reimburse Makan Dinas',
                                'summary' => 'Alur pengajuan reimburse makan dinas dari submit form sampai pembayaran.',
                                'body' => "1. Isi form reimburse lengkap dengan bukti transaksi.\n2. Lampirkan nota dan alasan kegiatan.\n3. Approval pertama oleh atasan langsung.\n4. Finance melakukan verifikasi nominal dan akun biaya.\n5. Jika lolos verifikasi, pembayaran dijadwalkan pada batch mingguan.",
                                'scope' => 'internal',
                                'type' => 'sop',
                                'source_kind' => 'article',
                                'owner_name' => 'Finance Operations',
                                'reviewer_name' => 'Accounting Lead',
                                'version_label' => 'v2.1',
                                'effective_date' => '2026-04-05',
                                'reference_notes' => 'Lampiran nota wajib',
                                'tags' => ['reimburse', 'finance', 'approval'],
                                'access_mode' => 'all',
                                'sort_order' => 1,
                            ],
                            [
                                'title' => 'Approval Cuti Staff Finance',
                                'summary' => 'Urutan approval cuti untuk staff finance beserta fallback approver.',
                                'body' => "1. Staff submit melalui form cuti.\n2. Supervisor finance approve lebih dulu.\n3. Jika supervisor tidak aktif, approval dialihkan ke head finance.\n4. HR melakukan final check terhadap sisa cuti.\n5. Notifikasi dikirim ke requester setelah status final.",
                                'scope' => 'internal',
                                'type' => 'policy',
                                'source_kind' => 'article',
                                'owner_name' => 'HR & Finance',
                                'reviewer_name' => 'Head of Finance',
                                'version_label' => 'v1.3',
                                'effective_date' => '2026-03-18',
                                'reference_notes' => 'Flow approval versi terbaru',
                                'tags' => ['cuti', 'finance', 'approval'],
                                'access_mode' => 'all',
                                'sort_order' => 2,
                            ],
                        ],
                    ],
                    [
                        'name' => 'MKBD',
                        'description' => 'Knowledge terkait proses MKBD dan kontrol internal.',
                        'sort_order' => 2,
                        'entries' => [
                            [
                                'title' => 'Closing MKBD Harian',
                                'summary' => 'Urutan ringkas closing MKBD dan titik cek sebelum laporan dianggap lengkap.',
                                'body' => "1. Tarik data posisi kas, piutang, dan kewajiban.\n2. Rekonsiliasi mutasi dengan sistem inti.\n3. Hitung rasio MKBD sesuai template aktif.\n4. Validasi angka abnormal dibanding hari sebelumnya.\n5. Arsipkan working paper dan kirim ringkasan ke supervisor.",
                                'scope' => 'internal',
                                'type' => 'jobdesk',
                                'source_kind' => 'article',
                                'owner_name' => 'Accounting',
                                'reviewer_name' => 'Operational Director',
                                'version_label' => 'v1.5',
                                'effective_date' => '2026-04-02',
                                'reference_notes' => 'Working paper MKBD',
                                'tags' => ['mkbd', 'finance', 'daily closing'],
                                'access_mode' => 'role_based',
                                'role_ids' => array_filter([$adminRoleId, $financeRoleId]),
                                'sort_order' => 1,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Domain Sekuritas',
                'description' => 'Knowledge umum seputar sekuritas, pasar modal, dan operasional broker.',
                'icon' => 'chart',
                'sort_order' => 3,
                'sections' => [
                    [
                        'name' => 'Dasar Pasar Modal',
                        'description' => 'Istilah dan konsep dasar untuk operasional sekuritas.',
                        'sort_order' => 1,
                        'entries' => [
                            [
                                'title' => 'Istilah Dasar Pasar Modal',
                                'summary' => 'Penjelasan singkat saham, obligasi, reksa dana, dan peran broker.',
                                'body' => "Saham adalah bukti kepemilikan perusahaan. Obligasi adalah surat utang. Reksa dana adalah wadah investasi yang dikelola manajer investasi. Broker atau perusahaan sekuritas menjadi perantara transaksi nasabah di pasar modal.",
                                'scope' => 'securities_domain',
                                'type' => 'faq',
                                'source_kind' => 'article',
                                'owner_name' => 'Knowledge Team',
                                'reviewer_name' => 'Operational Director',
                                'version_label' => 'v1.0',
                                'effective_date' => '2026-04-10',
                                'reference_notes' => 'Domain knowledge dasar',
                                'tags' => ['sekuritas', 'saham', 'obligasi', 'broker'],
                                'access_mode' => 'all',
                                'sort_order' => 1,
                            ],
                            [
                                'title' => 'Settlement dan Kliring pada Broker',
                                'summary' => 'Ringkasan fungsi kliring dan settlement dalam alur operasional broker.',
                                'body' => "Kliring memastikan kewajiban transaksi tiap pihak dihitung dengan benar. Settlement adalah proses penyelesaian akhir berupa perpindahan dana dan efek. Di broker, tim operasional memastikan data transaksi, dana, dan efek sinkron agar tidak terjadi gagal serah atau gagal bayar.",
                                'scope' => 'securities_domain',
                                'type' => 'faq',
                                'source_kind' => 'article',
                                'owner_name' => 'Operations',
                                'reviewer_name' => 'Operational Director',
                                'version_label' => 'v1.0',
                                'effective_date' => '2026-04-11',
                                'reference_notes' => 'Operational glossary',
                                'tags' => ['settlement', 'kliring', 'broker'],
                                'access_mode' => 'all',
                                'sort_order' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($spaces as $spaceData) {
            $space = KnowledgeSpace::query()->updateOrCreate(
                ['name' => $spaceData['name']],
                [
                    'description' => $spaceData['description'],
                    'icon' => $spaceData['icon'],
                    'sort_order' => $spaceData['sort_order'],
                    'is_active' => true,
                    'created_by' => $adminId,
                    'updated_by' => $adminId,
                ],
            );

            $space->ensureDefaultSection();

            foreach ($spaceData['sections'] as $sectionData) {
                $section = KnowledgeSection::query()->updateOrCreate(
                    [
                        'knowledge_space_id' => $space->id,
                        'name' => $sectionData['name'],
                    ],
                    [
                        'description' => $sectionData['description'],
                        'sort_order' => $sectionData['sort_order'],
                        'is_active' => true,
                    ],
                );

                foreach ($sectionData['entries'] as $entryData) {
                    $roleIds = $entryData['role_ids'] ?? [];
                    unset($entryData['role_ids']);

                    $entry = KnowledgeEntry::query()->updateOrCreate(
                        [
                            'knowledge_section_id' => $section->id,
                            'title' => $entryData['title'],
                        ],
                        [
                            ...$entryData,
                            'created_by' => $adminId,
                            'updated_by' => $adminId,
                            'is_active' => true,
                        ],
                    );

                    $entry->roles()->sync($entryData['access_mode'] === 'role_based' ? $roleIds : []);
                }
            }
        }
    }
}
