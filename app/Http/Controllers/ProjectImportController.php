<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Helpers\XlsxParser;
use App\Helpers\PermissionHelper;
use App\Models\UserActivityLog;
use Illuminate\Support\Facades\Session;

class ProjectImportController extends Controller
{
    public function form()
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }
        return view('admin.projects.import-excel');
    }

    public function preview(Request $request)
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        $request->validate([
            'file'       => 'required|file|mimes:xlsx,xls|max:20480',
            'exam_month' => 'nullable|date_format:Y-m',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes'    => 'รองรับเฉพาะ .xlsx / .xls',
            'file.max'      => 'ขนาดไฟล์ต้องไม่เกิน 20 MB',
        ]);

        $examMonth = $request->input('exam_month'); // e.g. "2025-08" or null
        $semester  = (int)$request->input('semester', 2);
        $year      = (int)$request->input('year', 2568);

        try {
            $sheetNames = XlsxParser::sheetNames($request->file('file'));
        } catch (\Exception $e) {
            return back()->with('error', 'อ่านไฟล์ไม่ได้: ' . $e->getMessage());
        }

        // Match --ProjectsDetails, --ProjectDetails, --ProjectsDetail, etc.
        $projectSheets = array_filter(
            $sheetNames,
            fn($n) => str_contains(strtolower($n), '--project') && !str_contains(strtolower($n), 'student')
        );

        if (empty($projectSheets)) {
            return back()->with('error', 'ไม่พบ sheet ที่ชื่อประกอบด้วย "--Project" ในไฟล์นี้');
        }

        // Load valid user codes (case-insensitive lookup)
        $validUserCodes = DB::table('user')
            ->whereNotNull('user_code')
            ->where('user_code', '!=', '')
            ->pluck('user_code')
            ->mapWithKeys(fn($c) => [strtolower(trim($c)) => trim($c)])
            ->toArray();

        // Load existing student IDs
        $existingStudents = DB::table('student')
            ->pluck('username_std')
            ->flip()
            ->toArray();

        // Load existing project codes
        $existingProjectCodes = DB::table('projects')
            ->pluck('project_code')
            ->flip()
            ->toArray();

        $preview = [];

        foreach ($projectSheets as $sName) {
            preg_match('/\b(CS\d+)\b/i', $sName, $m);
            $defaultCourse = $m[1] ?? 'CS303';

            try {
                $rows = XlsxParser::parseSheet($request->file('file'), $sName, 0);
            } catch (\Exception $e) {
                continue;
            }

            foreach ($rows as $ri => $row) {
                if ($ri < 2) continue; // skip blank row 0 and header row 1

                $id = trim($row[0] ?? '');
                if ($id === '' || strtoupper($id) === 'ID') continue;

                $projCode = trim($row[1] ?? '');
                if ($projCode === '') continue;

                $rawType    = strtolower(trim($row[3] ?? 'r'));
                $courseCode = strtoupper(trim($row[4] ?? $defaultCourse));
                $projNameTH = trim($row[5] ?? '');
                $memberCount = max(1, min(2, (int)(trim($row[19] ?? '1'))));

                $advisorCode  = trim($row[23] ?? '');
                $comm1Code    = trim($row[24] ?? '');
                $comm2Code    = trim($row[25] ?? '');
                $comm3Code    = trim($row[26] ?? '');
                $thaiDateRaw  = trim($row[28] ?? '');
                $examParsed   = $examMonth ? $this->parseThaiExamDate($thaiDateRaw, $examMonth) : null;
                $examDatetime = $examParsed['start'] ?? null;
                $examEndTime  = $examParsed['end']   ?? null;

                // Map project_type → student_type: s→s, r→r, m→rs
                $studentType = match($rawType) {
                    's'  => 's',
                    'r'  => 'r',
                    'm'  => 'rs',
                    default => 'r',
                };

                // Parse members using col[19] (จำนวนสมาชิก) as the loop bound
                $members = [];
                for ($mi = 0; $mi < $memberCount; $mi++) {
                    $base     = 7 + $mi * 6; // member 1 starts col 7, member 2 starts col 13
                    $prefix   = trim($row[$base + 0] ?? '');
                    $fullname = trim($row[$base + 1] ?? '');
                    $stdId    = trim($row[$base + 2] ?? '');
                    $email    = trim($row[$base + 3] ?? '');
                    $phone    = trim($row[$base + 4] ?? '');
                    $typeText = trim($row[$base + 5] ?? '');

                    if ($stdId === '') continue;

                    $parts = preg_split('/\s+/u', $fullname, 2);
                    $members[] = [
                        'prefix'        => $prefix,
                        'firstname_std' => $parts[0] ?? $fullname,
                        'lastname_std'  => $parts[1] ?? '-',
                        'username_std'  => $stdId,
                        'email_std'     => $email,
                        'phone'         => $phone,
                        'student_type'  => str_contains($typeText, 'พิเศษ') ? 's' : 'r',
                        'in_db'         => isset($existingStudents[$stdId]),
                    ];
                }

                // Validate — collect warnings (non-fatal) vs errors (fatal/skip)
                $warnings = [];
                $isNew = !isset($existingProjectCodes[$projCode]);

                if ($advisorCode && !isset($validUserCodes[strtolower($advisorCode)])) {
                    $warnings[] = "advisor '{$advisorCode}' ไม่พบในระบบ (จะข้ามการกำหนด)";
                }
                foreach ([
                    'comm1' => $comm1Code,
                    'comm2' => $comm2Code,
                    'comm3' => $comm3Code,
                ] as $role => $code) {
                    if ($code && !isset($validUserCodes[strtolower($code)])) {
                        $warnings[] = "{$role} '{$code}' ไม่พบในระบบ (จะข้าม)";
                    }
                }
                foreach ($members as $mem) {
                    if (!$mem['in_db']) {
                        $warnings[] = "นักศึกษา {$mem['username_std']} ยังไม่มีในระบบ (จะสร้างอัตโนมัติ)";
                    }
                }

                // Resolve actual user codes (only valid ones)
                $resolvedAdvisor = isset($validUserCodes[strtolower($advisorCode)]) ? $advisorCode : null;
                $resolvedComm1   = $comm1Code && isset($validUserCodes[strtolower($comm1Code)]) ? $comm1Code : null;
                $resolvedComm2   = $comm2Code && isset($validUserCodes[strtolower($comm2Code)]) ? $comm2Code : null;
                $resolvedComm3   = $comm3Code && isset($validUserCodes[strtolower($comm3Code)]) ? $comm3Code : null;

                if ($examDatetime === null && $thaiDateRaw !== '' && $examMonth) {
                    $warnings[] = "parse วันสอบไม่ได้: '{$thaiDateRaw}'";
                }

                $preview[] = [
                    'id'             => $id,
                    'exam_datetime'  => $examDatetime,
                    'exam_end_time'  => $examEndTime,
                    'thai_date_raw'  => $thaiDateRaw,
                    'project_code'   => $projCode,
                    'project_name'   => $projNameTH,
                    'course_code'    => $courseCode,
                    'raw_type'       => $rawType,
                    'student_type'   => $studentType,
                    'member_count'   => $memberCount,
                    'members'        => $members,
                    'advisor_code'   => $advisorCode,
                    'comm1_code'     => $comm1Code,
                    'comm2_code'     => $comm2Code,
                    'comm3_code'     => $comm3Code,
                    'resolved_adv'   => $resolvedAdvisor,
                    'resolved_comm1' => $resolvedComm1,
                    'resolved_comm2' => $resolvedComm2,
                    'resolved_comm3' => $resolvedComm3,
                    'warnings'       => $warnings,
                    'exists'         => !$isNew,
                    'sheet'          => $sName,
                ];
            }
        }

        if (empty($preview)) {
            return back()->with('error', 'ไม่พบข้อมูลโครงงานในไฟล์');
        }

        session([
            'project_excel_preview' => $preview,
            'project_import_semester' => $semester,
            'project_import_year'     => $year,
        ]);

        $newCount    = count(array_filter($preview, fn($r) => !$r['exists']));
        $existsCount = count(array_filter($preview, fn($r) => $r['exists']));
        $warnCount   = count(array_filter($preview, fn($r) => !empty($r['warnings']) && !$r['exists']));
        $dateCount   = count(array_filter($preview, fn($r) => !empty($r['exam_datetime'])));

        return view('admin.projects.import-excel', compact(
            'preview', 'newCount', 'existsCount', 'warnCount', 'dateCount', 'examMonth', 'semester', 'year'
        ));
    }

    public function confirm(Request $request)
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        $preview  = session('project_excel_preview', []);
        $semester = (int)session('project_import_semester', 2);
        $year     = (int)session('project_import_year', 2568);
        if (empty($preview)) {
            return redirect()->route('admin.projects.importExcel.form')
                ->with('error', 'ไม่พบข้อมูล Preview กรุณาอัปโหลดใหม่');
        }

        $newRows      = array_filter($preview, fn($r) => !$r['exists']);
        $existingRows = array_filter($preview, fn($r) =>  $r['exists']);
        $created  = 0;
        $updated  = 0;

        DB::beginTransaction();
        try {
            // Update exam times for existing projects
            foreach ($existingRows as $row) {
                if ($row['exam_datetime'] === null) continue;
                DB::table('projects')
                    ->where('project_code', $row['project_code'])
                    ->update([
                        'exam_datetime' => $row['exam_datetime'],
                        'exam_end_time' => $row['exam_end_time'] ?? null,
                        'updated_at'    => now(),
                    ]);
                $updated++;
            }

            foreach ($newRows as $row) {
                // 1. Create students that don't exist yet
                foreach ($row['members'] as $mem) {
                    if (!$mem['in_db']) {
                        DB::table('student')->insertOrIgnore([
                            'prefix_std'    => $mem['prefix'],
                            'username_std'  => $mem['username_std'],
                            'firstname_std' => $mem['firstname_std'],
                            'lastname_std'  => $mem['lastname_std'],
                            'email_std'     => $mem['email_std'],
                            'phone_std'     => $mem['phone'],
                            'password_std'  => Hash::make($mem['username_std']),
                            'role'          => 2048,
                            'course_code'   => $row['course_code'],
                            'student_type'  => $mem['student_type'],
                            'semester'      => 2,
                            'year'          => 2568,
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]);
                    }
                }

                // 2. Use semester/year from import form (not from project_code)

                // 3. Create group
                $groupId = DB::table('groups')->insertGetId([
                    'year'         => $year,
                    'semester'     => $semester,
                    'subject_code' => $row['course_code'],
                    'status_group' => 'approved',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                // 4. Add group members
                foreach ($row['members'] as $mem) {
                    DB::table('group_members')->insertOrIgnore([
                        'group_id'     => $groupId,
                        'username_std' => $mem['username_std'],
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }

                // 5. Create project
                $projectId = DB::table('projects')->insertGetId([
                    'group_id'       => $groupId,
                    'project_code'   => $row['project_code'],
                    'project_name'   => $row['project_name'],
                    'student_type'   => $row['student_type'],
                    'project_type'   => $row['raw_type'],
                    'exam_datetime'  => $row['exam_datetime'] ?? null,
                    'exam_end_time'  => $row['exam_end_time'] ?? null,
                    'status_project' => 'in_progress',
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                // 6. Assign lecturers (only resolved/valid codes)
                $lecturers = [
                    [$row['resolved_adv'],   1, 1],
                    [$row['resolved_comm1'], 2, 1],
                    [$row['resolved_comm2'], 2, 2],
                    [$row['resolved_comm3'], 2, 3],
                ];
                foreach ($lecturers as [$code, $relId, $sortOrder]) {
                    if ($code) {
                        DB::table('project_lecturers')->insertOrIgnore([
                            'project_id'      => $projectId,
                            'user_code'       => $code,
                            'relationship_id' => $relId,
                            'sort_order'      => $sortOrder,
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ]);
                    }
                }

                $created++;
            }
              DB::commit();

            UserActivityLog::create([
                'username'    => Session::get('username'),
                'user_type'   => 'user',
                'role'        => 'admin',

                'action'      => 'IMPORT_PROJECTS',
                'module'      => 'PROJECT',

                'target_type' => 'projects',
                'target_id'   => null,

                'description' => "Import {$created} projects, update {$updated} projects",

                'new_values'  => [
                    'created' => $created,
                    'updated' => $updated,
                    'semester' => $semester,
                    'year' => $year,
                ],

                'ip_address'  => request()->ip(),
                'user_agent'  => request()->userAgent(),
            ]);

            session()->forget(['project_excel_preview', 'project_import_semester', 'project_import_year']);

            $skippedExisting = count(array_filter($existingRows, fn($r) => $r['exam_datetime'] === null));
            $msg = "Import สำเร็จ: สร้างใหม่ {$created} โครงงาน";
            if ($updated)         $msg .= ", อัปเดตเวลาสอบ {$updated} โครงงาน";
            if ($skippedExisting) $msg .= " (ข้าม {$skippedExisting} ที่ไม่มีวันสอบใน Excel)";

            return redirect()->route('coordinator.projects.review')
                ->with('success', $msg);

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
}
    /**
     * Parse Thai exam date string to ['start' => 'YYYY-MM-DD HH:MM:SS', 'end' => 'YYYY-MM-DD HH:MM:SS'].
     * Input examples: "จ.18, 08:00 - 9.00"  "ศ. 22, 15:00 - 16:30"  "อ.19, 09.00 - 10:00"
     * baseYearMonth: "2025-08"
     */
    public static function parseThaiExamDate(string $raw, string $baseYearMonth): ?array
    {
        // Match day + start time + optional end time
        if (!preg_match('/\.\s*(\d+)\s*,\s*(\d{1,2})[\.:](\d{2})(?:\s*[-–]\s*(\d{1,2})[\.:](\d{2}))?/', trim($raw), $m)) {
            return null;
        }
        $day   = str_pad((int)$m[1], 2, '0', STR_PAD_LEFT);
        $sh    = str_pad((int)$m[2], 2, '0', STR_PAD_LEFT);
        $sm    = $m[3];
        $start = "{$baseYearMonth}-{$day} {$sh}:{$sm}:00";

        $end = null;
        if (!empty($m[4])) {
            $eh  = str_pad((int)$m[4], 2, '0', STR_PAD_LEFT);
            $em  = $m[5] ?? '00';
            $end = "{$baseYearMonth}-{$day} {$eh}:{$em}:00";
        }

        return ['start' => $start, 'end' => $end];
    }
}
