<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GroupInvitation;
use App\Models\GroupMember;
use App\Models\Project;
use App\Models\Student;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\UserActivityLog;

class GroupInvitationController extends Controller
{
    public function accept(GroupInvitation $invitation)
    {
        $student = Auth::guard('student')->user();

        // ตรวจสอบว่าเป็นผู้ที่ถูกเชิญ
        if ($invitation->invitee_username !== $student->username_std) {
            return redirect()->route('student.menu')->with('error', 'คุณไม่มีสิทธิ์ดำเนินการนี้');
        }

        // ตรวจสอบสถานะคำเชิญ
        if (!$invitation->isPending()) {
            return redirect()->route('student.menu')->with('error', 'คำเชิญนี้ได้ดำเนินการแล้ว');
        }

        // ตรวจสอบว่า student ยังไม่มีกลุ่ม
        if ($student->hasGroup()) {
            return redirect()->route('student.menu')->with('error', 'คุณมีกลุ่มแล้ว');
        }

        // ตรวจสอบว่ากลุ่มยังสามารถรับสมาชิกได้
        if (!$invitation->group->canAddMember()) {
            return redirect()->route('student.menu')->with('error', 'กลุ่มนี้เต็มแล้ว');
        }

        DB::beginTransaction();
        try {
            // ตอบรับคำเชิญ
            $invitation->accept();

            // เพิ่มเป็นสมาชิกกลุ่ม
            GroupMember::create([
                'group_id' => $invitation->group_id,
                'username_std' => $student->username_std
            ]);
            
            // อัพเดตสถานะกลุ่มเป็น member_added
            $invitation->group->update(['status_group' => 'member_added']);
            
            // อัพเดต project_code และ student_type
            $project = Project::where('group_id', $invitation->group_id)->first();
            if ($project) {
                $members = GroupMember::where('group_id', $invitation->group_id)
                    ->get()
                    ->pluck('username_std');
                
                $students = Student::whereIn('username_std', $members)->get();
                $memberCount = $students->count();
                
                // ตรวจสอบ student_type ของสมาชิกทั้งหมด
                $hasRegular = $students->where('student_type', 'r')->count() > 0;
                $hasSpecial = $students->where('student_type', 's')->count() > 0;
                
                // กำหนด student_type
                if ($hasRegular && $hasSpecial) {
                    $studentType = 'rs'; // ผสม
                } elseif ($hasSpecial) {
                    $studentType = 's';
                } else {
                    $studentType = 'r';
                }
                
                // อัพเดต project_code
                $projectCode = sprintf(
                    '%02d-%d-%02d_TBD-%s%d',
                    $invitation->group->year % 100,
                    $invitation->group->semester,
                    $invitation->group_id,
                    $studentType,
                    $memberCount
                );
                
                $project->update([
                    'project_code' => $projectCode,
                    'student_type' => $studentType
                ]);
            }

            UserActivityLog::create([
    'username'    => $student->username_std,
    'user_type'   => 'student',
    'student_id'  => $student->student_id,
    'role'        => 'student',

    'action'      => 'ACCEPT_INVITATION',
    'module'      => 'GROUP',

    'target_type' => 'group_invitations',
    'target_id'   => $invitation->invitation_id,

    'description' => "Student {$student->username_std} accepted invitation to group {$invitation->group_id}",

    'new_values' => [
        'group_id' => $invitation->group_id,
        'inviter_username' => $invitation->inviter_username,
        'invitee_username' => $invitation->invitee_username,
        'status' => 'accepted',
        'project_code' => $projectCode ?? null,
],
    'ip_address'  => request()->ip(),
    'user_agent'  => request()->userAgent(),
]);

DB::commit();
            
            // เก็บข้อมูลสำหรับแจ้งเตือน
            $inviterStudent = Student::where('username_std', $invitation->inviter_username)->first();
            $inviterName = $inviterStudent 
                ? "{$inviterStudent->firstname_std} {$inviterStudent->lastname_std}" 
                : $invitation->inviter_username;
            
            $groupNumber = sprintf('%02d-%02d', $invitation->group->semester, $invitation->group_id);
            
            return redirect()->route('student.menu')
                ->with('success', "🎉 คุณได้เข้าร่วมกลุ่ม #{$groupNumber} ของ {$inviterName} เรียบร้อยแล้ว!");

        } catch (\Exception $e) {
            DB::rollback();
            return redirect()->route('student.menu')->with('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        }
    }

    public function decline(GroupInvitation $invitation)
    {
        $student = Auth::guard('student')->user();

        // ตรวจสอบว่าเป็นผู้ที่ถูกเชิญ
        if ($invitation->invitee_username !== $student->username_std) {
            return redirect()->route('student.menu')->with('error', 'คุณไม่มีสิทธิ์ดำเนินการนี้');
        }

        // ตรวจสอบสถานะคำเชิญ
        if (!$invitation->isPending()) {
            return redirect()->route('student.menu')->with('error', 'คำเชิญนี้ได้ดำเนินการแล้ว');
        }

        // ปฏิเสธคำเชิญ
        $invitation->decline();

UserActivityLog::create([
    'username'    => $student->username_std,
    'user_type'   => 'student',
    'student_id'  => $student->student_id,
    'role'        => 'student',

    'action'      => 'DECLINE_INVITATION',
    'module'      => 'GROUP',

    'target_type' => 'group_invitations',
    'target_id'   => $invitation->invitation_id,

    'description' => "Student {$student->username_std} declined invitation to group {$invitation->group_id}",

    'new_values' => [
        'group_id' => $invitation->group_id,
        'inviter_username' => $invitation->inviter_username,
        'invitee_username' => $invitation->invitee_username,
      'status' => 'declined',
],

    'ip_address'  => request()->ip(),
    'user_agent'  => request()->userAgent(),
]);


return redirect()->route('student.menu')->with('success', 'ปฏิเสธคำเชิญแล้ว');
    }

    public function index()
    {
        $student = Auth::guard('student')->user();
        
        $invitations = $student->receivedInvitations()
            ->with(['group', 'inviter'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('student.invitations.index', compact('invitations'));
    }

    // ยกเลิกคำเชิญ (สำหรับผู้ส่งคำเชิญ)
    public function cancel(GroupInvitation $invitation)
    {
        $student = Auth::guard('student')->user();

        // ตรวจสอบว่าเป็นผู้ส่งคำเชิญ
        if ($invitation->inviter_username !== $student->username_std) {
            return redirect()->route('student.menu')->with('error', 'คุณไม่มีสิทธิ์ดำเนินการนี้');
        }

        // ตรวจสอบสถานะคำเชิญ
        if (!$invitation->isPending()) {
            return redirect()->route('student.menu')->with('error', 'คำเชิญนี้ได้ดำเนินการแล้ว');
        }
       $invitationData = [
    'group_id' => $invitation->group_id,
    'inviter_username' => $invitation->inviter_username,
    'invitee_username' => $invitation->invitee_username,
    'status' => $invitation->status,
];
$invitationId = $invitation->invitation_id;
$invitation->delete();

UserActivityLog::create([
    'username'    => $student->username_std,
    'user_type'   => 'student',
    'student_id'  => $student->student_id,
    'role'        => 'student',

    'action'      => 'CANCEL_INVITATION',
    'module'      => 'GROUP',

    'target_type' => 'group_invitations',
    'target_id' => $invitationId,

    'description' => "Student {$student->username_std} cancelled invitation to {$invitationData['invitee_username']}",

    'new_values'  => json_encode($invitationData),

    'ip_address'  => request()->ip(),
    'user_agent'  => request()->userAgent(),
]);

return redirect()->back()->with('success', 'ยกเลิกคำเชิญแล้ว');
    }
}
