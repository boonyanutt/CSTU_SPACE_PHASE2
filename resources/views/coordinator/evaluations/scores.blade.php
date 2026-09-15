@extends('layouts.app')

@section('title', 'คะแนนประเมิน – ' . ($project->project_code ?? '') . ' | CSTU SPACE')

@push('styles')
<style>
.hero-card { border:none; border-radius:16px; background:#fff; border-top:4px solid #4e73df; box-shadow:0 4px 20px rgba(78,115,223,.1); }
.section-card { border:none; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.07); overflow:hidden; }
.evaluator-row { display:flex; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid #f4f5f7; }
.evaluator-row:last-child { border-bottom:none; }
.person-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; color:#fff; flex-shrink:0; }

/* Score table - clean version */
.score-table { font-size:.85rem; border-collapse:separate; border-spacing:0; width:100%; }
.score-table th {
    padding:.65rem .9rem; font-size:.75rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.04em;
    color:#6c757d; background:#f8f9fa;
    border-bottom:2px solid #e9ecef;
    white-space:nowrap; text-align:center;
}
.score-table th.th-criteria { text-align:left; color:#495057; }
.score-table td { padding:.6rem .9rem; vertical-align:middle; border-bottom:1px solid #f0f1f5; }
.score-table .score-cell { text-align:center; font-weight:600; }
.score-table .max-cell { text-align:center; color:#adb5bd; font-size:.78rem; }
.score-table .avg-cell { text-align:center; font-weight:700; background:#f5f0ff; border-left:2px solid #c4b5fd; }

/* Part headers */
.row-part-header td { background:#eff3ff; font-weight:700; color:#3b4fd0; border-top:2px solid #c7d2fe; font-size:.83rem; }
.row-sub td:first-child { padding-left:2rem; color:#555; font-size:.83rem; }
.row-subtotal td { background:#e8efff; font-weight:600; border-top:1px solid #a5b4fc; border-bottom:2px solid #818cf8; }
.row-grand td { background:linear-gradient(135deg,#e8f0ff,#dce9ff); font-weight:700; font-size:.9rem; border-top:2px solid #818cf8; }

/* Summary cards at bottom */
.score-sum-box { text-align:center; padding:.75rem .5rem; flex:1; }
.score-sum-box .lbl { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; margin-bottom:4px; }
.score-sum-box .val { font-size:1.15rem; font-weight:800; line-height:1; }
.score-sum-box .mx  { font-size:.68rem; color:#adb5bd; margin-top:2px; }
.score-sum-box.final { background:linear-gradient(135deg,#f5f0ff,#ede9fe); border-radius:0 0 12px 0; }
.score-sum-box.final .lbl { color:#7c3aed; }
.score-sum-box.final .val { font-size:1.7rem; }

.student-header {
    background:linear-gradient(135deg,#4e73df,#224abe);
    color:#fff; padding:.7rem 1.25rem;
    border-radius:12px 12px 0 0;
    font-weight:700; font-size:.9rem;
    display:flex; align-items:center; gap:.5rem;
}
.badge-pending { display:inline-flex; align-items:center; gap:3px; font-size:.68rem; font-weight:600; padding:2px 7px; border-radius:20px; background:#fef9c3; color:#92400e; border:1px solid #fde68a; }
</style>
@endpush

@section('content')
<div class="container">

    <div style="background:white;border-radius:var(--border-radius);padding:2rem;margin-bottom:2rem;box-shadow:var(--shadow-light);">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2 style="color:#2c3e50;font-weight:700;font-size:2rem;margin-bottom:.5rem;">
                    <i class="bi bi-clipboard-data me-2"></i>คะแนนประเมินโครงงาน
                </h2>
                <p class="mb-0 opacity-75">{{ $project->project_code }} — {{ $project->project_name ?? 'ยังไม่ระบุชื่อ' }}</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('coordinator.evaluations.index') }}" class="btn" style="background:#f8f9fa;color:#2c3e50;font-weight:600;display:inline-flex;align-items:center;gap:.5rem;">
                    <i class="bi bi-arrow-left"></i><span>กลับรายการ</span>
                </a>
                <a href="{{ route('coordinator.dashboard') }}" class="btn" style="background:#f8f9fa;color:#2c3e50;font-weight:600;display:inline-flex;align-items:center;gap:.5rem;">
                    <i class="bi bi-house"></i><span>Dashboard</span>
                </a>
            </div>
        </div>
    </div>

    @php $lateInfo = $project->getLateSubmissionInfo(); @endphp
    @if($lateInfo)
    <div class="alert border-0 mb-4 d-flex align-items-start gap-3"
         style="background:#fff3cd;border-left:5px solid #f59e0b !important;border-radius:10px;border:1px solid #fde68a;">
        <i class="bi bi-exclamation-triangle-fill text-warning mt-1" style="font-size:1.3rem;flex-shrink:0;"></i>
        <div>
            <div class="fw-bold" style="color:#92400e;">ส่งงานล่าช้า {{ $lateInfo['days'] }} วัน</div>
            <div class="small" style="color:#78350f;">
                กำหนดส่ง: <strong>{{ thaiDateTime($lateInfo['deadline']) }}</strong> &nbsp;|&nbsp;
                ส่งจริง: <strong>{{ thaiDateTime($project->submitted_at) }}</strong>
            </div>
            <div class="mt-1 small" style="color:#92400e;">
                <i class="bi bi-calculator me-1"></i>
                ควรหักคะแนนส่วนโครงงาน <strong>{{ $lateInfo['days'] }} × 20% = {{ $lateInfo['penalty_pct'] }}%</strong>
                จากคะแนนที่ได้รับ (กรุณาปรับเอง)
            </div>
        </div>
    </div>
    @endif

    @php
        $roleLabels = ['advisor'=>'ที่ปรึกษา','committee1'=>'กรรมการ 1','committee2'=>'กรรมการ 2','committee3'=>'กรรมการ 3'];
        $roleColors = ['advisor'=>'#4e73df','committee1'=>'#1cc88a','committee2'=>'#36b9cc','committee3'=>'#858796'];

        $students    = $project->group->members->pluck('student')->take(2);
        $activeRoles = collect(['advisor','committee1','committee2','committee3'])
            ->filter(fn($r) => $project->{$r.'_code'})->values()->toArray();
        $submittedRoles = $project->evaluations->pluck('evaluator_role')->unique()->values()->toArray();

        $studentComplete = [];
        foreach ($students as $idx => $student) {
            $done = $project->evaluations->where('student_id',$student->student_id)->pluck('evaluator_role')->unique()->values()->toArray();
            $studentComplete[$idx] = count($activeRoles) > 0 && count(array_intersect($activeRoles,$done)) === count($activeRoles);
        }
        $allComplete = count($students) > 0 && !in_array(false,$studentComplete,true);
        $doneRoles   = count($submittedRoles);

        $evalMatrix = [];
        foreach ($students as $idx => $student) {
            foreach ($activeRoles as $role) {
                $evalMatrix[$idx][$role] = $project->evaluations
                    ->where('student_id',$student->student_id)
                    ->where('evaluator_role',$role)->first();
            }
        }

        $studentSummary = [];
        foreach ($students as $idx => $student) {
            $evals       = $project->evaluations->where('student_id',$student->student_id);
            $advisorEval = $evals->where('evaluator_role','advisor')->first();
            $p1    = $advisorEval ? (float)($advisorEval->part1_score ?? 0) : 0;
            $p2avg = $evals->count() ? round($evals->avg('part2_score'),  2) : 0;
            $p3a   = $evals->count() ? round($evals->avg('part3a_score'), 2) : 0;
            $p3b   = $evals->count() ? round($evals->avg('part3b_score'), 2) : 0;
            $p3c   = $evals->count() ? round($evals->avg('part3c_score'), 2) : 0;
            $p3tot = $p3a + $p3b + $p3c;
            $final = $p1 + $p2avg + $p3tot;
            $studentSummary[$idx] = compact('p1','p2avg','p3a','p3b','p3c','p3tot','final');
        }

        $gradeColor = fn($s) => $s >= 90 ? '#1cc88a' : ($s >= 80 ? '#36b9cc' : ($s >= 70 ? '#4e73df' : ($s >= 60 ? '#f6c23e' : '#e74a3b')));
    @endphp

    {{-- Hero --}}
    <div class="hero-card card mb-4 px-4 py-4">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-clipboard-data text-white" style="font-size:1.3rem;"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;">คะแนนประเมินโครงงาน</div>
                        <h1 class="h5 fw-bold mb-0">{{ $project->project_name ?? 'ยังไม่ระบุชื่อ' }}</h1>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <code class="text-primary fw-bold" style="font-size:.82rem;">{{ $project->project_code }}</code>
                    <span class="text-muted">·</span>
                    <span class="text-muted small"><i class="bi bi-people me-1"></i>
                        @foreach($project->group->members as $m)
                            {{ $m->student->firstname_std ?? '' }}@if(!$loop->last), @endif
                        @endforeach
                    </span>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="row g-2 text-center">
                    <div class="col-4">
                        <div class="small text-muted mb-1">ผู้ประเมิน</div>
                        <div class="fw-bold h5 mb-0">{{ $doneRoles }}/{{ count($activeRoles) }}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted mb-1">สถานะ</div>
                        @if($allComplete)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">ครบสมบูรณ์</span>
                        @elseif($doneRoles > 0)
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">รอเพิ่มเติม</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">ยังไม่มีคะแนน</span>
                        @endif
                    </div>
                    <div class="col-4">
                        <div class="small text-muted mb-1">นักศึกษา</div>
                        <div class="fw-bold h5 mb-0">{{ $students->count() }} คน</div>
                    </div>
                </div>
            </div>
        </div>
        @if($allComplete && $project->group->subject_code === 'CS303')
    <form method="POST"
         action="{{ url('/coordinator/evaluations/' . $project->project_id . '/scores/finalize') }}"
          class="mt-3"
          onsubmit="return confirm('ยืนยันผลคะแนนและบันทึกเกรด CS303 ใช่หรือไม่?')">

        @csrf

        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle me-1"></i>
            ยืนยันผลคะแนนและบันทึกเกรด
        </button>
    </form>
@endif
    </div>


    {{-- Evaluator status --}}
    <div class="section-card card mb-4">
        <div class="card-header bg-white d-flex align-items-center gap-2 py-3">
            <i class="bi bi-person-check-fill text-primary"></i>
            <span class="fw-semibold small">สถานะผู้ประเมิน</span>
        </div>
        <div class="card-body p-0">
            <div class="row g-0">
                @foreach(['advisor','committee1','committee2','committee3'] as $role)
                    @php $code = $project->{$role.'_code'}; @endphp
                    @if($code)
                        @php
                            $user = match($role) {
                                'advisor'    => $project->advisor    ?? null,
                                'committee1' => $project->committee1 ?? null,
                                'committee2' => $project->committee2 ?? null,
                                'committee3' => $project->committee3 ?? null,
                            };
                            $submitted = $project->evaluations->where('evaluator_role',$role)->where('evaluator_code',$code)->isNotEmpty();
                            $initial   = $user ? strtoupper(mb_substr($user->firstname_user ?? '?',0,1)) : strtoupper(substr($code,0,1));
                        @endphp
                        <div class="col-sm-6 col-lg-3 border-end border-bottom">
                            <div class="evaluator-row">
                                <div class="person-avatar" style="background:{{ $roleColors[$role] }};">{{ $initial }}</div>
                                <div class="flex-fill overflow-hidden">
                                    <div class="info-label mb-1">{{ $roleLabels[$role] }}</div>
                                    <div class="fw-semibold small text-dark text-truncate">
                                        {{ $user ? $user->firstname_user.' '.$user->lastname_user : $code }}
                                    </div>
                                    <code class="text-muted" style="font-size:.7rem;">{{ $code }}</code>
                                </div>
                                @if($submitted)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.68rem;white-space:nowrap;">
                                        <i class="bi bi-check-circle-fill me-1"></i>ให้คะแนนแล้ว
                                    </span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle" style="font-size:.68rem;white-space:nowrap;">
                                        <i class="bi bi-hourglass-split me-1"></i>รอคะแนน
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    {{-- Score tables: one per student --}}
    @foreach($students as $idx => $student)
    @php
        $isDone  = $studentComplete[$idx] ?? false;
        $summary = $studentSummary[$idx];
        $fs      = $summary['final'];
        $fsColor = $gradeColor($fs);
    @endphp
    <div class="mb-4">
        <div class="student-header">
            <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0;">
                {{ strtoupper(mb_substr($student->firstname_std ?? 'N',0,1)) }}
            </div>
            <div>
                {{ $student->firstname_std }} {{ $student->lastname_std }}
                <span class="opacity-75 ms-2 fw-normal" style="font-size:.78rem;">{{ $student->student_code ?? '' }}</span>
            </div>
            <div class="ms-auto">
                @if($isDone)
                    <span class="badge bg-white text-success" style="font-size:.7rem;"><i class="bi bi-check-circle-fill me-1"></i>ครบสมบูรณ์</span>
                @else
                    <span class="badge" style="background:rgba(255,255,255,.2);font-size:.7rem;"><i class="bi bi-hourglass-split me-1"></i>รอคะแนนบางส่วน</span>
                @endif
            </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:0 0 12px 12px;">
            <div class="table-responsive">
                <table class="score-table">
                    <thead>
                        <tr>
                            <th class="th-criteria" style="width:35%;">เกณฑ์การประเมิน</th>
                            <th style="width:6%;">เต็ม</th>
                            @foreach($activeRoles as $role)
                                @php
                                    $ru = match($role) {
                                        'advisor'    => $project->advisor    ?? null,
                                        'committee1' => $project->committee1 ?? null,
                                        'committee2' => $project->committee2 ?? null,
                                        'committee3' => $project->committee3 ?? null,
                                        default      => null,
                                    };
                                @endphp
                                <th style="border-top:3px solid {{ $roleColors[$role] }};">
                                    {{ $roleLabels[$role] }}<br>
                                    <span class="fw-normal" style="font-size:.68rem;opacity:.8;">
                                        {{ $ru ? $ru->firstname_user : $project->{$role.'_code'} }}
                                    </span>
                                </th>
                            @endforeach
                            <th class="avg-cell" style="border-top:3px solid #a78bfa;">เฉลี่ย</th>
                        </tr>
                    </thead>
                    <tbody>

                        {{-- Part 1 --}}
                        <tr>
                            <td>
                                <span class="fw-semibold">ส่วนที่ 1</span>
                                <span class="text-muted ms-1 small">{{ $criteria->part1_label }}</span>
                                <div class="text-muted" style="font-size:.72rem;">(อาจารย์ที่ปรึกษาเท่านั้น)</div>
                            </td>
                            <td class="max-cell">{{ $criteria->part1_max }}</td>
                            @foreach($activeRoles as $role)
                                @php $ev = $evalMatrix[$idx][$role] ?? null; @endphp
                                <td class="score-cell">
                                    @if($role === 'advisor')
                                        @if($ev) <span class="text-info fw-bold">{{ number_format($ev->part1_score ?? 0,1) }}</span>
                                        @else <span class="badge-pending"><i class="bi bi-hourglass-split"></i> รอ</span>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="avg-cell"><span class="text-info fw-bold">{{ number_format($summary['p1'],1) }}</span></td>
                        </tr>

                        {{-- Part 2 --}}
                        <tr>
                            <td>
                                <span class="fw-semibold">ส่วนที่ 2</span>
                                <span class="text-muted ms-1 small">{{ $criteria->part2_label }}</span>
                            </td>
                            <td class="max-cell">{{ $criteria->part2_max }}</td>
                            @foreach($activeRoles as $role)
                                @php $ev = $evalMatrix[$idx][$role] ?? null; @endphp
                                <td class="score-cell">
                                    @if($ev) <span class="text-primary fw-bold">{{ number_format($ev->part2_score ?? 0,1) }}</span>
                                    @else <span class="badge-pending"><i class="bi bi-hourglass-split"></i> รอ</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="avg-cell"><span class="text-primary fw-bold">{{ number_format($summary['p2avg'],1) }}</span></td>
                        </tr>

                        {{-- Part 3 header --}}
                        <tr class="row-part-header">
                            <td colspan="{{ 2 + count($activeRoles) + 1 }}">
                                <i class="bi bi-card-checklist me-1"></i>
                                ส่วนที่ 3 — การนำเสนอโครงงาน
                                <span class="text-muted fw-normal ms-1" style="font-size:.8rem;">({{ $criteria->getPart3Max() }} คะแนน)</span>
                            </td>
                        </tr>

                        @foreach([
                            ['3.1', 'p3a', $criteria->part3a_label, $criteria->part3a_max, 'part3a_score', 'text-warning'],
                            ['3.2', 'p3b', $criteria->part3b_label, $criteria->part3b_max, 'part3b_score', 'text-warning'],
                            ['3.3', 'p3c', $criteria->part3c_label, $criteria->part3c_max, 'part3c_score', 'text-warning'],
                        ] as [$num, $key, $label, $max, $field, $color])
                        <tr class="row-sub">
                            <td>{{ $num }} {{ $label }}</td>
                            <td class="max-cell">{{ $max }}</td>
                            @foreach($activeRoles as $role)
                                @php $ev = $evalMatrix[$idx][$role] ?? null; @endphp
                                <td class="score-cell">
                                    @if($ev) <span class="{{ $color }} fw-bold">{{ number_format($ev->$field ?? 0,1) }}</span>
                                    @else <span class="badge-pending"><i class="bi bi-hourglass-split"></i> รอ</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="avg-cell"><span class="{{ $color }} fw-bold">{{ number_format($summary[$key],1) }}</span></td>
                        </tr>
                        @endforeach

                        {{-- Part 3 subtotal --}}
                        <tr class="row-subtotal">
                            <td>รวมส่วนที่ 3</td>
                            <td class="max-cell fw-bold">{{ $criteria->getPart3Max() }}</td>
                            @foreach($activeRoles as $role)
                                @php $ev = $evalMatrix[$idx][$role] ?? null; @endphp
                                <td class="score-cell">
                                    @if($ev) <span style="color:#3b4fd0;font-weight:700;">{{ number_format($ev->part3_score ?? 0,1) }}</span>
                                    @else <span class="badge-pending"><i class="bi bi-hourglass-split"></i> รอ</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="avg-cell" style="color:#3b4fd0;font-weight:700;">{{ number_format($summary['p3tot'],1) }}</td>
                        </tr>

                        {{-- Grand total --}}
                        <tr class="row-grand">
                            <td class="fw-bold">คะแนนรวมทั้งสิ้น</td>
                            <td class="max-cell fw-bold">{{ $criteria->getAdvisorMax() }}</td>
                            @foreach($activeRoles as $role)
                                @php
                                    $ev   = $evalMatrix[$idx][$role] ?? null;
                                    $maxR = $role === 'advisor' ? $criteria->getAdvisorMax() : $criteria->getCommitteeMax();
                                @endphp
                                <td class="score-cell">
                                    @if($ev)
                                        <span class="fw-bold" style="color:#4e73df;">{{ number_format($ev->total_score ?? 0,1) }}</span>
                                        <span class="text-muted" style="font-size:.7rem;">/{{ $maxR }}</span>
                                    @else
                                        <span class="badge-pending"><i class="bi bi-hourglass-split"></i> รอ</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="avg-cell">
                                <span class="fw-bold" style="font-size:1.1rem;color:{{ $fsColor }};">{{ number_format($fs,1) }}</span>
                                <span class="text-muted" style="font-size:.7rem;">/100</span>
                            </td>
                        </tr>

                    </tbody>
                </table>
            </div>

            {{-- Summary bar --}}
            <div class="d-flex border-top" style="background:{{ $isDone ? '#f0fdf4' : '#fffbeb' }};">
                @foreach([
                    ['ส่วนที่ 1', $summary['p1'],    $criteria->part1_max, 'text-info'],
                    ['ส่วนที่ 2', $summary['p2avg'], $criteria->part2_max, 'text-primary'],
                    ['3.1',       $summary['p3a'],  $criteria->part3a_max, 'text-warning'],
                    ['3.2',       $summary['p3b'],  $criteria->part3b_max, 'text-warning'],
                    ['3.3',       $summary['p3c'],  $criteria->part3c_max, 'text-warning'],
                ] as [$lbl, $val, $max, $cls])
                <div class="score-sum-box border-end">
                    <div class="lbl">{{ $lbl }}</div>
                    <div class="val {{ $cls }}">{{ number_format($val,1) }}</div>
                    <div class="mx">/{{ $max }}</div>
                </div>
                @endforeach
                <div class="score-sum-box final">
                    <div class="lbl">คะแนนรวม</div>
                    <div class="val" style="color:{{ $fsColor }};">{{ number_format($fs,1) }}</div>
                    <div class="mx">/{{ $criteria->getAdvisorMax() }}</div>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    @if($students->isEmpty())
        <div class="section-card card">
            <div class="card-body text-center py-5">
                <i class="bi bi-clipboard-x display-4 text-muted d-block mb-2" style="opacity:.3;"></i>
                <h6 class="text-muted fw-semibold">ยังไม่มีการให้คะแนน</h6>
                <p class="text-muted small mb-0">รอให้อาจารย์และคณะกรรมการให้คะแนนโครงงาน</p>
            </div>
        </div>
    @endif

</div>
@endsection
