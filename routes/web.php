<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\StudentManagementController;
use App\Http\Controllers\AdminLogController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\PermissionTestController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupInvitationController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\CoordinatorUserController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\AdminSubmissionController;
use App\Http\Controllers\CoordinatorSubmissionController;
use App\Http\Controllers\StaffSubmissionController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\LecturerSubmissionController;
use App\Http\Controllers\ExamScheduleController;
use App\Http\Controllers\SubjectSummaryController;
use App\Http\Controllers\AdminActivityLogController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// ======================================
// Authentication Routes
// ======================================

// Login page (named so controllers can redirect to the login route)
Route::get('/', [AuthController::class, 'showLoginForm'])->name('login');
Route::get('login', [AuthController::class, 'showLoginForm'])->name('login.page');
Route::post('login', [AuthController::class, 'login'])->name('login.submit');

// Password reset routes
Route::get('password/reset', [PasswordResetController::class, 'showVerifyForm'])->name('password.reset');
Route::post('password/verify', [PasswordResetController::class, 'verify'])->name('password.verify');
Route::get('password/new', [PasswordResetController::class, 'showNewPasswordForm'])->name('password.new');
Route::post('password/update', [PasswordResetController::class, 'update'])->name('password.update');

// Logout routes
Route::get('logout', [AuthController::class, 'logout'])->name('logout');
Route::post('logout-beacon', [AuthController::class, 'logoutBeacon'])->name('logout-beacon');

// Session management
Route::post('refresh-session', [AuthController::class, 'refreshSession'])->name('refresh-session');

// ======================================
// Student Routes (Protected by student auth)
// ======================================
Route::middleware(['auth:student', 'check.subject.access', 'session.timeout'])->group(function () {
    
    // Student Menu/Dashboard
    Route::get('/student/menu', [StudentController::class, 'menu'])->name('student.menu');
    Route::get('/student/dashboard', [StudentController::class, 'dashboard'])->name('student.dashboard');
    Route::get('/student/debug', function() {
        $student = Auth::guard('student')->user();
        $myGroup = $student->groups()->with(['members.student', 'latestProposal.lecturer'])->first();
        $pendingInvitations = $student->pendingInvitations()->with(['group', 'inviter'])->orderBy('created_at', 'desc')->get();
        $isGroupLeader = false;
        if ($myGroup) {
            $firstMember = $myGroup->members()->orderBy('groupmem_id', 'asc')->first();
            $isGroupLeader = $firstMember && $firstMember->username_std === $student->username_std;
        }
        return view('student.debug', compact('student', 'myGroup', 'pendingInvitations', 'isGroupLeader'));
    })->name('student.debug');
    
    // Group Management Routes
    Route::prefix('groups')->name('groups.')->group(function () {
    Route::get('create', [GroupController::class, 'create'])->name('create');
    Route::post('store', [GroupController::class, 'store'])->name('store');

    Route::post('{group}/topic2/confirm', [GroupController::class, 'confirmTopic2'])
        ->name('topic2.confirm');

    Route::post('{group}/topic2/reject', [GroupController::class, 'rejectTopic2'])
        ->name('topic2.reject');

    Route::get('{group}', [GroupController::class, 'show'])->name('show');
    Route::get('search/students', [GroupController::class, 'searchStudents'])->name('search-students');
    Route::post('leave', [GroupController::class, 'leaveGroup'])->name('leave');
});
    
    // Group Invitation Management Routes
    Route::prefix('invitations')->name('invitations.')->group(function () {
        Route::get('/', [GroupInvitationController::class, 'index'])->name('index');
        Route::post('/', [GroupInvitationController::class, 'store'])->name('store');
        Route::post('{invitation}/accept', [GroupInvitationController::class, 'accept'])->name('accept');
        Route::post('{invitation}/decline', [GroupInvitationController::class, 'decline'])->name('decline');
        Route::delete('{invitation}/cancel', [GroupInvitationController::class, 'cancel'])->name('cancel');
    });
    
    // Project Proposal Routes (Group Leaders only)
    Route::prefix('proposals')->name('proposals.')->group(function () {
        Route::get('groups/{group}/create', [ProposalController::class, 'create'])->name('create');
        Route::post('groups/{group}', [ProposalController::class, 'store'])->name('store');
    });
    
    // Submission Routes (PDF Report Upload)
    Route::prefix('submission')->name('student.submission.')->group(function () {
        Route::get('/', [SubmissionController::class, 'showUploadForm'])->name('form');
        Route::post('/{project}/upload', [SubmissionController::class, 'upload'])->name('upload');
        Route::get('/{project}/download', [SubmissionController::class, 'download'])->name('download');
    });
    

});

// ======================================
// Protected Routes
// ======================================

