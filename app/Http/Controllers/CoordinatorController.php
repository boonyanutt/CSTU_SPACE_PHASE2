<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Group;
use App\Models\Project;
use App\Models\ProjectLecturer;
use App\Models\User;
use App\Models\ProjectEvaluation;
use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class CoordinatorController extends Controller
{
    public function dashboard()
    {
        $user = Auth::guard('web')->user();

        $stats = [
            'total_groups'    => Group::count(),
            'pending_groups'  => Group::where('status_group', 'pending')->count(),
            'approved_groups' => Group::where('status_group', 'approved')->count(),
            'total_projects'  => Project::count(),
        ];

        $pendingGroups = Group::with(['members.student', 'project'])
            ->where('status_group', 'pending')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return view('coordinator.dashboard', compact('stats', 'pendingGroups'));
    }

    public function groups(Request $request)
    {
        $query = Group::with(['members.student', 'project', 'latestProposal.lecturer']);

        if ($request->status)   $query->where('status_group', $request->status);
        if ($request->subject)  $query->where('subject_code', $request->subject);
        if ($request->semester) $query->where('semester', $request->semester);
        if ($request->year)     $query->where('year', $request->year);

        $groups = $query
            ->orderBy('year', 'desc')
            ->orderBy('semester', 'desc')
            ->orderBy('group_id', 'asc')
            ->get();

        $years = DB::table('groups')->distinct()->orderBy('year', 'desc')->pluck('year');

        return view('coordinator.groups.index', compact('groups', 'years'));
    }

    public function groupShow($id)
    {
        $group = Group::with([
            'members.student',
            'project.advisorLecturer.user',
            'project.committeeLecturers.user',
            'project.parentProject.group',
            'latestProposal.lecturer',
        ])->findOrFail($id);

        $lecturers = User::whereRaw('role & 8192 != 0')
            ->orderBy('firstname_user', 'asc')
            ->get();

        // For CS403 groups: load CS303 passed/in_progress projects as parent candidates
        $parentProjects = collect();
        if ($group->subject_code === 'CS403') {
            $parentProjects = Project::with(['group.members.student'])
                ->whereHas('group', fn($q) => $q->where('subject_code', 'CS303'))
                ->whereIn('status_project', ['passed', 'in_progress', 'submitted', 'late_submission'])
                ->orderBy('project_code')
                ->get();
        }

        return view('coordinator.groups.show', compact('group', 'lecturers', 'parentProjects'));
    }

    public function approveGroup(Request $request, $id)
    {
        $user = Auth::guard('web')->user();

        if (!$user->canEdit()) {
            return redirect()->route('coordinator.groups.show', $id)
                ->with('error', 'คุณไม่มีสิทธิ์อนุมัติกลุ่ม (Staff read-only)');
        }

        $request->validate([
            'project_name'      => 'required|string|max:255',
            'advisor_code'      => 'required|string|exists:user,user_code',
            'student_type'      => 'required|in:r,s',
            'parent_project_id' => 'nullable|integer|exists:projects,project_id',
        ]);

        $group = Group::findOrFail($id);

        DB::beginTransaction();
        try {
            $group->update(['status_group' => 'approved']);

            $memberCount  = $group->members->count();
            $advisorCode  = $request->advisor_code;
            $studentType  = $request->student_type;

            $projectCode = sprintf(
                '%02d-%d-%02d_%s-%s%d',
                $group->year % 100,
                $group->semester,
                $group->group_id,
                $advisorCode,
                $studentType,
                $memberCount
            );

            $project = Project::create([
                'group_id'          => $group->group_id,
                'parent_project_id' => $request->filled('parent_project_id') ? (int)$request->parent_project_id : null,
                'project_name'      => $request->project_name,
                'project_code'      => $projectCode,
                'student_type'      => $studentType,
                'status_project'    => 'in_progress',
            ]);

            $project->projectLecturers()->create([
                'user_code'       => $advisorCode,
                'relationship_id' => 1,
                'sort_order'      => 1,
            ]);

            DB::commit();
            return redirect()->route('coordinator.groups.show', $id)
                ->with('success', 'อนุมัติกลุ่มและสร้างโครงงานเรียบร้อยแล้ว');

        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }

    public function updateProject(Request $request, $id)
    {
        $user = Auth::guard('web')->user();

        if (!$user->canEdit()) {
            return back()->with('error', 'คุณไม่มีสิทธิ์แก้ไขข้อมูล (Staff read-only)');
        }

        $request->validate([
            'project_name'      => 'nullable|string|max:255',
            'advisor_code'      => 'nullable|string|exists:user,user_code',
            'committee1_code'   => 'nullable|string|exists:user,user_code',
            'committee2_code'   => 'nullable|string|exists:user,user_code',
            'committee3_code'   => 'nullable|string|exists:user,user_code',
            'exam_datetime'     => 'nullable|date',
            'project_type'      => 'nullable|string',
            'status_project'    => 'nullable|string',
            'parent_project_id' => 'nullable|integer|exists:projects,project_id',
        ]);

        $lecturers = array_filter([
            $request->advisor_code,
            $request->committee1_code,
            $request->committee2_code,
            $request->committee3_code,
        ]);

        if (count($lecturers) !== count(array_unique($lecturers))) {
            return back()->with('error', 'ไม่สามารถเลือกอาจารย์คนเดียวกันในหลายตำแหน่งได้');
        }

        $group = Group::with('project')->findOrFail($id);

        if (!$group->project) {
            return back()->with('error', 'กลุ่มนี้ยังไม่มีโครงงาน');
        }

        DB::beginTransaction();
        try {
            $group->project->update($request->only([
                'project_name', 'exam_datetime', 'project_type', 'status_project', 'parent_project_id',
            ]));

            $group->project->syncLecturers(
                $request->advisor_code,
                $request->committee1_code,
                $request->committee2_code,
                $request->committee3_code,
            );

            DB::commit();
            return back()->with('success', 'อัพเดทข้อมูลโครงงานเรียบร้อยแล้ว');

        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }

    public function projectsReview(Request $request)
    {
        if (!PermissionHelper::isCoordinator() && !PermissionHelper::isAdmin() && !PermissionHelper::isStaff()) {
            return redirect()->route('menu')->with('error', 'Unauthorized access');
        }

        $query = Project::with([
            'group.members.student',
            'advisorLecturer.user',
            'committeeLecturers.user',
            'examSchedule',
        ]);

        if ($request->status) {
            $query->where('status_project', $request->status);
        }
        if ($request->year) {
            $query->whereHas('group', fn($q) => $q->where('year', $request->year));
        }
        if ($request->semester) {
            $query->whereHas('group', fn($q) => $q->where('semester', $request->semester));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('project_code', 'like', "%{$s}%")
                ->orWhere('project_name', 'like', "%{$s}%")
            );
        }

        $projects = $query
            ->join('groups', 'projects.group_id', '=', 'groups.group_id')
            ->orderBy('groups.year', 'desc')
            ->orderBy('groups.semester', 'desc')
            ->orderBy('groups.group_id', 'asc')
            ->orderBy('projects.project_code', 'asc')
            ->select('projects.*')
            ->get();

        $statuses  = ['pending', 'approved', 'in_progress', 'submitted', 'late_submission', 'passed', 'failed', 'rejected'];
        $years     = DB::table('groups')->distinct()->orderBy('year', 'desc')->pluck('year');
        $semesters = [1, 2];

        return view('coordinator.projects.review', compact('projects', 'statuses', 'years', 'semesters'));
    }

    public function updateProjectReview(Request $request, $projectId)
    {
        $request->validate([
            'status_project' => 'required|in:not_proposed,pending,approved,rejected,in_progress,late_submission,submitted,passed,failed',
            'notes'          => 'nullable|string|max:500',
        ]);

        try {
            $project = Project::findOrFail($projectId);

            $user = Auth::guard('web')->user();
            if ($user && $user->isStaff()) {
                DB::table('project_activities')->insertOrIgnore([
                    'project_id' => $projectId,
                    'user_code'  => $user->user_code,
                    'action'     => 'status_changed',
                    'old_value'  => $project->status_project,
                    'new_value'  => $request->status_project,
                    'notes'      => $request->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                \Log::info('Project status updated by staff', [
                    'project_id' => $projectId,
                    'staff_code' => $user->user_code,
                    'old_status' => $project->status_project,
                    'new_status' => $request->status_project,
                ]);
            }

            $project->update(['status_project' => $request->status_project]);

            return back()->with('success', 'อัพเดตสถานะโครงงาน: ' . $project->project_code . ' เรียบร้อยแล้ว');

        } catch (\Exception $e) {
            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }

    public function settings()
    {
        $currentYear     = 2568;
        $currentSemester = 1;
        return view('coordinator.settings', compact('currentYear', 'currentSemester'));
    }

    public function exportCsv(Request $request)
    {
        $query = Project::with([
            'group.members.student',
            'advisorLecturer.user',
            'committeeLecturers.user',
        ]);

        if ($request->status) {
            $query->where('status_project', $request->status);
        }
        if ($request->year) {
            $query->whereHas('group', fn($q) => $q->where('year', $request->year));
        }
        if ($request->semester) {
            $query->whereHas('group', fn($q) => $q->where('semester', $request->semester));
        }

        $projects = $query->orderBy('project_code', 'asc')->get();

        $filename = 'projects_' . date('Y-m-d_His') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($projects) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, [
                'project_id', 'project_code', 'project_name',
                'student1', 'student2',
                'advisor_code', 'committee1_code', 'committee2_code', 'committee3_code',
                'exam_datetime',
            ]);

            foreach ($projects as $index => $project) {
                $members  = $project->group->members ?? collect();
                $member1  = $members->get(0);
                $member2  = $members->get(1);
                $coms     = $project->committeeLecturers;

                fputcsv($file, [
                    $index + 1,
                    $project->project_code ?? '-',
                    $project->project_name ?? '-',
                    $member1 ? "{$member1->student->firstname_std} {$member1->student->lastname_std}" : '-',
                    $member2 ? "{$member2->student->firstname_std} {$member2->student->lastname_std}" : '-',
                    $project->advisor_code ?? '-',
                    $coms->get(0)?->user_code ?? '-',
                    $coms->get(1)?->user_code ?? '-',
                    $coms->get(2)?->user_code ?? '-',
                    $project->exam_datetime ? $project->exam_datetime->format('d.m.y, H:i') : '-',
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    // ──────────────────────────────────────────
    // Schedule & Committee
    // ──────────────────────────────────────────

    public function schedulesIndex(Request $request)
    {
        if (!PermissionHelper::isCoordinator() && !PermissionHelper::isAdmin() && !PermissionHelper::isStaff()) {
            return redirect()->route('menu')->with('error', 'Unauthorized access');
        }

        $query = Project::with([
            'group.members.student',
            'advisorLecturer.user',
            'committeeLecturers.user',
        ]);

        if ($request->filled('semester')) {
            $query->whereHas('group', fn($q) => $q->where('semester', $request->semester));
        }
        if ($request->filled('year')) {
            $query->whereHas('group', fn($q) => $q->where('year', $request->year));
        }
        if ($request->filled('course_code')) {
            $query->whereHas('group', fn($q) => $q->where('subject_code', $request->course_code));
        }
        if ($request->filled('has_exam')) {
            $request->has_exam === '1'
                ? $query->whereNotNull('exam_datetime')
                : $query->whereNull('exam_datetime');
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('project_code', 'like', "%{$s}%")
                ->orWhere('project_name', 'like', "%{$s}%")
            );
        }

        // Stats from filtered query (before pagination)
        $statsQuery   = clone $query;
        $totalCount   = $statsQuery->count();
        $withExam     = (clone $query)->whereNotNull('exam_datetime')->count();
        $withoutExam  = $totalCount - $withExam;

        $projects = $query
            ->join('groups', 'projects.group_id', '=', 'groups.group_id')
            ->orderBy('groups.year', 'desc')
            ->orderBy('groups.semester', 'desc')
            ->orderBy('groups.group_id', 'asc')
            ->select('projects.*')
            ->get();

        $years = DB::table('groups')->distinct()->orderBy('year', 'desc')->pluck('year');

        return view('coordinator.schedules.index', compact(
            'projects', 'totalCount', 'withExam', 'withoutExam', 'years'
        ));
    }

    public function scheduleEdit($projectId)
    {
        if (!PermissionHelper::isCoordinator() && !PermissionHelper::isAdmin() && !PermissionHelper::isStaff()) {
            return redirect()->route('menu')->with('error', 'Unauthorized access');
        }

        $project = Project::with([
            'group.members.student',
            'advisorLecturer.user',
            'committeeLecturers.user',
            'coAdvisorInternal.user',
            'coAdvisorExternal.user',
        ])->findOrFail($projectId)->refresh();

        $lecturers = User::whereRaw('(role & ?) != 0', [8192])
            ->orderBy('firstname_user')->get();

        return view('coordinator.schedules.edit', compact('project', 'lecturers'));
    }

    public function scheduleUpdate(Request $request, $projectId)
    {
        if (!PermissionHelper::isCoordinator() && !PermissionHelper::isAdmin() && !PermissionHelper::isStaff()) {
            return redirect()->route('menu')->with('error', 'Unauthorized access');
        }

        $request->validate([
            'exam_datetime'    => 'nullable|date',
            'exam_end_time'    => 'nullable|date',
            'advisor_code'     => 'nullable|exists:user,user_code',
            'committee1_code'  => 'nullable|exists:user,user_code',
            'committee2_code'  => 'nullable|exists:user,user_code',
            'committee3_code'  => 'nullable|exists:user,user_code',
            'coadv_int_code'   => 'nullable|exists:user,user_code',
            'coadv_ext_code'   => 'nullable|exists:user,user_code',
        ]);

        $project = Project::with([
            'advisorLecturer', 'committeeLecturers',
            'coAdvisorInternal', 'coAdvisorExternal',
        ])->findOrFail($projectId);

        // Check for exam datetime conflict (same datetime used by another project)
        $conflictWarning = null;
        if ($request->filled('exam_datetime')) {
            $conflictProjects = \App\Models\Project::where('project_id', '!=', $projectId)
                ->where('exam_datetime', $request->exam_datetime)
                ->pluck('project_code')
                ->toArray();
            if (!empty($conflictProjects)) {
                $conflictWarning = 'วันเวลาสอบ ' . \Carbon\Carbon::parse($request->exam_datetime)->format('d/m/Y H:i') .
                    ' ถูกใช้โดยโครงงาน: ' . implode(', ', $conflictProjects) . ' แล้ว';
            }
        }

        $examDateChanged = $project->exam_datetime != $request->exam_datetime && $request->exam_datetime;
        $committeeChanged = (
            $project->advisor_code    != $request->advisor_code    ||
            $project->committee1_code != $request->committee1_code ||
            $project->committee2_code != $request->committee2_code ||
            $project->committee3_code != $request->committee3_code ||
            ($project->coAdvisorInternal?->user_code) != $request->coadv_int_code ||
            ($project->coAdvisorExternal?->user_code) != $request->coadv_ext_code
        );

        $project->update([
            'exam_datetime' => $request->exam_datetime ?: null,
            'exam_end_time' => $request->exam_end_time ?: null,
        ]);

        $project->syncLecturers(
            $request->advisor_code,
            $request->committee1_code,
            $request->committee2_code,
            $request->committee3_code,
        );

        $project->syncCoAdvisors(
            $request->coadv_int_code ?: null,
            $request->coadv_ext_code ?: null,
        );

        $user = Auth::guard('web')->user();
        if ($user && PermissionHelper::isStaff() && ($examDateChanged || $committeeChanged)) {
            \Log::info('Schedule updated by staff', [
                'project_id'       => $projectId,
                'staff_code'       => $user->user_code,
                'exam_date_changed' => $examDateChanged,
                'committee_changed' => $committeeChanged,
            ]);
        }

        if ($examDateChanged) {
            session()->flash('exam_schedule_updated', [
                'project_id'    => $project->project_id,
                'exam_datetime' => $request->exam_datetime,
            ]);
        }

        $redirect = redirect()->route('coordinator.schedules.index')
            ->with('success', 'อัพเดทตารางสอบและคณะกรรมการเรียบร้อยแล้ว');

        if ($conflictWarning) {
            $redirect = $redirect->with('warning', $conflictWarning);
        }

        return $redirect;
    }

    public function scheduleImportForm()
    {
        $lecturers = DB::table('user')
            ->whereRaw('(role & ?) != 0', [8192 | 16384 | 32768])
            ->select('user_code', 'firstname_user', 'lastname_user')
            ->orderBy('user_code')
            ->get();

        return view('coordinator.schedules.import', compact('lecturers'));
    }

    public function scheduleImportTemplate()
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="schedule_import_template.csv"',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, ['project_code', 'exam_datetime', 'advisor_code', 'committee1_code', 'committee2_code', 'committee3_code']);
            fputcsv($file, ['68-1-01_kdc-r1', '2025-05-20 09:00', 'SCH', 'JDO', 'SMY', '']);
            fputcsv($file, ['68-1-02_abc-r2', '2025-05-20 10:00', 'SCH', 'KWT', 'JDO', '']);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function scheduleImportPreview(Request $request)
    {
        $request->validate(['file' => 'required|mimes:csv,txt|max:2048']);

        $path   = $request->file('file')->getRealPath();
        $rows   = array_map(fn($line) => str_getcsv($line), file($path));
        $header = array_shift($rows);
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $validCodes = DB::table('user')
            ->whereNotNull('user_code')
            ->pluck('user_code')
            ->map(fn($c) => strtolower(trim($c)))
            ->flip()
            ->toArray();

        $projectMap = Project::with(['group.members.student'])->get()->keyBy('project_code');

        $preview = [];

        foreach ($rows as $i => $row) {
            if (count(array_filter($row)) === 0) continue;

            $data = [];
            foreach ($header as $col => $key) {
                $data[$key] = trim($row[$col] ?? '');
            }

            $projCode = $data['project_code'] ?? '';
            $examDt   = $data['exam_datetime'] ?? '';
            $advisor  = $data['advisor_code'] ?? '';
            $comm1    = $data['committee1_code'] ?? '';
            $comm2    = $data['committee2_code'] ?? '';
            $comm3    = $data['committee3_code'] ?? '';

            $errors  = [];
            $project = $projectMap->get($projCode);

            if (!$project) {
                $errors[] = "ไม่พบโครงงาน '{$projCode}' ในระบบ";
            }

            $parsedDt = null;
            if ($examDt) {
                try {
                    $parsedDt = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $examDt);
                } catch (\Exception $e) {
                    $errors[] = "รูปแบบวันเวลาไม่ถูกต้อง '{$examDt}' (ใช้ YYYY-MM-DD HH:MM)";
                }
            }

            foreach (['advisor_code' => $advisor, 'committee1_code' => $comm1, 'committee2_code' => $comm2, 'committee3_code' => $comm3] as $field => $code) {
                if ($code && !isset($validCodes[strtolower($code)])) {
                    $errors[] = "ไม่พบ user_code '{$code}' ในระบบ";
                }
            }

            $members = $project
                ? $project->group->members->map(fn($m) => trim(($m->student->firstname_std ?? '') . ' ' . ($m->student->lastname_std ?? '')))->join(', ')
                : '-';

            $preview[] = [
                'line'            => $i + 2,
                'project_code'    => $projCode,
                'project_name'    => $project?->project_name ?? '-',
                'members'         => $members,
                'exam_datetime'   => $examDt,
                'advisor_code'    => $advisor,
                'committee1_code' => $comm1,
                'committee2_code' => $comm2,
                'committee3_code' => $comm3,
                'errors'          => $errors,
                'valid'           => empty($errors),
            ];
        }

        session(['schedule_import_preview' => $preview]);

        return view('coordinator.schedules.import', compact('preview'));
    }

    public function scheduleImportConfirm(Request $request)
    {
        $preview = session('schedule_import_preview', []);

        if (empty($preview)) {
            return redirect()->route('coordinator.schedules.import.form')
                ->with('error', 'ไม่พบข้อมูล Preview กรุณาอัปโหลดไฟล์ใหม่');
        }

        $imported = 0;
        $skipped  = 0;

        foreach ($preview as $row) {
            if (!$row['valid']) { $skipped++; continue; }

            $project = Project::where('project_code', $row['project_code'])->first();
            if (!$project) { $skipped++; continue; }

            if ($row['exam_datetime']) {
                $project->update([
                    'exam_datetime' => \Carbon\Carbon::createFromFormat('Y-m-d H:i', $row['exam_datetime']),
                ]);
            }

            $project->syncLecturers(
                $row['advisor_code']    ?: null,
                $row['committee1_code'] ?: null,
                $row['committee2_code'] ?: null,
                $row['committee3_code'] ?: null,
            );

            $imported++;
        }

        session()->forget('schedule_import_preview');

        return redirect()->route('coordinator.schedules.index')
            ->with('success', "Import สำเร็จ {$imported} โครงงาน" . ($skipped ? ", ข้าม {$skipped} แถว (มีข้อผิดพลาด)" : ''));
    }

    // ──────────────────────────────────────────
    // Evaluation & Grading
    // ──────────────────────────────────────────

    public function evaluationsIndex(Request $request)
    {
        $query = Project::with(['group.members.student', 'evaluations.evaluator']);

        if ($request->filled('semester')) {
            $query->whereHas('group', fn($q) => $q->where('semester', $request->semester));
        }
        if ($request->filled('year')) {
            $query->whereHas('group', fn($q) => $q->where('year', $request->year));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('project_name', 'like', "%{$s}%")
                ->orWhere('project_code', 'like', "%{$s}%")
            );
        }

        $projects = $query
            ->join('groups', 'projects.group_id', '=', 'groups.group_id')
            ->orderBy('groups.year', 'desc')
            ->orderBy('groups.semester', 'desc')
            ->orderBy('groups.group_id', 'asc')
            ->select('projects.*')
            ->get();

        if ($request->filled('eval_status')) {
            $projects = $projects->filter(function ($p) use ($request) {
                $exp  = collect([$p->advisor_code, $p->committee1_code, $p->committee2_code, $p->committee3_code])->filter()->count();
                $done = $p->evaluations->pluck('evaluator_role')->unique()->count();
                return match($request->eval_status) {
                    'complete' => $exp > 0 && $done >= $exp,
                    'partial'  => $done > 0 && $done < $exp,
                    'none'     => $done === 0,
                    default    => true,
                };
            })->values();
        }

        $years = DB::table('groups')->distinct()->orderBy('year', 'desc')->pluck('year');

        return view('coordinator.evaluations.index', compact('projects', 'years'));
    }

    public function exportScores(Request $request)
    {
        $subjects = $this->buildExportData($request);

        $hasData = collect($subjects)->contains(
            fn($s) => count($s['rowsDetail']) > 0 || count($s['rowsSummary']) > 0
        );
        if (!$hasData) {
            return back()->with('warning', 'ไม่พบข้อมูลคะแนนสำหรับเงื่อนไขที่เลือก');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // remove default sheet

        $blueHeader  = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ];
        $greenHeader = array_replace_recursive($blueHeader, ['fill' => ['startColor' => ['rgb' => '166534']]]);

        $sheetDefs = [
            ['cs303', 'detail',  'CS303 - แยกตามอาจารย์', $blueHeader,  'EFF6FF', 'L'],
            ['cs303', 'summary', 'CS303 - สรุปรวม',        $greenHeader, 'F0FDF4', 'K'],
            ['cs403', 'detail',  'CS403 - แยกตามอาจารย์', $blueHeader,  'EFF6FF', 'L'],
            ['cs403', 'summary', 'CS403 - สรุปรวม',        $greenHeader, 'F0FDF4', 'K'],
        ];

        foreach ($sheetDefs as [$subKey, $type, $title, $headerStyle, $zebraColor, $lastCol]) {
            $s    = $subjects[$subKey];
            $headers = $type === 'detail' ? $s['headersDetail'] : $s['headersSummary'];
            $rows    = $type === 'detail' ? $s['rowsDetail']    : $s['rowsSummary'];

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($headers, null, 'A1');
            $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerStyle);

            $rowNum = 2;
            foreach ($rows as $row) {
                $sheet->fromArray($row, null, "A{$rowNum}");
                if ($rowNum % 2 === 0) {
                    $sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")
                        ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($zebraColor);
                }
                $rowNum++;
            }
            foreach (range('A', $lastCol) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $filterLabel = implode('_', array_filter([
            $request->input('course_code'),
            $request->input('year')     ? 'ปี'    . $request->input('year')     : null,
            $request->input('semester') ? 'เทอม'  . $request->input('semester') : null,
        ])) ?: 'ทั้งหมด';
        $filename = 'ผลคะแนน_' . $filterLabel . '_' . now()->format('Ymd') . '.xlsx';

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function buildExportData(Request $request): array
    {
        $semester   = $request->input('semester');
        $year       = $request->input('year');
        $courseCode = $request->input('course_code');
        $sort       = $request->input('sort', 'code'); // code | student | time

        $evaluations = \App\Models\ProjectEvaluation::with([
            'project.group',
            'student',
            'evaluator',
        ])->whereHas('project.group', function ($q) use ($semester, $year, $courseCode) {
            if ($semester)   $q->where('semester',     $semester);
            if ($year)       $q->where('year',         $year);
            if ($courseCode) $q->where('subject_code', $courseCode);
        })->get();

        $roleLabels = [
            'advisor'    => 'อาจารย์ที่ปรึกษา',
            'committee1' => 'กรรมการคนที่ 1',
            'committee2' => 'กรรมการคนที่ 2',
            'committee3' => 'กรรมการคนที่ 3',
        ];
        $roleOrder = ['advisor' => 0, 'committee1' => 1, 'committee2' => 2, 'committee3' => 3];

        $studentName = fn($s) => trim(($s->firstname_std ?? '') . ' ' . ($s->lastname_std ?? ''));

        $result = [];

        foreach (['CS303', 'CS403'] as $subject) {
            $subEvals = $evaluations->filter(
                fn($e) => ($e->project->group->subject_code ?? '') === $subject
            );
            $crit = \App\Models\EvaluationCriteria::forSubject($subject);

            $headersDetail = [
                'รหัสนักศึกษา', 'ชื่อ-สกุล', 'รหัสโครงงาน', 'ชื่อโครงงาน',
                $crit->part1_label  . ' (/' . $crit->part1_max  . ')',
                $crit->part2_label  . ' (/' . $crit->part2_max  . ')',
                $crit->part3a_label . ' (/' . $crit->part3a_max . ')',
                $crit->part3b_label . ' (/' . $crit->part3b_max . ')',
                $crit->part3c_label . ' (/' . $crit->part3c_max . ')',
                'รวม', 'อาจารย์ผู้ประเมิน', 'บทบาท',
            ];

            $headersSummary = [
                'รหัสนักศึกษา', 'ชื่อ-สกุล', 'รหัสโครงงาน', 'ชื่อโครงงาน',
                $crit->part1_label  . ' เฉลี่ย',
                $crit->part2_label  . ' เฉลี่ย',
                $crit->part3a_label . ' เฉลี่ย',
                $crit->part3b_label . ' เฉลี่ย',
                $crit->part3c_label . ' เฉลี่ย',
                'รวม (เฉลี่ย)', 'จำนวนผู้ประเมิน',
            ];

            // Detail: 1 row per eval record — sort based on $sort param
            $sortedDetail = match ($sort) {
                'student' => $subEvals->sortBy(fn($e) => sprintf('%s_%d_%s',
                    $e->student_id ?? '',
                    $roleOrder[$e->evaluator_role] ?? 9,
                    $e->project->project_code ?? ''
                )),
                'time' => $subEvals->sortBy('submitted_at'),
                default  => $subEvals->sortBy(fn($e) => sprintf('%s_%d_%s',
                    $e->project->project_code ?? '',
                    $roleOrder[$e->evaluator_role] ?? 9,
                    $e->student_id ?? ''
                )),
            };

            $rowsDetail = [];
            foreach ($sortedDetail as $eval) {
                $student = $eval->student;
                if (!$student) continue;
                $evaluatorName = $eval->evaluator
                    ? trim(($eval->evaluator->firstname_user ?? '') . ' ' . ($eval->evaluator->lastname_user ?? ''))
                    : $eval->evaluator_code;
                $rowsDetail[] = [
                    $student->username_std ?? $eval->student_id,
                    $studentName($student),
                    $eval->project->project_code ?? '',
                    $eval->project->project_name ?? '',
                    $eval->part1_score,
                    $eval->part2_score,
                    $eval->part3a_score,
                    $eval->part3b_score,
                    $eval->part3c_score,
                    $eval->total_score,
                    $evaluatorName,
                    $roleLabels[$eval->evaluator_role] ?? $eval->evaluator_role,
                ];
            }

            // Summary: 1 row per (project × student), avg scores across all evaluators for that student
            $rowsSummary = [];
            $grouped = $subEvals->groupBy(fn($e) => $e->project_id . '|' . $e->student_id);
            $grouped = $grouped->sortBy(function ($evals) use ($sort) {
                $e = $evals->first();
                return $sort === 'student'
                    ? ($e->student_id ?? '') . '_' . ($e->project->project_code ?? '')
                    : ($e->project->project_code ?? '') . '_' . ($e->student_id ?? '');
            });
            foreach ($grouped as $evals) {
                $first   = $evals->first();
                $student = $first->student;
                if (!$student) continue;
                $count = $evals->count();
                $rowsSummary[] = [
                    $student->username_std ?? '',
                    $studentName($student),
                    $first->project->project_code ?? '',
                    $first->project->project_name ?? '',
                    round($evals->avg('part1_score'),  2),
                    round($evals->avg('part2_score'),  2),
                    round($evals->avg('part3a_score'), 2),
                    round($evals->avg('part3b_score'), 2),
                    round($evals->avg('part3c_score'), 2),
                    round($evals->avg('total_score'),  2),
                    $count,
                ];
            }

            $result[strtolower($subject)] = compact('headersDetail', 'headersSummary', 'rowsDetail', 'rowsSummary');
        }

        return $result;
    }

    public function exportPreview(Request $request)
    {
        $subjects = $this->buildExportData($request);
        $years    = DB::table('groups')->distinct()->orderBy('year', 'desc')->pluck('year');
        return view('coordinator.evaluations.export-preview', compact('subjects', 'years'));
    }

    public function exportData(Request $request)
    {
        $data = $this->buildExportData($request);
        $data['updated_at'] = now()->format('H:i:s');
        return response()->json($data);
    }

    public function viewScores($projectId)
    {
        $project = Project::with([
            'group.members.student',
            'evaluations.evaluator',
            'advisorLecturer.user',
            'committeeLecturers.user',
        ])->findOrFail($projectId);

        $criteria = \App\Models\EvaluationCriteria::forSubject($project->group->subject_code ?? '');

        return view('coordinator.evaluations.scores', compact('project', 'criteria'));
    }
    public function finalizeGrades($projectId)
{
    $project = Project::with([
        'group.members.student',
        'evaluations'
    ])->findOrFail($projectId);

    // การบันทึกเกรดชุดนี้ใช้สำหรับ CS303 เท่านั้น
    if (!$project->group || $project->group->subject_code !== 'CS303') {
        return redirect()->back()
            ->with('error', 'สามารถยืนยันเกรดได้เฉพาะโครงงานวิชา CS303');
    }

    // ผู้ประเมินที่โครงงานนี้มีจริง
    $activeRoles = collect([
        'advisor',
        'committee1',
        'committee2',
        'committee3'
    ])->filter(function ($role) use ($project) {
        return !empty($project->{$role . '_code'});
    })->values();

    if ($activeRoles->isEmpty()) {
        return redirect()->back()
            ->with('error', 'ไม่พบผู้ประเมินของโครงงานนี้');
    }

    $members = $project->group->members;

    if ($members->isEmpty()) {
        return redirect()->back()
            ->with('error', 'ไม่พบนักศึกษาในกลุ่มนี้');
    }

    /*
     * STEP 1
     * ตรวจให้ครบก่อนทุกคน
     *
     * ถ้ายังมีใครได้คะแนนไม่ครบ
     * จะไม่บันทึกเกรดให้ใครเลย
     */
    foreach ($members as $member) {

        $student = $member->student;

        if (!$student) {
            return redirect()->back()
                ->with('error', 'พบสมาชิกที่ไม่มีข้อมูลนักศึกษา');
        }

        $evaluations = $project->evaluations
            ->where('student_id', $student->student_id);

        $submittedRoles = $evaluations
            ->pluck('evaluator_role')
            ->unique();

        foreach ($activeRoles as $role) {

            if (!$submittedRoles->contains($role)) {
                return redirect()->back()
                    ->with(
                        'error',
                        'ยังไม่สามารถยืนยันเกรดได้ เนื่องจากคะแนนของ '
                        . $student->firstname_std . ' '
                        . $student->lastname_std
                        . ' ยังไม่ครบ'
                    );
            }
        }
    }

    /*
     * STEP 2
     * เมื่อคะแนนของทุกคนครบแล้ว
     * คำนวณ Final Score + Grade
     */
    DB::transaction(function () use ($project, $members) {

        foreach ($members as $member) {

            $student = $member->student;

            $evaluations = $project->evaluations
                ->where('student_id', $student->student_id);

            /*
             * Part 1
             * ใช้คะแนนจาก Advisor เท่านั้น
             */
            $advisorEvaluation = $evaluations
                ->where('evaluator_role', 'advisor')
                ->first();

            $p1 = $advisorEvaluation
                ? (float) ($advisorEvaluation->part1_score ?? 0)
                : 0;

            /*
             * Part 2
             * ค่าเฉลี่ยจากผู้ประเมินทั้งหมด
             */
            $p2avg = $evaluations->count()
                ? round($evaluations->avg('part2_score'), 2)
                : 0;

            /*
             * Part 3
             * ค่าเฉลี่ยแต่ละหัวข้อ
             */
            $p3a = $evaluations->count()
                ? round($evaluations->avg('part3a_score'), 2)
                : 0;

            $p3b = $evaluations->count()
                ? round($evaluations->avg('part3b_score'), 2)
                : 0;

            $p3c = $evaluations->count()
                ? round($evaluations->avg('part3c_score'), 2)
                : 0;

            /*
             * Final Score
             *
             * ใช้สูตรเดียวกับหน้า scores.blade.php เดิม
             */
            $finalScore = $p1 + $p2avg + $p3a + $p3b + $p3c;

            /*
             * แปลงคะแนนเป็นเกรด
             */
            if ($finalScore >= 90) {
                $grade = 'A';
            } elseif ($finalScore >= 85) {
                $grade = 'B+';
            } elseif ($finalScore >= 80) {
                $grade = 'B';
            } elseif ($finalScore >= 70) {
                $grade = 'C+';
            } elseif ($finalScore >= 60) {
                $grade = 'C';
            } elseif ($finalScore >= 55) {
                $grade = 'D+';
            } elseif ($finalScore >= 50) {
                $grade = 'D';
            } else {
                $grade = 'F';
            }

            /*
             * บันทึกลง student.grade_cs303
             */
            $student->grade_cs303 = $grade;
            $student->save();
        }
    });

    return redirect()->back()
        ->with('success', 'ยืนยันผลคะแนนและบันทึกเกรด CS303 เรียบร้อยแล้ว');
}
}
