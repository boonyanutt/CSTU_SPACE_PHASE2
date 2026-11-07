<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use App\Helpers\PermissionHelper;
use App\Helpers\XlsxParser;
use App\Helpers\XlsxBuilder;

class StudentManagementController extends Controller
{
    /**
     * Show the form for creating a new student
     */
    public function create()
    {
        if (!PermissionHelper::canManageUsers()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์จัดการนักศึกษา');
        }

        return view('admin.students.create');
    }

    /**
     * Store a newly created student in database
     */
    public function store(Request $request)
    {
        if (!PermissionHelper::canManageUsers()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์จัดการนักศึกษา');
        }

        $request->validate([
            'username_std' => 'required|unique:student,username_std',
            'firstname_std' => 'required',
            'lastname_std' => 'required',
            'email_std' => 'required|email',
            'password_std' => 'required|min:6',
        ]);

        DB::table('student')->insert([
            'username_std' => $request->username_std,
            'firstname_std' => $request->firstname_std,
            'lastname_std' => $request->lastname_std,
            'email_std' => $request->email_std,
            'password_std' => Hash::make($request->password_std),
            'role' => 2048, // Student role_code
        ]);

        return redirect()->route('users.index')->with('success', 'เพิ่มนักศึกษาสำเร็จ');
    }

    /**
     * Show the form for editing the specified student
     */
    public function edit($id)
    {
        if (!PermissionHelper::canManageUsers()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์จัดการนักศึกษา');
        }

        $student = DB::table('student')->where('student_id', $id)->first();

        if (!$student) {
            return redirect()->route('users.index')->with('error', 'ไม่พบนักศึกษานี้');
        }

        return view('admin.students.edit', compact('student'));
    }

    /**
     * Update the specified student in database
     */
    public function update(Request $request, $id)
    {
        if (!PermissionHelper::canManageUsers()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์จัดการนักศึกษา');
        }

        $request->validate([
            'firstname_std'  => 'required',
            'lastname_std'   => 'required',
            'email_std'      => 'required|email',
            'course_code'    => 'required|in:CS303,CS403',
            'student_type'   => 'required|in:s,r',
            'semester'       => 'required|integer|between:1,2',
            'year'           => 'required|integer|min:2560|max:2600',
        ]);

        $updateData = [
            'firstname_std' => $request->firstname_std,
            'lastname_std'  => $request->lastname_std,
            'email_std'     => $request->email_std,
            'course_code'   => $request->course_code,
            'student_type'  => $request->student_type,
            'semester'      => $request->semester,
            'year'          => $request->year,
        ];

        // ถ้ามีการเปลี่ยนรหัสผ่าน
        if ($request->filled('password_std')) {
            $request->validate([
                'password_std' => 'min:6',
            ]);
            $updateData['password_std'] = Hash::make($request->password_std);
        }

        DB::table('student')
            ->where('student_id', $id)
            ->update($updateData);

        return redirect()->route('users.index')->with('success', 'แก้ไขนักศึกษาสำเร็จ');
    }

    /**
     * Remove the specified student from database
     */
    public function destroy($id)
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        DB::table('student')->where('student_id', $id)->delete();