// Protected routes with session timeout middleware
Route::middleware('session.timeout')->group(function () {
    
    // ======================================
    // Coordinator Routes
    // ======================================
    Route::prefix('coordinator')->name('coordinator.')->middleware('role:coordinator,admin,staff')->group(function () {
        Route::get('/', function() { return redirect()->route('coordinator.dashboard'); })->name('menu');
        Route::get('dashboard', [CoordinatorController::class, 'dashboard'])->name('dashboard');
        
        // Exam Schedules Management (Full Access for Coordinator & Staff)
        Route::prefix('exam-schedules')->name('exam-schedules.')->middleware('role:staff,coordinator,admin')->group(function () {
            Route::get('/', [ExamScheduleController::class, 'coordinatorExamScheduleIndex'])->name('index');
            Route::get('calendar', [ExamScheduleController::class, 'coordinatorExamScheduleCalendar'])->name('calendar');
            Route::get('create', [ExamScheduleController::class, 'coordinatorExamScheduleCreate'])->name('create');
            Route::post('/', [ExamScheduleController::class, 'coordinatorExamScheduleStore'])->name('store');
            Route::get('{id}/edit', [ExamScheduleController::class, 'coordinatorExamScheduleEdit'])->name('edit');
            Route::put('{id}', [ExamScheduleController::class, 'coordinatorExamScheduleUpdate'])->name('update');
            Route::delete('{id}', [ExamScheduleController::class, 'coordinatorExamScheduleDestroy'])->name('destroy');
        });
        
        Route::prefix('groups')->name('groups.')->group(function () {
            Route::get('/', [CoordinatorController::class, 'groups'])->name('index');
            Route::get('{id}', [CoordinatorController::class, 'groupShow'])->name('show');
            Route::post('{id}/approve', [CoordinatorController::class, 'approveGroup'])->name('approve');
        });
        
        Route::prefix('projects')->name('projects.')->group(function () {
            Route::put('{id}', [CoordinatorController::class, 'updateProject'])->name('update');
            Route::get('export/csv', [CoordinatorController::class, 'exportCsv'])->name('export.csv');
            Route::get('review', [CoordinatorController::class, 'projectsReview'])->name('review');
            Route::put('review/{projectId}', [CoordinatorController::class, 'updateProjectReview'])->name('update-review');
        });
        
        Route::prefix('proposals')->name('proposals.')->group(function () {
            Route::get('/', [ProposalController::class, 'coordinatorIndex'])->name('index');
        });
        
        // Submission Download for Coordinator/Staff
        Route::get('submission/{project}/download', [SubmissionController::class, 'download'])->name('submission.download');
        
        // Schedule & Committee Assignment (Full Access for Coordinator & Staff)
        Route::prefix('schedules')->name('schedules.')->middleware('role:staff,coordinator,admin')->group(function () {
            Route::get('/', [CoordinatorController::class, 'schedulesIndex'])->name('index');
            Route::get('import', [CoordinatorController::class, 'scheduleImportForm'])->name('import.form');
            Route::post('import', [CoordinatorController::class, 'scheduleImportPreview'])->name('import.preview');
            Route::post('import/confirm', [CoordinatorController::class, 'scheduleImportConfirm'])->name('import.confirm');
            Route::get('import/template', [CoordinatorController::class, 'scheduleImportTemplate'])->name('import.template');
            Route::get('{project}/edit', [CoordinatorController::class, 'scheduleEdit'])->name('edit');
            Route::put('{project}', [CoordinatorController::class, 'scheduleUpdate'])->name('update');
        });
        
        // Evaluation
        Route::prefix('evaluations')->name('evaluations.')->group(function () {
            Route::get('/', [CoordinatorController::class, 'evaluationsIndex'])->name('index');
            Route::get('export', [CoordinatorController::class, 'exportScores'])->name('export');
            Route::get('export-preview', [CoordinatorController::class, 'exportPreview'])->name('export-preview');
            Route::get('export-data', [CoordinatorController::class, 'exportData'])->name('export-data');
            Route::get('{project}/scores', [CoordinatorController::class, 'viewScores'])->name('scores');
        });
        
        // Submissions Management
        Route::prefix('submissions')->name('submissions.')->group(function () {
            Route::get('/', [CoordinatorSubmissionController::class, 'index'])->name('index');
            Route::get('{project_id}', [CoordinatorSubmissionController::class, 'show'])->name('show');
            Route::get('{project_id}/download', [CoordinatorSubmissionController::class, 'download'])->name('download');
        });
        
        Route::get('settings', [CoordinatorController::class, 'settings'])->name('settings');

        // Subject Summary (import / export)
        Route::prefix('subject-summary')->name('subject-summary.')->group(function () {
            Route::get('/',               [SubjectSummaryController::class, 'index'])->name('index');
            Route::get('export',          [SubjectSummaryController::class, 'export'])->name('export');
            Route::get('template',        [SubjectSummaryController::class, 'template'])->name('template');
            Route::get('import',          [SubjectSummaryController::class, 'importForm'])->name('import.form');
            Route::post('import/preview', [SubjectSummaryController::class, 'importPreview'])->name('import.preview');
            Route::post('import/confirm', [SubjectSummaryController::class, 'importConfirm'])->name('import.confirm');
        });
    });
    
    // ======================================
    // Lecturer Routes
    // ======================================
    Route::prefix('lecturer')->name('lecturer.')->middleware('role:lecturer,admin')->group(function () {
        // Menu/Dashboard
        Route::get('/', function() { return view('lecturer.menu'); })->name('menu');
        Route::get('/dashboard', [App\Http\Controllers\LecturerController::class, 'dashboard'])->name('dashboard');
        
        // My Projects
        Route::get('/projects', [App\Http\Controllers\LecturerController::class, 'myProjects'])->name('projects.index');
        
        Route::prefix('proposals')->name('proposals.')->group(function () {
            Route::get('/', [ProposalController::class, 'lecturerIndex'])->name('index');
            Route::get('{proposal}', [ProposalController::class, 'show'])->name('show');
            Route::post('{proposal}/approve', [ProposalController::class, 'approve'])->name('approve');
            Route::post('{proposal}/reject', [ProposalController::class, 'reject'])->name('reject');
        });
        
        // Submission Download for Lecturer
        Route::get('submission/{project}/download', [SubmissionController::class, 'download'])->name('submission.download');
        
        // Evaluation
        Route::prefix('evaluations')->name('evaluations.')->group(function () {
            Route::get('/', [App\Http\Controllers\LecturerController::class, 'evaluationsIndex'])->name('index');
            Route::get('{project}/evaluate', [App\Http\Controllers\LecturerController::class, 'evaluateForm'])->name('form');
            Route::post('{project}/evaluate', [App\Http\Controllers\LecturerController::class, 'submitEvaluation'])->name('submit');
            Route::get('{project}/export', [App\Http\Controllers\LecturerController::class, 'exportEvaluation'])->name('export');
            Route::get('export-all', [App\Http\Controllers\LecturerController::class, 'exportAll'])->name('export-all');
        });

        // Submissions Management
        Route::prefix('submissions')->name('submissions.')->group(function () {
            Route::get('/', [LecturerSubmissionController::class, 'index'])->name('index');
            Route::get('{project_id}', [LecturerSubmissionController::class, 'show'])->name('show');
            Route::get('{project_id}/download', [LecturerSubmissionController::class, 'download'])->name('download');
        });
    });
    
    // ======================================
    // Staff Routes (Submissions only)
    // ======================================
    Route::middleware(['role:staff,coordinator,admin'])->prefix('staff')->name('staff.')->group(function () {
        
        // Submissions Management
        Route::prefix('submissions')->name('submissions.')->group(function () {
            Route::get('/', [StaffSubmissionController::class, 'index'])->name('index');
            Route::get('{project_id}', [StaffSubmissionController::class, 'show'])->name('show');
            Route::get('{project_id}/download', [StaffSubmissionController::class, 'download'])->name('download');
        });
        
    });

    // ======================================
    // Main Menu
    // ======================================
    Route::get('menu', [MenuController::class, 'index'])->name('menu');

    // ======================================
    // User Management (Admin/Coordinator Only)
    // ======================================
    Route::prefix('users')->name('users.')->middleware('role:coordinator,staff,admin')->group(function () {
        // View list - Coordinator, Admin can view
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        
        // Create, Edit, Delete
        Route::get('create', [UserManagementController::class, 'create'])->name('create');
        Route::post('/', [UserManagementController::class, 'store'])->name('store');
        Route::get('{user}/edit', [UserManagementController::class, 'edit'])->name('edit');
        Route::put('{user}', [UserManagementController::class, 'update'])->name('update');
        Route::delete('{user}', [UserManagementController::class, 'destroy'])->name('destroy');
        
        // Import/Export (XLSX)
        Route::get('import/form',    [UserManagementController::class, 'importForm'])->name('importForm');
        Route::post('import/preview',[UserManagementController::class, 'importPreview'])->name('importPreview');
        Route::get('import/preview', fn() => redirect()->route('users.importForm'))->name('importPreview.get');
        Route::post('import/confirm',[UserManagementController::class, 'importConfirm'])->name('importConfirm');
        Route::get('import/confirm', fn() => redirect()->route('users.importForm'))->name('importConfirm.get');
        Route::get('export/all',     [UserManagementController::class, 'exportAll'])->name('exportAll');
        
        // View details
        Route::get('{user}', [UserManagementController::class, 'show'])->name('show');
    });

    // ======================================
    // Student Management (Admin/Coordinator Only)
    // ======================================
    Route::prefix('students')->name('students.')->middleware('role:coordinator,admin')->group(function () {
        // Create, Edit, Delete
        Route::get('create', [StudentManagementController::class, 'create'])->name('create');
        Route::post('/', [StudentManagementController::class, 'store'])->name('store');
        Route::get('{student}/edit', [StudentManagementController::class, 'edit'])->name('edit');
        Route::put('{student}', [StudentManagementController::class, 'update'])->name('update');
        Route::delete('{student}', [StudentManagementController::class, 'destroy'])->name('destroy');
        
        // Import/Export (CSV)
        Route::get('import/form', [StudentManagementController::class, 'importForm'])->name('importForm');
        Route::post('import', [StudentManagementController::class, 'import'])->name('import');
        Route::get('template/download', [StudentManagementController::class, 'downloadTemplate'])->name('downloadTemplate');
        Route::get('export/all', [StudentManagementController::class, 'exportAll'])->name('exportAll');
        // Import จาก Excel ต้นฉบับ (Admin only)
        Route::get('import-excel/form',    [StudentManagementController::class, 'importExcelForm'])->name('importExcelForm');
        Route::post('import-excel/preview',[StudentManagementController::class, 'importExcelPreview'])->name('importExcelPreview');
        Route::post('import-excel/confirm',[StudentManagementController::class, 'importExcelConfirm'])->name('importExcelConfirm');
        
        // View details
        Route::get('{student}', [StudentManagementController::class, 'show'])->name('show');
    });

    // ======================================
    // Admin Management
    // ======================================
    Route::prefix('admin')->name('admin.')->group(function () {

        // Project Import from Excel (Admin only)
        Route::prefix('projects')->name('projects.')->middleware('role:admin')->group(function () {
            Route::get('import-excel',         [\App\Http\Controllers\ProjectImportController::class, 'form'])->name('importExcel.form');
            Route::post('import-excel/preview',[\App\Http\Controllers\ProjectImportController::class, 'preview'])->name('importExcel.preview');
            Route::post('import-excel/confirm',[\App\Http\Controllers\ProjectImportController::class, 'confirm'])->name('importExcel.confirm');
        });

        // Submissions Management
        Route::prefix('submissions')->name('submissions.')->group(function () {
            Route::get('/', [AdminSubmissionController::class, 'index'])->name('index');
            Route::get('{project_id}', [AdminSubmissionController::class, 'show'])->name('show');
            Route::get('{project_id}/download', [AdminSubmissionController::class, 'download'])->name('download');
        });
        
        // Login Logs Management
        Route::prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [AdminLogController::class, 'index'])->name('index');
            Route::get('{log}', [AdminLogController::class, 'show'])->name('show');
            Route::get('export/csv', [AdminLogController::class, 'export'])->name('export');
        });
        // Activity Logs Management
        Route::prefix('activity-logs')->name('activity-logs.')->middleware('role:admin')->group(function () {
         Route::get('/', [AdminActivityLogController::class, 'index'])->name('index');
        });

        // Subject Management (Admin + Staff)
        Route::prefix('subjects')->name('subjects.')->middleware('role:admin,staff')->group(function () {
            Route::get('/', [SubjectController::class, 'index'])->name('index');
            Route::get('create', [SubjectController::class, 'create'])->name('create');
            Route::post('/', [SubjectController::class, 'store'])->name('store');
            Route::get('{subject}/edit', [SubjectController::class, 'edit'])->name('edit');
            Route::put('{subject}', [SubjectController::class, 'update'])->name('update');
            Route::post('{subject}/toggle', [SubjectController::class, 'toggle'])->name('toggle');
            Route::delete('{subject}', [SubjectController::class, 'destroy'])->name('destroy');
            Route::delete('term/{year}/{semester}', [SubjectController::class, 'destroyTerm'])->name('destroyTerm');
            Route::post('open-new-term', [SubjectController::class, 'openNewTerm'])->name('openNewTerm');
            Route::post('bulk-update-term', [SubjectController::class, 'bulkUpdateTerm'])->name('bulkUpdateTerm');
        });
        
    });
    
    // ======================================
    // Statistics Dashboard (Admin Only)
    // ======================================
    Route::prefix('statistics')->name('statistics.')->group(function () {
        Route::get('/', [StatisticsController::class, 'index'])->name('index');
        Route::get('export', [StatisticsController::class, 'export'])->name('export');
    });

    // ======================================
    // Permission Testing Route
    // ======================================
    Route::get('test-permission', [PermissionTestController::class, 'testPermission'])->name('test.permission');

});
