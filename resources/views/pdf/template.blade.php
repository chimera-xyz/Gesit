<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>GESIT - Form Submission</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f8f9fa;
        }

        .container {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            padding: 40px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header {
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .company-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .company-name {
            font-size: 28px;
            font-weight: 700;
            color: #3b82f6;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .company-address {
            font-size: 14px;
            color: #64748b;
        }

        .document-title {
            font-size: 18px;
            font-weight: 600;
            color: #1d4ed8;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .document-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 14px;
            color: #374151;
            font-weight: 400;
        }

        .section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-data {
            background: #f9fafb;
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .field-group {
            display: flex;
            margin-bottom: 15px;
        }

        .field-label {
            font-size: 12px;
            font-weight: 500;
            color: #6b7280;
            width: 200px;
            text-transform: uppercase;
        }

        .field-value {
            font-size: 14px;
            color: #374151;
            font-weight: 400;
            flex: 1;
        }

        .status-section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #3b82f6;
            border-radius: 8px;
            background: #eff6ff;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-submitted {
            background: #dbeafe;
            color: #ffffff;
        }

        .status-pending-it {
            background: #fbbf24;
            color: #92400e;
        }

        .status-pending-director {
            background: #fbbf24;
            color: #92400e;
        }

        .status-pending-accounting {
            background: #fbbf24;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #ffffff;
        }

        .status-completed {
            background: #10b981;
            color: #ffffff;
        }

        .status-rejected {
            background: #fee2e2;
            color: #ffffff;
        }

        .approval-timeline {
            margin-top: 30px;
        }

        .approval-step {
            display: flex;
            margin-bottom: 20px;
            align-items: flex-start;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }

        .step-content {
            flex: 1;
            margin-left: 20px;
        }

        .step-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .step-role {
            font-size: 12px;
            font-weight: 500;
            color: #6b7280;
            text-transform: uppercase;
        }

        .step-date {
            font-size: 11px;
            color: #9ca3af;
        }

        .step-notes {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 15px;
            font-style: italic;
        }

        .signature-section {
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f0fdf4;
        }

        .signature-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .signature-image {
            max-width: 200px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background: white;
        }

        .signature-info {
            flex: 1;
        }

        .signature-name {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }

        .signature-date {
            font-size: 11px;
            color: #9ca3af;
        }

        .verification-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            background: #10b981;
            color: white;
            margin-left: 10px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
        }

        .watermark {
            position: fixed;
            bottom: 10px;
            right: 10px;
            font-size: 10px;
            color: rgba(0, 0, 0, 0.1);
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="watermark">Yuli Sekuritas Indonesia - GESIT System</div>

    <div class="container">
        <!-- Company Header -->
        <div class="company-header">
            <div>
                <div class="company-name">Yuli Sekuritas Indonesia</div>
                <div class="company-address">Jalan Jenderal Sudirman No. 27, Jakarta Selatan 12920, Indonesia</div>
                <div style="color: #3b82f6; font-size: 14px; font-weight: 600;">GESIT - General Enterprise Service & IT</div>
            </div>
            <div style="text-align: right; font-size: 12px; color: #64748b;">
                Tanggal: {{ $submission->created_at->format('d F Y') }}
            </div>
        </div>

        <!-- Document Title -->
        <div class="document-title">
            Form Requisition - {{ $submission->form->name ?? 'Unknown Form' }}
        </div>

        <!-- Document Information -->
        <div class="document-info">
            <div class="info-item">
                <div class="info-label">Nama Karyawan</div>
                <div class="info-value">{{ $submission->user->name ?? 'Unknown' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Departemen</div>
                <div class="info-value">{{ $submission->user->department ?? '-' }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Form ID</div>
                <div class="info-value">#{{ $submission->id }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="status-badge status-{{ strtolower(str_replace(' ', '-', $submission->current_status)) }}">
                    {{ ucfirst(str_replace('_', ' ', $submission->current_status)) }}
                </div>
            </div>
        </div>

        <!-- Form Data Section -->
        <div class="section">
            <div class="section-title">Form Data</div>
            <div class="form-data">
                @foreach($submission->form_data as $key => $value)
                    @if(is_array($value))
                        @if($key === 'item_name' || $key === 'employee_name' || $key === 'department')
                            <div class="field-group">
                                <div class="field-label">{{ formatFieldLabel($key) }}</div>
                                <div class="field-value">{{ $value }}</div>
                            </div>
                        @elseif($key === 'item_type')
                            <div class="field-group">
                                <div class="field-label">Tipe Barang</div>
                                <div class="field-value">{{ $value }}</div>
                            </div>
                        @elseif($key === 'urgency')
                            <div class="field-group">
                                <div class="field-label">Status Urgensi</div>
                                <div class="field-value">{{ $value }}</div>
                            </div>
                        @elseif($key === 'estimated_cost')
                            <div class="field-group">
                                <div class="field-label">Estimasi Biaya</div>
                                <div class="field-value">Rp {{ number_format($value, 0, ',', '.') }}</div>
                            </div>
                        @else
                            <div class="field-group">
                                <div class="field-label">{{ formatFieldLabel($key) }}</div>
                                <div class="field-value">
                                    @foreach($value as $subKey => $subValue)
                                        {{ $subValue }}
                                        @if(!$loop->last), {{ ', ' }}
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="field-group">
                            <div class="field-label">{{ formatFieldLabel($key) }}</div>
                            <div class="field-value">{{ $value }}</div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        <!-- Approval Timeline -->
        <div class="approval-timeline">
            <div class="section-title">Approval History</div>
            @foreach($submission->approvalSteps->sortBy('step_number') as $step)
                <div class="approval-step">
                    <div class="step-number">{{ $step->step_number }}</div>
                    <div class="step-content">
                        <div class="step-header">
                            <div>
                                <div class="step-role">{{ $step->approver_role }}</div>
                                <div class="step-date">{{ $step->approved_at ? $step->approved_at->format('d M Y H:i') : 'Pending' }}</div>
                            </div>
                            <div class="status-badge status-{{ strtolower($step->status) }}">
                                {{ ucfirst($step->status) }}
                            </div>
                        </div>
                        @if($step->notes)
                            <div class="step-notes">{{ $step->notes }}</div>
                        @endif
                    </div>
                @endforeach
        </div>

        <!-- Signatures Section -->
        @if($submission->approvalSteps->pluck('signature')->filter()->isNotEmpty())
            <div class="signature-section">
                <div class="section-title">Digital Signatures</div>
                @foreach($submission->approvalSteps->sortBy('step_number') as $step)
                    @if($step->signature)
                        <div class="signature-container">
                            <img src="{{ asset($step->signature->signature_image) }}" alt="Signature" class="signature-image">
                            <div class="signature-info">
                                <div class="signature-name">{{ $step->signature->user->name }}</div>
                                <div class="signature-date">{{ $step->signature->signed_at->format('d M Y H:i') }}</div>
                                @if($step->signature->verified)
                                    <div class="verification-badge">VERIFIED</div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        <!-- Rejection Reason -->
        @if($submission->current_status === 'rejected' && $submission->rejection_reason)
            <div class="section" style="background: #fee2e2;">
                <div class="section-title" style="color: #ffffff;">Rejection Reason</div>
                <p style="color: #ffffff; font-size: 14px;">{{ $submission->rejection_reason }}</p>
            </div>
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>Generated by GESIT System - {{ date('Y-m-d H:i:s') }} | Valid: {{ $submission->current_status !== 'rejected' ? 'Yes' : 'No' }}</p>
        <p>Yuli Sekuritas Indonesia - Internal Use Only</p>
    </div>
</body>
</html>

@php
    function formatFieldLabel($key)
    {
        $labels = [
            'employee_name' => 'Nama Karyawan',
            'department' => 'Departemen',
            'item_name' => 'Nama Barang',
            'item_type' => 'Tipe Barang',
            'specifications' => 'Spesifikasi yang Diinginkan',
            'reason' => 'Alasan Ingin Membeli',
            'urgency' => 'Status Urgensi',
            'estimated_cost' => 'Estimasi Biaya (Rp)',
            'quantity' => 'Jumlah',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
