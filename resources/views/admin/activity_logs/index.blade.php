@extends('layouts.app')

@section('title', 'ประวัติการใช้งานระบบ | CSTU SPACE')

@section('content')
<div class="activity-log-container">
    <div class="page-header">
        <div>
            <h2><i class="bi bi-clock-history"></i> ประวัติการใช้งานระบบ</h2>
            <p>ติดตามการเปลี่ยนแปลงข้อมูลของผู้ใช้งานในระบบ</p>
        </div>
        <a href="{{ route('menu') }}" class="back-btn">
            <i class="bi bi-arrow-left"></i> กลับ
        </a>
    </div>

    <div class="filter-card">
        <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="row g-3">
            <div class="col-md-4">
                <label>ค้นหา</label>
                <input type="text" name="search" class="form-control"
                       value="{{ request('search') }}"
                       placeholder="ค้นหา username, action, description">
            </div>

            <div class="col-md-3">
                <label>Module</label>
                <select name="module" class="form-select">
                    <option value="">ทั้งหมด</option>
                    @foreach($modules as $module)
                        <option value="{{ $module }}" {{ request('module') == $module ? 'selected' : '' }}>
                            {{ $module }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label>Action</label>
                <select name="action" class="form-select">
                    <option value="">ทั้งหมด</option>
                    @foreach($actions as $action)
                        <option value="{{ $action }}" {{ request('action') == $action ? 'selected' : '' }}>
                            {{ $action }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button class="search-btn w-100" type="submit">
                    <i class="bi bi-search"></i> ค้นหา
                </button>
            </div>
        </form>
    </div>

    <div class="log-card">
        <div class="table-responsive">
            <table class="table activity-table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ผู้ใช้</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Target</th>
                        <th>รายละเอียด</th>
                        <th>IP Address</th>
                        <th>เวลา</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="fw-bold">#{{ $log->activity_log_id }}</td>

                            <td>
                                <div class="user-cell">
                                    <i class="bi bi-person-circle"></i>
                                    <span>{{ $log->username ?? '-' }}</span>
                                </div>
                            </td>

                            <td>
                                <span class="role-badge">{{ $log->role ?? '-' }}</span>
                            </td>

                            <td>
                                <span class="action-badge action-{{ strtolower($log->action) }}">
                                    {{ $log->action }}
                                </span>
                            </td>

                            <td>
                                <span class="module-badge">{{ $log->module }}</span>
                            </td>

                            <td>
                                <small>
                                    {{ $log->target_type ?? '-' }}
                                    @if($log->target_id)
                                        #{{ $log->target_id }}
                                    @endif
                                </small>
                            </td>

                            <td class="description-cell">
                                {{ $log->description ?? '-' }}

                                @if($log->new_values)
                                    <details class="mt-2">
                                        <summary>ดูข้อมูลที่เปลี่ยนแปลง</summary>
                                        <pre>{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </td>

                            <td>{{ $log->ip_address ?? '-' }}</td>

                            <td>
                                <span class="time-text">
                                    {{ optional($log->created_at)->format('d/m/Y H:i:s') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <i class="bi bi-inbox fs-1 text-muted"></i>
                                <p class="mt-2 text-muted">ไม่พบข้อมูล Activity Logs</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination-wrapper">
            {{ $logs->links() }}
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

<style>
    .activity-log-container {
        min-height: 100vh;
        padding: 2rem;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    }

    .page-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 20px;
        padding: 2rem;
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 8px 25px rgba(0,0,0,.1);
    }

    .page-header h2 {
        font-weight: 700;
        margin-bottom: .5rem;
    }

    .page-header p {
        margin: 0;
        opacity: .9;
    }

    .back-btn {
        color: #fff;
        text-decoration: none;
        background: rgba(255,255,255,.2);
        padding: .75rem 1.25rem;
        border-radius: 50px;
        border: 1px solid rgba(255,255,255,.35);
    }

    .back-btn:hover {
        color: #fff;
        background: rgba(255,255,255,.3);
    }

    .filter-card,
    .log-card {
        background: white;
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 8px 25px rgba(0,0,0,.1);
    }

    .filter-card label {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: .4rem;
    }

    .form-control,
    .form-select {
        border-radius: 14px;
        padding: .75rem 1rem;
    }

    .search-btn {
        border: none;
        border-radius: 50px;
        padding: .75rem 1.2rem;
        color: white;
        font-weight: 600;
        background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    }

    .activity-table {
        margin-bottom: 0;
    }

    .activity-table thead {
        background: #f4f6fb;
    }

    .activity-table th {
        color: #2c3e50;
        font-weight: 700;
        padding: 1rem;
        white-space: nowrap;
    }

    .activity-table td {
        padding: 1rem;
        vertical-align: top;
    }

    .user-cell {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-weight: 600;
    }

    .user-cell i {
        color: #667eea;
        font-size: 1.2rem;
    }

    .role-badge,
    .module-badge,
    .action-badge {
        display: inline-block;
        padding: .35rem .75rem;
        border-radius: 50px;
        font-size: .8rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .role-badge {
        background: #eef2ff;
        color: #667eea;
    }

    .module-badge {
        background: #e0f7fa;
        color: #00838f;
    }

    .action-badge {
        background: #f5f5f5;
        color: #444;
    }

    .action-create_group,
    .action-submit_proposal {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .action-leave_group,
    .action-reject_proposal,
    .action-decline_invitation {
        background: #ffebee;
        color: #c62828;
    }

    .action-approve_proposal,
    .action-accept_invitation {
        background: #e3f2fd;
        color: #1565c0;
    }

    .description-cell {
        min-width: 260px;
        max-width: 420px;
    }

    details summary {
        cursor: pointer;
        color: #667eea;
        font-weight: 600;
    }

    pre {
        background: #f8f9fa;
        padding: .75rem;
        border-radius: 12px;
        margin-top: .5rem;
        font-size: .8rem;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .time-text {
        white-space: nowrap;
        color: #555;
        font-weight: 500;
    }

    .pagination-wrapper {
        margin-top: 1.5rem;
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 1rem;
            align-items: flex-start;
        }

        .activity-log-container {
            padding: 1rem;
        }
    }
</style>
@endpush