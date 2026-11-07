@extends('layouts.app')

@section('title', 'Import นักศึกษาจาก Excel | CSTU SPACE')

@push('styles')
<style>
    .page-card { border:none; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.08); background:white; }
    .upload-zone {
        border:2px dashed #c0c9e0; border-radius:12px; padding:2.5rem;
        text-align:center; background:#f8f9ff; transition:all .2s; cursor:pointer;
    }
    .upload-zone:hover, .upload-zone.drag-over { border-color:#667eea; background:#eef0ff; }
    .upload-icon { font-size:3rem; color:#667eea; margin-bottom:.75rem; }

    .tbl-sm td, .tbl-sm th { font-size:.79rem; padding:.38rem .5rem; vertical-align:middle; }
    .tbl-sm thead th {
        background:linear-gradient(135deg,#2E75B6,#1F4E79);
        color:white; border:none; font-weight:600; white-space:nowrap;
    }
    .row-new       { background:#f0fff4; }
.row-exists    { background:#fffbea; }
.row-update    { background:#eff6ff; }
.row-match     { background:#ecfdf5; }
.row-conflict  { background:#fef2f2; }
.row-invalid   { background:#fef2f2; }

.pill-new      { background:#dcfce7; color:#166534; }
.pill-exists   { background:#fef9c3; color:#854d0e; }
.pill-update   { background:#dbeafe; color:#1e40af; }
.pill-match    { background:#d1fae5; color:#065f46; }
.pill-conflict { background:#fee2e2; color:#991b1b; }
.pill-invalid  { background:#fee2e2; color:#991b1b; }

.status-pill {
    display:inline-block;
    font-size:.73rem;
    font-weight:600;
    padding:.18rem .55rem;
    border-radius:20px;
}
    .stats-bar { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
    .stats-chip { flex:1; min-width:120px; text-align:center; border-radius:10px; padding:.6rem 1rem; font-weight:600; }
    .chip-new   { background:#dcfce7; color:#166534; }
    .chip-exist { background:#fef9c3; color:#854d0e; }
    .chip-total { background:#e0e7ff; color:#3730a3; }
    .step-num {
        width:26px; height:26px; border-radius:50%; background:var(--gradient-primary); color:white;
        display:inline-flex; align-items:center; justify-content:center;
        font-size:.8rem; font-weight:700; flex-shrink:0;
    }
    .type-s { background:#ede9fe; color:#5b21b6; }
    .type-r { background:#dcfce7; color:#166534; }
    .type-pill { display:inline-block; font-size:.7rem; padding:.15rem .45rem; border-radius:10px; }
</style>
@endpush

@section('content')
<div class="container-fluid px-4 py-4">

    {{-- Header --}}
    <div class="mb-3">
        <a href="{{ route('users.index') }}" class="btn btn-link text-decoration-none ps-0 text-secondary">
            <i class="bi bi-chevron-left me-1"></i>กลับ User Management
        </a>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-1">
            <div>
                <h1 class="h3 fw-bold mb-0">
                    <i class="bi bi-people-fill me-2 text-success"></i>Import นักศึกษาจาก Excel
                </h1>
                <p class="text-muted small mb-0">
                    รองรับไฟล์ Excel ที่มี sheet ชื่อลงท้ายด้วย <code>--Students</code>
                    เช่น <code>CS303--Students</code>, <code>CS403--Students</code>
                </p>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3">
            <i class="bi bi-x-circle me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3">
            <i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">

        {{-- Left: Info + Upload --}}
        <div class="col-lg-4">

            <div class="page-card p-4 mb-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-info"></i>ข้อมูลที่จะนำเข้า</h6>
                <table class="table table-sm mb-0" style="font-size:.8rem;">
                    <tr><th class="text-muted fw-normal" style="width:45%;">Excel column</th><th>เก็บใน DB</th></tr>
                    <tr><td>col 0 — คำนำหน้า</td><td><code>prefix_std</code></td></tr>
                    <tr><td>col 1 — ชื่อ-นามสกุล</td><td><code>firstname_std</code> + <code>lastname_std</code></td></tr>
                    <tr><td>col 2 — รหัสนักศึกษา</td><td><code>username_std</code> + password</td></tr>
                    <tr><td>col 3 — Email</td><td><code>email_std</code></td></tr>
                    <tr><td>col 4 — เบอร์โทร</td><td><code>phone_std</code></td></tr>
                    <tr><td>col 5 — ประเภท</td><td><code>student_type</code> (s/r)</td></tr>
                    <tr><td>ชื่อ sheet</td><td><code>course_code</code></td></tr>
                </table>
                <div class="alert alert-info border-0 rounded-3 mt-3 mb-0 small py-2">
                    <i class="bi bi-key me-1"></i>
                    Password เริ่มต้น = <strong>รหัสนักศึกษา</strong> &nbsp;|&nbsp; ปีการศึกษา 2568 เทอม 2
                </div>
            </div>

            <div class="page-card p-4 mb-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-list-ol me-2 text-primary"></i>ขั้นตอน</h6>
                <div class="d-flex gap-2 mb-2 align-items-start">
                    <span class="step-num">1</span>
                    <div class="small">อัปโหลดไฟล์ <code>.xlsx</code> ที่มี sheet <code>--Students</code></div>
                </div>
                <div class="d-flex gap-2 mb-2 align-items-start">
                    <span class="step-num">2</span>
                    <div class="small">ตรวจสอบ preview — แถวสีเหลือง = มีในระบบแล้ว (จะข้าม)</div>
                </div>
                <div class="d-flex gap-2 align-items-start">
                    <span class="step-num">3</span>
                    <div class="small">กด <strong>"ยืนยัน Import"</strong> เพื่อสร้างบัญชีทั้งหมด</div>
                </div>
            </div>

            <div class="page-card p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-upload me-2 text-primary"></i>อัปโหลดไฟล์</h6>
                <form method="POST" action="{{ route('students.importExcelPreview') }}"
                      enctype="multipart/form-data" id="uploadForm">
                    @csrf
                    <!-- เลือกปีการศึกษา -->
<div class="mb-3">
    <label for="importYear" class="form-label fw-semibold">
        ปีการศึกษา (พ.ศ.)
    </label>

    <input
        type="number"
        name="import_year"
        id="importYear"
        class="form-control"
        min="2500"
        max="2700"
        value="{{ old('import_year') }}"
        placeholder="เช่น 2568"
        required
    >
</div>

<!-- เลือกภาคเรียน -->
<div class="mb-3">
    <label for="importSemester" class="form-label fw-semibold">
        ภาคเรียน
    </label>

    <select
        name="import_semester"
        id="importSemester"
        class="form-select"
        required
    >
        <option value="">-- เลือกภาคเรียน --</option>
        <option value="1" {{ old('import_semester') == '1' ? 'selected' : '' }}>
            ภาคเรียนที่ 1
        </option>
        <option value="2" {{ old('import_semester') == '2' ? 'selected' : '' }}>
            ภาคเรียนที่ 2
        </option>
    </select>
</div>
                    <div class="upload-zone" id="uploadZone"
                         onclick="document.getElementById('fileInput').click()">
                        <div class="upload-icon"><i class="bi bi-file-earmark-excel"></i></div>
                        <div class="fw-semibold mb-1">คลิกหรือลากไฟล์มาวางที่นี่</div>
                        <div class="text-muted small">.xlsx เท่านั้น ขนาดไม่เกิน 20 MB</div>
                        <div id="fileNameDisplay" class="mt-2 text-primary small fw-semibold"></div>
                    </div>
                    <input type="file" name="file" id="fileInput" class="d-none"
                           accept=".xlsx,.xls" onchange="onFileSelect(this)">
                    <button type="submit" class="btn btn-primary w-100 mt-3"
                            id="uploadBtn" disabled>
                        <i class="bi bi-search me-2"></i>Preview ข้อมูล
                    </button>
                </form>
            </div>
        </div>

        {{-- Right: Preview --}}
        <div class="col-lg-8">
            @isset($preview)

            <div class="page-card p-3">
                <div class="stats-bar">
                    <div class="stats-chip chip-total">รวม {{ count($preview) }} คน</div>
                    <div class="stats-chip chip-new">✓ ใหม่: {{ $new ?? 0 }} คน</div>
                    <div class="stats-chip chip-exist">⚠ มีอยู่แล้ว: {{ $exists ?? 0 }} คน</div>
                    <div class="stats-chip chip-new"> ✓ พร้อมต่อ CS403: {{ $cs403Ready ?? 0 }} คน
</div>
                </div>

               @if(($new ?? 0) > 0 || ($gradeUpdates ?? 0) > 0 || ($cs403Ready ?? 0) > 0)
                <form method="POST" action="{{ route('students.importExcelConfirm') }}" id="confirmForm">
                    @csrf
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
                        <div class="text-muted small">
                            <i class="bi bi-info-circle me-1"></i>
กด <strong>ยืนยัน</strong> เพื่อเพิ่มนักศึกษาใหม่ {{ $new ?? 0 }} คน
อัปเดตเกรด CS303 {{ $gradeUpdates ?? 0 }} คน
และเปลี่ยนผ่าน CS403 {{ $cs403Ready ?? 0 }} คน
                        </div>
                        <button type="submit" class="btn btn-success btn-sm fw-semibold px-4">
                            <i class="bi bi-person-plus-fill me-2"></i>ยืนยัน Import
({{ ($new ?? 0) + ($gradeUpdates ?? 0) + ($cs403Ready ?? 0) }} คน)
                        </button>
                    </div>
                </form>
                @else
                <div class="alert alert-warning border-0 rounded-3 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    นักศึกษาทุกคนในไฟล์มีในระบบแล้ว ไม่มีรายการใหม่
                </div>
                @endif

                <div class="table-responsive" style="max-height:580px; overflow-y:auto;">
                    <table class="table tbl-sm mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>สถานะ</th>
                                <th>คำนำหน้า</th>
                                <th style="min-width:120px;">ชื่อ</th>
                                <th style="min-width:120px;">นามสกุล</th>
                                <th>รหัสนศ.</th>
                                <th style="min-width:170px;">Email</th>
                                <th>เบอร์</th>
                                <th>วิชา</th>
                                <th>เกรด CS303</th>
                                <th>ประเภท</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview as $i => $item)
                       @php
                             $rowClass = match($item['status']) {
                             'new'            => 'row-new',
                             'exists'         => 'row-exists',
                             'grade_update'   => 'row-update',
                             'grade_match'    => 'row-match',
                             'grade_conflict' => 'row-conflict',
                             'invalid_grade'  => 'row-invalid',
                             'cs403_ready' => 'row-new',
                             'cs403_already_enrolled' => 'row-exists',
                            'cs403_incomplete_group'  => 'row-conflict',
                            'cs403_not_eligible'      => 'row-invalid',
                            'cs403_no_old_group' => 'row-invalid',
                             default          => 'row-exists',

                         };
                        @endphp

<tr class="{{ $rowClass }}">
                                <td class="text-center text-muted">{{ $i + 1 }}</td>
                                <td>

    @if($item['status'] === 'cs403_already_enrolled')

    <span class="status-pill pill-match">
        ✓ ลงทะเบียน CS403 แล้ว
    </span>

@elseif($item['status'] === 'cs403_ready')
    <span class="status-pill pill-new">
        ✓ พร้อมต่อ CS403
    </span>
    <div class="small text-muted mt-1">
        กลุ่ม CS303 เดิม: {{ $item['old_group_id'] ?? '-' }}
    </div>

@elseif($item['status'] === 'cs403_incomplete_group')

    <span class="status-pill pill-conflict">
        ⚠ สมาชิกไม่ครบ
    </span>

@elseif($item['status'] === 'cs403_not_eligible')

    <span class="status-pill pill-invalid">
        ✗ ยังไม่ผ่าน CS303
    </span>

@elseif($item['status'] === 'cs403_no_old_group')

    <span class="status-pill pill-invalid">
        ✗ ไม่พบกลุ่ม CS303
    </span>

@elseif($item['status'] === 'new')


    <span class="status-pill pill-new">
        ✓ ใหม่
    </span>

@elseif($item['status'] === 'grade_update')

    <span class="status-pill pill-update">
        ↑ เพิ่มเกรด
    </span>

@elseif($item['status'] === 'grade_match')

    <span class="status-pill pill-match">
        ✓ เกรดตรงกัน
    </span>

@elseif($item['status'] === 'grade_conflict')

    <span class="status-pill pill-conflict">
        ⚠ เกรดไม่ตรง
    </span>

    <div class="small text-danger mt-1">
        DB: {{ $item['db_grade'] ?? '-' }}
        / Excel: {{ $item['course_code'] === 'CS403'
    ? ($item['db_grade'] ?? '-')
    : ($item['grade_cs303'] ?? '-') }}
    </div>

@elseif($item['status'] === 'invalid_grade')

    <span class="status-pill pill-invalid">
        ✗ เกรดไม่ถูกต้อง
    </span>

@else

    <span class="status-pill pill-exists">
        มีอยู่แล้ว
    </span>

@endif
                                </td>
                                <td>{{ $item['prefix'] }}</td>
                                <td class="fw-semibold">{{ $item['firstname_std'] }}</td>
                                <td>{{ $item['lastname_std'] }}</td>
                                <td><code style="font-size:.75rem;">{{ $item['username_std'] }}</code></td>
                                <td style="font-size:.76rem;">{{ $item['email_std'] }}</td>
                                <td style="font-size:.76rem;">{{ $item['phone'] }}</td>
                                <td>
                                    <span class="badge bg-primary rounded-pill" style="font-size:.7rem;">
                                        {{ $item['course_code'] }}
                                    </span>
                                </td>
                                <td class="text-center">
                                        {{ $item['grade_cs303'] ?? '-' }}
                                    </td>
                                <td>
                                    <span class="type-pill {{ $item['student_type'] === 's' ? 'type-s' : 'type-r' }}">
                                        {{ $item['student_type'] === 's' ? 'พิเศษ' : 'ปกติ' }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @else
            <div class="page-card p-5 text-center text-muted">
                <i class="bi bi-people" style="font-size:4rem; opacity:.15;"></i>
                <p class="mt-3 mb-0 fw-semibold">ยังไม่มีข้อมูล Preview</p>
                <small>อัปโหลดไฟล์ Excel ที่มี sheet CS303--Students / CS403--Students</small>
            </div>
            @endisset
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
function onFileSelect(input) {
    const name = input.files[0]?.name ?? '';
    document.getElementById('fileNameDisplay').textContent = name ? '📎 ' + name : '';
    document.getElementById('uploadBtn').disabled = !name;
}
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag-over');
    const fi = document.getElementById('fileInput');
    fi.files = e.dataTransfer.files;
    onFileSelect(fi);
});
</script>
@endpush
