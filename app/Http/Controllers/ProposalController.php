<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProjectProposal;
use App\Models\Group;
use App\Models\User;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\UserActivityLog;

class ProposalController extends Controller
{
    // แสดงฟอร์มเสนอหัวข้อ (สำหรับ group leader)
    public function create($groupId)
    {
        $group = Group::with(['members.student', 'latestProposal'])->findOrFail($groupId);
        
        // ตรวจสอบว่าเป็นหัวหน้ากลุ่ม (สมาชิกคนแรก)
        $firstMember = $group->members()->orderBy('groupmem_id', 'asc')->first();
        if (!$firstMember || $firstMember->username_std !== Auth::guard('student')->user()->username_std) {
            return redirect()->route('student.menu')
                ->with('error', 'เฉพาะหัวหน้ากลุ่มเท่านั้นที่สามารถเสนอหัวข้อได้');
        }
        
        // ตรวจสอบว่าสามารถเสนอหัวข้อได้หรือไม่
        if (!$group->canProposeProject()) {
            $message = 'ไม่สามารถเสนอหัวข้อได้ในขณะนี้';
            
            if ($group->hasPendingInvitation()) {
                $message = 'ต้องรอให้สมาชิกที่ถูกเชิญตอบรับหรือปฏิเสธก่อนถึงจะสามารถเสนอหัวข้อได้';
            }
            
            return redirect()->route('student.menu')->with('error', $message);
        }
        
        // ดึงรายชื่อ lecturers (ใช้ bitwise check เพื่อรองรับ multi-role)
        $lecturers = User::whereRaw('(role & 8192) != 0')->get();
        
        return view('student.proposals.create', compact('group', 'lecturers'));
    }
    