        return redirect()->route('users.index')->with('success', 'ลบนักศึกษาสำเร็จ');
    }

    /**
     * Show import form
     */
    public function importForm()
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        return view('admin.students.import');
    }

    /**
     * Import students from CSV file
     */
    public function import(Request $request)
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');
        $path = $file->getRealPath();

        $csv = array_map('str_getcsv', file($path));

        // ตรวจสอบ header
        $header = array_shift($csv);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($csv as $index => $row) {
            // ข้ามแถวที่ว่าง
            if (empty(array_filter($row))) {
                continue;
            }

            // Map columns: username, firstname, lastname, email, password, course_code, semester, year
            $username = trim($row[0] ?? '');
            $firstname = trim($row[1] ?? '');
            $lastname = trim($row[2] ?? '');
            $email = trim($row[3] ?? '');
            $password = trim($row[4] ?? '');
            $courseCode = trim($row[5] ?? 'CS303');
            $semester = trim($row[6] ?? '2');
            $year = trim($row[7] ?? '2568');

            // Validate required fields
            if (empty($username) || empty($firstname) || empty($lastname) || empty($email) || empty($password)) {
                $errors[] = "แถวที่ " . ($index + 2) . ": ข้อมูลไม่ครบถ้วน";
                $skipped++;
                continue;
            }

            // Validate course_code
            if (!in_array($courseCode, ['CS303', 'CS403'])) {
                $errors[] = "แถวที่ " . ($index + 2) . ": รหัสวิชาต้องเป็น CS303 หรือ CS403";
                $skipped++;
                continue;
            }

            // Validate semester
            if (!in_array($semester, ['1', '2'])) {
                $errors[] = "แถวที่ " . ($index + 2) . ": เทอมต้องเป็น 1 หรือ 2";
                $skipped++;
                continue;
            }

            // ตรวจสอบว่า username ซ้ำหรือไม่
            $exists = DB::table('student')->where('username_std', $username)->exists();

            if ($exists) {
                $errors[] = "แถวที่ " . ($index + 2) . ": Username '{$username}' มีอยู่แล้ว";
                $skipped++;
                continue;
            }

            // Insert student
            try {
                DB::table('student')->insert([
                    'username_std' => $username,
                    'firstname_std' => $firstname,
                    'lastname_std' => $lastname,
                    'email_std' => $email,
                    'password_std' => Hash::make($password),
                    'role' => 2048, // Student role_code
                    'course_code' => $courseCode,
                    'semester' => (int)$semester,
                    'year' => (int)$year,
                ]);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "แถวที่ " . ($index + 2) . ": " . $e->getMessage();
                $skipped++;
            }
        }

        $message = "Import นักศึกษาสำเร็จ {$imported} รายการ";
        if ($skipped > 0) {
            $message .= ", ข้าม {$skipped} รายการ";
        }

        if (!empty($errors)) {
            Session::flash('import_errors', $errors);
        }

        return redirect()->route('users.index')->with('success', $message);
    }

    /**
     * Download CSV template
     */
    public function downloadTemplate()
    {
        // Coordinator, Admin, Staff สามารถดาวน์โหลดได้
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="student_import_template.csv"',
        ];

        $callback = function() {
            $file = fopen('php://output', 'w');

            // Header
            fputcsv($file, ['username', 'firstname', 'lastname', 'email', 'password', 'course_code', 'semester', 'year']);

            // ตัวอย่างข้อมูล
            fputcsv($file, ['6509650099', 'ทดสอบ', 'ระบบ', 'test.std@dome.tu.ac.th', 'testpass123', 'CS303', '2', '2568']);
            fputcsv($file, ['6509650098', 'ตัวอย่าง', 'นักศึกษา', 'example.std@dome.tu.ac.th', 'examplepass456', 'CS403', '1', '2568']);
            fputcsv($file, ['6509650097', 'สมมติ', 'ข้อมูล', 'sample.std@dome.tu.ac.th', 'samplepass789', 'CS303', '2', '2568']);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ──────────────────────────────────────────────────────────────
    // Import Students จาก Excel ต้นฉบับ (68-2_Projects format)
    // Admin only
    // ──────────────────────────────────────────────────────────────

    public function importExcelForm()
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

        return view('admin.students.import-excel');
    }

    public function importExcelPreview(Request $request)
    {
        if (!PermissionHelper::isAdmin()) {
            return redirect()->route('menu')->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }

            $request->validate([
             'file' => 'required|file|mimes:xlsx|max:20480',
            'import_year' => 'required|integer|between:2500,2700',
            'import_semester' => 'required|integer|in:1,2',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes'    => 'รองรับเฉพาะ .xlsx / .xls',
            'file.max'      => 'ขนาดไฟล์ต้องไม่เกิน 20 MB',
        ]);
        $importYear = (int) $request->input('import_year');
        $importSemester = (int) $request->input('import_semester');

        session([
         'student_excel_import_year' => $importYear,
        'student_excel_import_semester' => $importSemester,
        ]);

        try {
            $sheetNames = XlsxParser::sheetNames($request->file('file'));
        } catch (\Throwable $e) {
        return back()->with(
        'error',
        'อ่านไฟล์ไม่ได้: ' . $e->getMessage()
        . ' | ไฟล์: ' . basename($e->getFile())
        . ' | บรรทัด: ' . $e->getLine()
    );
}

        $studentSheets = array_filter($sheetNames, fn($n) => str_contains($n, '--Students'));
        if (empty($studentSheets)) {
            return back()->with('error',
                'ไม่พบ sheet ที่ชื่อลงท้ายด้วย "--Students" ในไฟล์นี้ กรุณาตรวจสอบ');
        }

        $preview = [];
        foreach ($studentSheets as $sName) {
            preg_match('/\b(CS\d+)\b/i', $sName, $m);
            $courseCode = $m[1] ?? 'CS303';

           try {
                 $rows = XlsxParser::parseSheet($request->file('file'), $sName, 0);
            } catch (\Throwable $e) {
    return back()->with(
        'error',
        'อ่านชีต ' . $sName . ' ไม่สำเร็จ: '
        . $e->getMessage()
        . ' | ไฟล์: ' . basename($e->getFile())
        . ' | บรรทัด: ' . $e->getLine()
    );
}

if (!is_iterable($rows)) {
    return back()->with('error', 'อ่านชีต ' . $sName . ' ไม่สำเร็จ: ตัวอ่าน Excel ไม่ส่งข้อมูลแถวกลับมา');
}

foreach ($rows as $row) {
    $studentId = trim($row[2] ?? '');
    if ($studentId === '') continue;
    $preview[] = $this->mapExcelStudentRow($row, $courseCode);
}
        }

        if (empty($preview)) {
            return back()->with('error', 'ไม่พบข้อมูลนักศึกษาในไฟล์');
        }

        $existingStudents = DB::table('student')
    ->whereIn('username_std', array_column($preview, 'username_std'))
    ->get()
    ->keyBy('username_std');

foreach ($preview as &$item) {
        // ตรวจนักศึกษาที่มาจากชีต CS403
    if ($item['course_code'] === 'CS403') {

        $username = $item['username_std'];
        $existing = $existingStudents->get($username);

        // ยังไม่มีข้อมูลนักศึกษาในระบบ
       if (!$existing) {
    // ไม่อนุญาตให้ลง CS403 หากยังไม่มีข้อมูล CS303 เดิม
    $item['status'] = 'cs403_not_eligible';
    $item['db_grade'] = null;
    continue;
}
        // ตรวจว่านักศึกษาอยู่ในกลุ่ม CS403 แล้วหรือยัง
$hasCS403Group = DB::table('group_members')
    ->join('groups', 'group_members.group_id', '=', 'groups.group_id')
    ->where('group_members.username_std', $username)
    ->where('groups.subject_code', 'CS403')
    ->exists();

// ถ้าเคยมีกลุ่ม CS403 แล้ว ไม่ให้เปลี่ยนผ่านซ้ำ
if ($hasCS403Group) {
    $item['status'] = 'cs403_already_enrolled';
    continue;
}
        // ใช้เกรด CS303 จากฐานข้อมูล ไม่ใช่จากชีต CS403
        $dbGrade = strtoupper(trim($existing->grade_cs303 ?? ''));
        $item['db_grade'] = $dbGrade !== '' ? $dbGrade : null;

        $passingGrades = ['A', 'B+', 'B', 'C+', 'C'];

        if (!in_array($dbGrade, $passingGrades, true)) {
            $item['status'] = 'cs403_not_eligible';
            continue;
        }

        // ค้นหากลุ่ม CS303 เดิม
        $oldGroup = DB::table('group_members')
            ->join('groups', 'group_members.group_id', '=', 'groups.group_id')
            ->where('group_members.username_std', $username)
            ->where('groups.subject_code', 'CS303')
            ->select('groups.group_id')
            ->first();

        if (!$oldGroup) {
            $item['status'] = 'cs403_no_old_group';
            continue;
        }

        // เก็บเลขกลุ่ม CS303 เพื่อใช้ตอนสร้างรายการต่อเนื่อง
        $item['old_group_id'] = $oldGroup->group_id;
        $item['status'] = 'cs403_candidate';

        continue;
    }

    $grade = $item['grade_cs303'] ?? null;

    // เกรดใน Excel ไม่ถูกต้อง
    if (!$this->isValidCS303Grade($grade)) {
        $item['status'] = 'invalid_grade';
        continue;
    }

    $existing = $existingStudents->get($item['username_std']);

    // นักศึกษาใหม่
    if (!$existing) {
        $item['status'] = 'new';
        continue;
    }

    // มีนักศึกษาอยู่แล้ว
    $dbGrade = $existing->grade_cs303;

    // DB ยังไม่มีเกรด แต่ Excel มี
    if (!$dbGrade && $grade) {
        $item['status'] = 'grade_update';
        continue;
    }

    // DB และ Excel มีเกรดเหมือนกัน
    if ($dbGrade && $grade && $dbGrade === $grade) {
        $item['status'] = 'grade_match';
        continue;
    }

    // DB กับ Excel มีเกรดไม่ตรงกัน
    if ($dbGrade && $grade && $dbGrade !== $grade) {
        $item['status'] = 'grade_conflict';
        $item['db_grade'] = $dbGrade;
        continue;
    }

    $item['status'] = 'exists';
}

unset($item);
    // รายชื่อนักศึกษาที่ปรากฏในชีต CS403
$cs403Usernames = array_column(
    array_filter(
        $preview,
        fn($row) => $row['course_code'] === 'CS403'
    ),
    'username_std'
);

// ตรวจสมาชิกของกลุ่ม CS303 เดิมให้ครบ
foreach ($preview as &$item) {

    if ($item['status'] !== 'cs403_candidate') {
        continue;
    }

    $oldGroupId = $item['old_group_id'];

    $oldMembers = DB::table('group_members')
        ->where('group_id', $oldGroupId)
        ->pluck('username_std')
        ->toArray();

    // ตรวจว่าสมาชิกทุกคนอยู่ในชีต CS403 หรือไม่
    $allMembersInCS403 = empty(
        array_diff($oldMembers, $cs403Usernames)
    );
    // ดึงเกรด CS303 ของสมาชิกทุกคนจากฐานข้อมูล
$memberGrades = DB::table('student')
    ->whereIn('username_std', $oldMembers)
    ->pluck('grade_cs303', 'username_std');

// เกรดที่ผ่าน CS303
$passingGrades = ['A', 'B+', 'B', 'C+', 'C'];

// ต้องมีข้อมูลเกรดครบทุกคน
$allMembersPassed = count($memberGrades) === count($oldMembers);

foreach ($oldMembers as $memberUsername) {

    $grade = strtoupper(trim($memberGrades[$memberUsername] ?? ''));

    if (!in_array($grade, $passingGrades, true)) {
        $allMembersPassed = false;
        break;
    }
}

    if ($allMembersInCS403 && $allMembersPassed) {
        $item['status'] = 'cs403_ready';
    } else {
        $item['status'] = 'cs403_incomplete_group';
    }
}

unset($item);

        $new = count(array_filter(
    $preview,
    fn($r) => $r['status'] === 'new'
));

$exists = count(array_filter(
    $preview,
    fn($r) => in_array(
        $r['status'],
        ['exists', 'grade_match'],
        true
    )
));

$gradeUpdates = count(array_filter(
    $preview,
    fn($r) => $r['status'] === 'grade_update'
));

$gradeMatches = count(array_filter(
    $preview,
    fn($r) => $r['status'] === 'grade_match'
));

$cs403Ready = count(array_filter(
    $preview,
    fn($r) => $r['status'] === 'cs403_ready'
));

session(['student_excel_preview' => $preview]);

return view(
    'admin.students.import-excel',
    compact(
    'preview',
    'new',
    'exists',
    'gradeUpdates',
    'gradeMatches',
    'cs403Ready'
)
);
    }

 public function importExcelConfirm(Request $request)
{
   // อนุญาตเฉพาะผู้ดูแลระบบ
    if (!PermissionHelper::isAdmin()) {
        return redirect()->route('menu')
            ->with('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    $preview = session('student_excel_preview', []);
    // อ่านปีการศึกษาและภาคเรียนที่เลือกจากหน้า Import
    $importYear = session('student_excel_import_year');
    $importSemester = session('student_excel_import_semester');

    // ตรวจว่ามีข้อมูลปีและเทอมครบหรือไม่
    if ($importYear === null || $importSemester === null) {
    return redirect()->route('students.importExcelForm')
        ->with('error', 'ไม่พบปีการศึกษาหรือภาคเรียน กรุณาอัปโหลดใหม่');
}

    // แปลงเป็นจำนวนเต็ม
    $importYear = (int) $importYear;
    $importSemester = (int) $importSemester;
    if (empty($preview)) {
        return redirect()->route('students.importExcelForm')
            ->with('error', 'ไม่พบข้อมูล preview กรุณาอัปโหลดใหม่');
    }

    $newRows = array_filter(
        $preview,
        fn($r) => $r['status'] === 'new'
    );

    $gradeUpdateRows = array_filter(
        $preview,
        fn($r) => $r['status'] === 'grade_update'
    );
    // นักศึกษาที่พร้อมเปลี่ยนผ่าน CS303 -> CS403
$cs403ReadyRows = array_filter(
    $preview,
    fn($r) => $r['status'] === 'cs403_ready'
);

// รวมสมาชิกตามกลุ่ม CS303 เดิม
$cs403Groups = [];

foreach ($cs403ReadyRows as $item) {
    $oldGroupId = $item['old_group_id'];

    $cs403Groups[$oldGroupId][] = $item['username_std'];
}

// ค้นหารายวิชา CS403 ตามปีและเทอมที่เลือก Import
$cs403Term = DB::table('subjects')
    ->where('subject_code', 'CS403')
    ->where('year', $importYear)
    ->where('semester', $importSemester)
    ->first();

// ตรวจว่ามีรายวิชา CS403 ในปีและเทอมนี้หรือไม่
if (!empty($cs403Groups) && !$cs403Term) {
    return back()->with(
        'error',
        "ไม่พบรายวิชา CS403 ปี {$importYear} ภาคเรียน {$importSemester} ในระบบ"
    );
}
    $created = 0;
    $updated = 0;

    DB::beginTransaction();

    try {

        foreach ($newRows as $item) {
            // ป้องกันการเพิ่มนักศึกษาใหม่เป็น CS403 โดยตรง
        if ($item['course_code'] === 'CS403') {
            throw new \Exception(
        "นักศึกษา {$item['username_std']} ยังไม่มีประวัติ CS303 ในระบบ"
    );
}
            $studentId = DB::table('student')->insertGetId([
                'prefix_std'     => $item['prefix'],
                'username_std'   => $item['username_std'],
                'firstname_std'  => $item['firstname_std'],
                'lastname_std'   => $item['lastname_std'],
                'email_std'      => $item['email_std'],
                'phone_std'      => $item['phone'],
                'password_std'   => Hash::make($item['password']),
                'role'           => 2048,
                'course_code'    => $item['course_code'],
                'student_type'   => $item['student_type'],
                'semester' => $importSemester,
                'year' => $importYear,
                'grade_cs303'    => $item['grade_cs303'] ?? null,
                    ]);

        // บันทึกประวัติการลงทะเบียนของนักศึกษาใหม่
        DB::table('student_enrollments')->insert([
        'student_id'   => $studentId,
        'subject_code' => $item['course_code'],
        'year'         => $importYear,
        'semester'     => $importSemester,
        'grade'        => $item['course_code'] === 'CS303'
            ? ($item['grade_cs303'] ?? null)
            : null,
        'project_id'   => null,
        'created_at'   => now(),
        'updated_at'   => now(),
        ]);

        $created++;
        }

       foreach ($gradeUpdateRows as $item) {

    // 1. ค้นหานักศึกษาเดิม
    $student = DB::table('student')
        ->where('username_std', $item['username_std'])
        ->first();

    if (!$student) {
        throw new \Exception(
            "ไม่พบนักศึกษา {$item['username_std']}"
        );
    }

    // 2. บันทึกเกรดในตารางเดิม เพื่อให้ระบบ Phase 1 ยังใช้งานได้
    DB::table('student')
        ->where('student_id', $student->student_id)
        ->update([
            'grade_cs303' => $item['grade_cs303'],
        ]);

    // 3. บันทึกหรืออัปเดตประวัติของปีและเทอมที่ Import
    DB::table('student_enrollments')->updateOrInsert(
        [
            'student_id' => $student->student_id,
            'subject_code' => 'CS303',
            'year' => $importYear,
            'semester' => $importSemester,
        ],
        [
            'grade' => $item['grade_cs303'],
            'updated_at' => now(),
        ]
    );

    $updated++;
}

        // เปลี่ยนผ่านกลุ่ม CS303 -> CS403
$transitionedGroups = 0;

$cs403Roster = array_values(array_unique(array_map(
    'strval',
    array_column(
        array_filter(
            $preview,
            fn($row) => $row['course_code'] === 'CS403'
        ),
        'username_std'
    )
)));

foreach ($cs403Groups as $oldGroupId => $previewMembers) {

    // 1. ตรวจกลุ่ม CS303 เดิม
    $oldGroup = DB::table('groups')
        ->where('group_id', $oldGroupId)
        ->where('subject_code', 'CS303')
        ->lockForUpdate()
        ->first();

    if (!$oldGroup || $oldGroup->status_group !== 'approved') {
        throw new \Exception("กลุ่ม {$oldGroupId} ยังไม่พร้อมเปลี่ยนผ่าน");
    }

    // 2. ตรวจโครงงานเดิม
    $oldProject = DB::table('projects')
        ->where('group_id', $oldGroupId)
        ->lockForUpdate()
        ->first();

    if (!$oldProject) {
        throw new \Exception("ไม่พบโครงงานของกลุ่ม {$oldGroupId}");
    }

   // 3. ตรวจว่ามีกลุ่ม CS403 ที่ใช้ Project เดิมแล้วหรือยัง
$existingGroup = DB::table('groups')
    ->where('project_id', $oldProject->project_id)
    ->where('subject_code', 'CS403')
    ->exists();

if ($existingGroup) {
    throw new \Exception(
        "โครงงาน {$oldProject->project_id} มีกลุ่ม CS403 แล้ว"
    );
}

    // 4. ตรวจสมาชิกเดิมทั้งหมด
    $oldMembers = DB::table('group_members')
        ->where('group_id', $oldGroupId)
        ->pluck('username_std')
        ->map(fn($username) => (string) $username)
        ->toArray();

    if (empty($oldMembers)) {
        throw new \Exception("กลุ่ม {$oldGroupId} ไม่มีสมาชิก");
    }

    // ต้องมีรายชื่อสมาชิกทุกคนใน Excel CS403
    if (!empty(array_diff($oldMembers, $cs403Roster))) {
        throw new \Exception(
            "รายชื่อสมาชิกกลุ่ม {$oldGroupId} ใน Excel CS403 ไม่ครบ"
        );
    }

    // ตรวจว่ารายชื่อที่ Preview ระบุไว้ตรงกับสมาชิกจริง
    if (
        !empty(array_diff($oldMembers, $previewMembers)) ||
        !empty(array_diff($previewMembers, $oldMembers))
    ) {
        throw new \Exception(
            "ข้อมูลสมาชิกกลุ่ม {$oldGroupId} เปลี่ยนแปลง กรุณา Preview ใหม่"
        );
    }

    // 5. ตรวจนักศึกษาและเกรดทุกคนจาก DB อีกครั้ง
    $students = DB::table('student')
        ->whereIn('username_std', $oldMembers)
        ->lockForUpdate()
        ->get()
        ->keyBy('username_std');

    if ($students->count() !== count($oldMembers)) {
        throw new \Exception("ข้อมูลนักศึกษากลุ่ม {$oldGroupId} ไม่ครบ");
    }

    foreach ($oldMembers as $username) {

        $student = $students->get($username);
        $grade = strtoupper(trim($student->grade_cs303 ?? ''));

        if (!in_array($grade, ['A', 'B+', 'B', 'C+', 'C'], true)) {
            throw new \Exception(
                "นักศึกษา {$username} ยังไม่ผ่าน CS303"
            );
        }

        // ป้องกันนักศึกษาที่มีกลุ่ม CS403 อยู่แล้ว
        $hasCS403Group = DB::table('group_members')
            ->join('groups', 'group_members.group_id', '=', 'groups.group_id')
            ->where('group_members.username_std', $username)
            ->where('groups.subject_code', 'CS403')
            ->exists();

        if ($hasCS403Group) {
            throw new \Exception(
                "นักศึกษา {$username} มีกลุ่ม CS403 อยู่แล้ว"
            );
        }
    }

    // 6. สร้างกลุ่ม CS403 ใหม่
    $newGroupId = DB::table('groups')->insertGetId([
        'year' => $importYear,
        'semester' => $importSemester,
        'subject_code' => 'CS403',
        'status_group' => 'approved',
        'project_id' => $oldProject->project_id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ], 'group_id');

    // 7. คัดลอกสมาชิกเดิม โดยรอให้แต่ละคนยืนยันการทำต่อ
    foreach ($oldMembers as $username) {
        DB::table('group_members')->insert([
            'group_id'                  => $newGroupId,
            'username_std'             => $username,
            'topic2_confirmation_status' => 'pending',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }
        // บันทึกประวัติ CS403 โดยใช้ Project เดิม
// บันทึกประวัติ CS403 โดยใช้ Project เดิม
foreach ($oldMembers as $username) {

    // ดึงข้อมูลนักศึกษาจากสมาชิกกลุ่มเดิม
    $student = $students->get($username);

    // ตรวจว่ามีประวัติรายวิชานี้ในปี/เทอมนี้หรือยัง
    $enrollment = DB::table('student_enrollments')
        ->where('student_id', $student->student_id)
        ->where('subject_code', 'CS403')
        ->where('year', $importYear)
        ->where('semester', $importSemester)
        ->first();

    if ($enrollment) {

        // มีประวัติแล้ว: อัปเดตเฉพาะ Project และเวลาแก้ไข
        DB::table('student_enrollments')
            ->where('enrollment_id', $enrollment->enrollment_id)
            ->update([
                'project_id' => $oldProject->project_id,
                'updated_at' => now(),
            ]);

    } else {

        // ยังไม่มีประวัติ: เพิ่มรายการใหม่
        DB::table('student_enrollments')->insert([
            'student_id'   => $student->student_id,
            'subject_code' => 'CS403',
            'year'         => $importYear,
            'semester'     => $importSemester,
            'grade'        => null,
            'project_id'   => $oldProject->project_id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}

    // 8. สร้างโครงงาน CS403 ที่เชื่อมกับโครงงานเดิม
   /*
DB::table('projects')->insert([
    'group_id'          => $newGroupId,
    'project_name'      => $oldProject->project_name,
    'project_code'      => 'CS403-CONT-' . $oldProject->project_id,
    'student_type'      => $oldProject->student_type,
    'status_project'    => 'in_progress',
    'project_type'      => $oldProject->project_type,
    'parent_project_id' => $oldProject->project_id,
    'created_at'        => now(),
    'updated_at'        => now(),
]);
*/
// เก็บประวัติ CS303 เดิม ก่อนเปลี่ยนเป็น CS403
foreach ($oldMembers as $username) {

    $student = $students->get($username);

    // ตรวจว่ามีประวัติ CS303 ในปีและภาคเรียนนี้แล้วหรือยัง
    $existingEnrollment = DB::table('student_enrollments')
        ->where('student_id', $student->student_id)
        ->where('subject_code', 'CS303')
        ->where('year', $oldGroup->year)
        ->where('semester', $oldGroup->semester)
        ->first();

    // ถ้ายังไม่มีประวัติ จึงเพิ่มข้อมูลใหม่
    if (!$existingEnrollment) {

        DB::table('student_enrollments')->insert([
            'student_id' => $student->student_id,
            'subject_code' => 'CS303',
            'year' => $oldGroup->year,
            'semester' => $oldGroup->semester,
            'grade' => $student->grade_cs303,
            'project_id' => $oldProject->project_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
    // 9. เปลี่ยนวิชาปัจจุบันของสมาชิกเป็น CS403
    DB::table('student')
    ->whereIn('username_std', $oldMembers)
    ->update([
        'course_code' => 'CS403',
        'year' => $importYear,
        'semester' => $importSemester,
    ]);

    $transitionedGroups++;
}
        DB::commit();

    } catch (\Exception $e) {
        DB::rollBack();

        return back()->with(
            'error',
            'เกิดข้อผิดพลาด: ' . $e->getMessage()
        );
    }

    session()->forget('student_excel_preview');

    return redirect()->route('users.index')
        ->with(
            'success',
            "นำเข้าสำเร็จ: เพิ่มนักศึกษา {$created} คน, อัปเดตเกรด {$updated} คน"
        );
}

    private function mapExcelStudentRow(array $row, string $courseCode): array
    {
        // col 0: prefix | col 1: fullname   | col 2: student_id
        // col 3: email  | col 4: phone      | col 5: course_type text
        $fullname = trim($row[1] ?? '');
        $parts    = preg_split('/\s+/u', $fullname, 2);

        $username       = trim($row[2] ?? '');
        $courseTypeText = trim($row[5] ?? '');
        $studentType    = str_contains($courseTypeText, 'พิเศษ') ? 's' : 'r';
        $password       = trim($row[4] ?? ''); // เบอร์โทร = password เริ่มต้น
        if ($password === '') $password = $username;
        // อ่านเกรดเฉพาะชีต CS303
        $grade = $courseCode === 'CS303'
         ? strtoupper(trim($row[6] ?? ''))
    : '';

        if ($grade === '') {
         $grade = null;
}
        return [
            'prefix'           => trim($row[0] ?? ''),
            'firstname_std'    => $parts[0] ?? $fullname,
            'lastname_std'     => $parts[1] ?? '-',
            'username_std'     => $username,
            'email_std'        => trim($row[3] ?? ''),
            'phone'            => trim($row[4] ?? ''),
            'password'         => $password,
            'course_code'      => $courseCode,
            'student_type'     => $studentType,
            'course_type_text' => $courseTypeText,
            'grade_cs303'      => $grade,
            'status'           => 'new',
        ];
    }
    private function isValidCS303Grade(?string $grade): bool
{
    if ($grade === null) {
        return true;
    }

    return in_array(
        $grade,
        ['A', 'B+', 'B', 'C+', 'C', 'D+', 'D', 'F'],
        true
    );
}
    /**
     * Export all students to Excel
     */
    public function exportAll()
    {
        $students = DB::table('student')
            ->select('student_id', 'prefix_std', 'username_std', 'firstname_std', 'lastname_std',
                     'email_std', 'phone_std', 'course_code', 'student_type', 'semester', 'year')
            ->orderBy('student_id')
            ->get();

        $header = ['prefix', 'fullname', 'username_std', 'email', 'phone', 'course_type', 'password'];
        $rows   = [$header];
        foreach ($students as $s) {
            $rows[] = [
                $s->prefix_std    ?? '',
                trim(($s->firstname_std ?? '') . ' ' . ($s->lastname_std ?? '')),
                $s->username_std  ?? '',
                $s->email_std     ?? '',
                $s->phone_std     ?? '',
                $s->student_type === 's' ? 'โครงการปริญญาตรีภาคพิเศษ' : 'โครงการปกติ',
                '',
            ];
        }

        return XlsxBuilder::download('students_export_' . date('Y-m-d_His') . '.xlsx', ['Students' => $rows]);
    }
}
