<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentController extends Controller
{
    public function menu()
    {
        $student = Auth::guard('student')->user();
        
        if (!$student) {
            return redirect()->route('login')->with('error', 'กรุณาเข้าสู่ระบบ');
        }

        // ดึงข้อมูลกลุ่มของ student พร้อม proposal ล่าสุด
        $myGroup = $student->groups()
    ->with([
        'members.student',
        'latestProposal.lecturer',
        'project.advisorLecturer.user',
        'project.committeeLecturers.user'
    ])
    ->first();
        
        // ดึงคำเชิญที่ค้างอยู่
        $pendingInvitations = $student->pendingInvitations()
            ->with(['group', 'inviter'])
            ->orderBy('created_at', 'desc')
            ->get();

        // เช็คว่าเป็นหัวหน้ากลุ่มหรือไม่
        $isGroupLeader = false;
        if ($myGroup) {
            $firstMember = $myGroup->members()->orderBy('groupmem_id', 'asc')->first();
            $isGroupLeader = $firstMember && $firstMember->username_std === $student->username_std;
        }

        return view('student.menu', compact('student', 'myGroup', 'pendingInvitations', 'isGroupLeader'));
    }

    public function dashboard()
    {
        return $this->menu(); // redirect to menu as requested
    }

}
