<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Aktivitas IT</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1f2937;
        }

        .heading {
            font-size: 18pt;
            font-weight: 700;
            color: #7c5710;
        }

        .subheading {
            font-size: 10pt;
            color: #6b7280;
        }

        .brand-table td {
            vertical-align: top;
        }

        .brand-logo-wrap {
            width: 112px;
            padding-right: 12px;
        }

        .brand-logo {
            display: block;
            width: 96px;
            height: auto;
        }

        .summary-table td {
            border: 1px solid #d7c29a;
            padding: 8px 10px;
        }

        .summary-label {
            background: #f9f3e7;
            font-weight: 700;
            color: #7c5710;
            width: 160px;
        }

        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.sheet th,
        table.sheet td {
            border: 1px solid #d9e1ea;
            padding: 8px 10px;
            vertical-align: top;
            word-wrap: break-word;
            white-space: normal;
        }

        table.sheet th {
            background: #f3e6ca;
            color: #7c5710;
            font-weight: 700;
            text-align: left;
        }

        .muted {
            color: #6b7280;
        }

        .small {
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <table class="brand-table" style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <tr>
            @if (!empty($logo_data_uri))
                <td class="brand-logo-wrap">
                    <img src="{{ $logo_data_uri }}" alt="PT Yulie Sekuritas Indonesia Tbk." class="brand-logo">
                </td>
            @endif
            <td>
                <div class="heading">Laporan Aktivitas IT</div>
                <div class="subheading">Dibuat pada {{ $generated_at->format('d/m/Y H:i:s') }}</div>
                <div class="subheading">{{ $filter_summary }}</div>
            </td>
        </tr>
    </table>

    <table class="summary-table" style="margin-bottom: 20px;">
        <tr>
            <td class="summary-label">Total Aktivitas</td>
            <td>{{ $stats['total'] }}</td>
            <td class="summary-label">Helpdesk</td>
            <td>{{ $stats['helpdesk'] }}</td>
        </tr>
        <tr>
            <td class="summary-label">Pengajuan</td>
            <td>{{ $stats['submission'] }}</td>
            <td class="summary-label">Internal IT</td>
            <td>{{ $stats['internal'] }}</td>
        </tr>
    </table>

    <table class="sheet">
        <thead>
            <tr>
                <th style="width: 48px;">No</th>
                <th style="width: 132px;">Waktu</th>
                <th style="width: 96px;">Modul</th>
                <th style="width: 180px;">Aktivitas</th>
                <th style="width: 100px;">Referensi</th>
                <th style="width: 160px;">Objek</th>
                <th style="width: 120px;">Aktor</th>
                <th style="width: 120px;">Role Aktor</th>
                <th style="width: 120px;">Requester / Pemohon</th>
                <th style="width: 120px;">PIC IT</th>
                <th style="width: 170px;">Pihak Terkait</th>
                <th style="width: 110px;">Status Aktivitas</th>
                <th style="width: 110px;">Status Terkini</th>
                <th style="width: 190px;">Ringkasan</th>
                <th style="width: 190px;">Catatan</th>
                <th style="width: 120px;">Konteks</th>
                <th style="width: 90px;">Visibilitas</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($activities as $index => $activity)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($activity['occurred_at'])->format('d/m/Y H:i:s') }}</td>
                    <td>{{ $activity['module_label'] }}</td>
                    <td>{{ $activity['activity_name'] }}</td>
                    <td>{{ $activity['reference_number'] }}</td>
                    <td>{{ $activity['item_title'] }}</td>
                    <td>{{ $activity['actor_name'] }}</td>
                    <td>{{ $activity['actor_role'] }}</td>
                    <td>{{ $activity['requester_name'] }}</td>
                    <td>{{ $activity['it_owner'] }}</td>
                    <td>{{ $activity['related_users'] }}</td>
                    <td>{{ $activity['status_at_event_label'] ?? '-' }}</td>
                    <td>{{ $activity['current_status_label'] ?? '-' }}</td>
                    <td>{{ $activity['summary'] }}</td>
                    <td>{{ $activity['notes'] ?? '-' }}</td>
                    <td>{{ $activity['context_label'] }}</td>
                    <td>{{ $activity['visibility_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="17" class="muted small">Belum ada aktivitas IT pada filter yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
