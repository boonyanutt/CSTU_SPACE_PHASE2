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
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes'    => 'รองรับเฉพาะ .xlsx / .xls',
            'file.max'      => 'ขนาดไฟล์ต้องไม่เกิน 20 MB',
        ]);

        try {
            $sheetNames = XlsxParser::sheetNames($request->file('file'));
        } catch (\Exception $e) {
            return back()->with('error', 'อ่านไฟล์ไม่ได้: ' . $e->getMessage());
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
            } catch (\Exception $e) {
                continue;
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

        $new = count(array_filter(
    $preview,
    fn($r) => $r['status'] === 'new'
));

$exists = count(array_filter(
    $preview,
    fn($r) => $r['status'] === 'exists'
));$exists = count(array_filter(
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

session(['student_excel_preview' => $preview]);

return view(
    'admin.students.import-excel',
    compact(
        'preview',
        'new',
        'exists',
        'gradeUpdates',
        'gradeMatches'
    )
);
    }

 public function importExcelConfirm(Request $request)
{
    $preview = session('student_excel_preview', []);

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

    $created = 0;
    $updated = 0;

    DB::beginTransaction();

    try {

        foreach ($newRows as $item) {
            DB::table('student')->insert([
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
                'semester'       => 2,
                'year'           => 2568,
                'grade_cs303'    => $item['grade_cs303'] ?? null,
            ]);

            $created++;
        }

        foreach ($gradeUpdateRows as $item) {
            DB::table('student')
                ->where('username_std', $item['username_std'])
                ->update([
                    'grade_cs303' => $item['grade_cs303'],
                ]);

            $updated++;
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
        $grade = strtoupper(trim($row[6] ?? ''));

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