    // บันทึกข้อเสนอ
    public function store(Request $request, $groupId)
    {
        $group = Group::with(['members', 'project'])->findOrFail($groupId);
        
        // ตรวจสอบสิทธิ์
        $firstMember = $group->members()->orderBy('groupmem_id', 'asc')->first();
        if (!$firstMember || $firstMember->username_std !== Auth::guard('student')->user()->username_std) {
            return redirect()->route('student.menu')
                ->with('error', 'ไม่มีสิทธิ์เสนอหัวข้อ');
        }
        
        // ตรวจสอบว่าสามารถเสนอได้หรือไม่
        if (!$group->canProposeProject()) {
            return redirect()->route('student.menu')
                ->with('error', 'ต้องรอให้สมาชิกตอบรับคำเชิญก่อนถึงจะเสนอหัวข้อได้');
        }
        
        $request->validate([
            'proposed_title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'proposed_to' => 'required|exists:user,username_user'
        ]);
        
        // ตรวจสอบว่า lecturer มี role lecturer จริงหรือไม่ (ใช้ bitwise check)
        $lecturer = User::where('username_user', $request->proposed_to)
            ->whereRaw('(role & 8192) != 0')
            ->first();
            
        if (!$lecturer) {
            return back()->with('error', 'ผู้ใช้ที่เลือกไม่ใช่อาจารย์');
        }
        
        DB::beginTransaction();
        try {
            // สร้างข้อเสนอ
           $proposal = ProjectProposal::create([
    'group_id' => $groupId,
    'proposed_title' => $request->proposed_title,
    'description' => $request->description,
    'proposed_to' => $request->proposed_to,
    'proposed_by' => Auth::guard('student')->user()->username_std,
    'status' => 'pending'
]);
            
            // อัพเดต project status เป็น pending
            if ($group->project) {
                $group->project->update([
                    'status_project' => 'pending',
                    'project_name' => $request->proposed_title // เก็บชื่อโครงงานที่เสนอไว้ด้วย
                ]);
            }
            $student = Auth::guard('student')->user();

UserActivityLog::create([
    'username'    => $student->username_std,
    'user_type'   => 'student',
    'student_id'  => $student->student_id,
    'role'        => 'student',

    'action'      => 'SUBMIT_PROPOSAL',
    'module'      => 'PROPOSAL',

    'target_type' => 'project_proposals',
    'target_id'   => $proposal->proposal_id,

    'description' => "Student {$student->username_std} submitted proposal {$request->proposed_title}",

    'new_values'  => json_encode([
        'group_id' => $groupId,
        'title' => $request->proposed_title,
        'lecturer' => $request->proposed_to,
        'status' => 'pending',
    ]),

    'ip_address'  => request()->ip(),
    'user_agent'  => request()->userAgent(),
]);
            DB::commit();
            return redirect()->route('groups.show', $groupId)
                ->with('success', 'ส่งข้อเสนอหัวข้อสำเร็จ รอการพิจารณาจากอาจารย์');
                
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    
    // แสดงรายการข้อเสนอของ lecturer
    public function lecturerIndex(Request $request)
    {
        $username = Auth::guard('web')->user()->username_user;

        $query = ProjectProposal::with(['group.members.student', 'student'])
            ->where('proposed_to', $username);

        if ($request->filled('year'))     $query->whereHas('group', fn($q) => $q->where('year', $request->year));
        if ($request->filled('semester')) $query->whereHas('group', fn($q) => $q->where('semester', $request->semester));
        if ($request->filled('subject'))  $query->whereHas('group', fn($q) => $q->where('subject_code', $request->subject));
        if ($request->filled('status'))   $query->where('status', $request->status);

        $proposals = $query->orderBy('status', 'asc')->orderBy('proposed_at', 'desc')->get();

        $groupIds  = ProjectProposal::where('proposed_to', $username)->pluck('group_id');
        $years     = DB::table('groups')->whereIn('group_id', $groupIds)->distinct()->orderBy('year', 'desc')->pluck('year');
        $semesters = DB::table('groups')->whereIn('group_id', $groupIds)->distinct()->orderBy('semester')->pluck('semester');
        $subjects  = DB::table('groups')->whereIn('group_id', $groupIds)->distinct()->orderBy('subject_code')->pluck('subject_code');

        return view('lecturer.proposals.index', compact('proposals', 'years', 'semesters', 'subjects'));
    }
    
    // แสดงรายละเอียดข้อเสนอ
    public function show($proposalId)
    {
        $proposal = ProjectProposal::with(['group.members.student', 'student', 'lecturer'])
            ->findOrFail($proposalId);
        
        // ตรวจสอบสิทธิ์
        $user = Auth::guard('web')->user();
        if ($proposal->proposed_to !== $user->username_user) {
            return redirect()->route('lecturer.proposals.index')
                ->with('error', 'ไม่มีสิทธิ์ดูข้อเสนอนี้');
        }
        
        return view('lecturer.proposals.show', compact('proposal'));
    }
    
    // อนุมัติข้อเสนอ
    public function approve($proposalId)
    {
        $proposal = ProjectProposal::with(['group.project'])->findOrFail($proposalId);
        
        // ตรวจสอบสิทธิ์
        $user = Auth::guard('web')->user();
        if ($proposal->proposed_to !== $user->username_user) {
            return back()->with('error', 'ไม่มีสิทธิ์ดำเนินการ');
        }
        
        if ($proposal->status !== 'pending') {
            return back()->with('error', 'ข้อเสนอนี้ได้รับการตอบกลับแล้ว');
        }
        
        DB::beginTransaction();
        try {
            // อัพเดทสถานะข้อเสนอ
            $proposal->update([
                'status' => 'approved',
                'responded_at' => now()
            ]);
            
            // อัพเดต project status เป็น approved
            if ($proposal->group->project) {
                $project = $proposal->group->project;
                
                // อัปเดต project_code โดยเปลี่ยน TBD เป็นรหัสอาจารย์
                $oldProjectCode = $project->project_code;
                $newProjectCode = preg_replace('/_TBD-/', "_{$user->user_code}-", $oldProjectCode);
                
                $project->update([
                    'status_project' => 'in_progress',
                    'project_code'   => $newProjectCode,
                ]);

                // Set this lecturer as advisor (replace any existing advisor)
                $project->projectLecturers()->where('relationship_id', 1)->delete();
                $project->projectLecturers()->create([
                    'user_code'       => $user->user_code,
                    'relationship_id' => 1,
                    'sort_order'      => 1,
                ]);
            }
            UserActivityLog::create([
    'username'    => $user->username_user,
    'user_type'   => 'lecturer',
    'role'        => 'lecturer',

    'action'      => 'APPROVE_PROPOSAL',
    'module'      => 'PROPOSAL',

    'target_type' => 'project_proposals',
    'target_id'   => $proposal->proposal_id,

    'description' => "Lecturer {$user->username_user} approved proposal {$proposal->proposal_id}",

    'new_values'  => json_encode([
        'group_id' => $proposal->group_id,
        'status' => 'approved',
        'project_code' => $newProjectCode ?? null,
    ]),

    'ip_address'  => request()->ip(),
    'user_agent'  => request()->userAgent(),
]);
            DB::commit();
            
            return redirect()->route('lecturer.proposals.index')
                ->with('success', 'อนุมัติข้อเสนอสำเร็จ');
                
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    
    // ปฏิเสธข้อเสนอ
    public function reject(Request $request, $proposalId)
    {
        $proposal = ProjectProposal::with(['group.project'])->findOrFail($proposalId);
        
        // ตรวจสอบสิทธิ์
        $user = Auth::guard('web')->user();
        if ($proposal->proposed_to !== $user->username_user) {
            return back()->with('error', 'ไม่มีสิทธิ์ดำเนินการ');
        }
        
        if ($proposal->status !== 'pending') {
            return back()->with('error', 'ข้อเสนอนี้ได้รับการตอบกลับแล้ว');
        }
        
        $request->validate([
            'rejection_reason' => 'required|string|max:500'
        ]);
        
        DB::beginTransaction();
        try {
            // อัพเดต proposal status
            $proposal->update([
                'status' => 'rejected',
                'rejection_reason' => $request->rejection_reason,
                'responded_at' => now()
            ]);
            
            // อัพเดต project status เป็น rejected
            if ($proposal->group->project) {
                $proposal->group->project->update([
                    'status_project' => 'rejected'
                ]);
            }
            UserActivityLog::create([
    'username'    => $user->username_user,
    'user_type'   => 'lecturer',
    'role'        => 'lecturer',

    'action'      => 'REJECT_PROPOSAL',
    'module'      => 'PROPOSAL',

    'target_type' => 'project_proposals',
    'target_id'   => $proposal->proposal_id,

    'description' => "Lecturer {$user->username_user} rejected proposal {$proposal->proposal_id}",

    'new_values'  => json_encode([
        'group_id' => $proposal->group_id,
        'status' => 'rejected',
        'reason' => $request->rejection_reason,
    ]),

    'ip_address'  => request()->ip(),
    'user_agent'  => request()->userAgent(),
]);
            DB::commit();
            
            return redirect()->route('lecturer.proposals.index')
                ->with('success', 'ปฏิเสธข้อเสนอแล้ว');
                
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }
    
    // Coordinator - ดูข้อเสนอทั้งหมดในระบบ
    public function coordinatorIndex()
    {
        $proposals = ProjectProposal::with(['group.members.student', 'student', 'lecturer'])
            ->orderBy('status', 'asc') // pending ก่อน
            ->orderBy('proposed_at', 'desc')
            ->get();
            
        return view('coordinator.proposals.index', compact('proposals'));
    }
}
