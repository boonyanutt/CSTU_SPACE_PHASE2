<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $table = 'groups';
    protected $primaryKey = 'group_id';
    
    protected $fillable = [
        'group_id',
        'year',
        'semester',
        'subject_code',
        'status_group'
    ];

    protected $casts = [
        'year' => 'integer',
        'semester' => 'integer',
    ];

    // Relationships
    public function members()
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'group_id');
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'group_members', 'group_id', 'username_std', 'group_id', 'username_std');
    }

    public function invitations()
    {
        return $this->hasMany(GroupInvitation::class, 'group_id', 'group_id');
    }

    public function pendingInvitations()
    {
        return $this->invitations()->where('status', 'pending');
    }

    // Helper methods
    public function getMemberCount()
    {
        return $this->members()->count();
    }

    public function canAddMember()
    {
        return $this->getMemberCount() < 2;
    }

    public function getCreator()
    {
        return $this->members()->first()?->student;
    }

    // ตรวจสอบว่ามีคำเชิญที่รอการตอบรับอยู่หรือไม่
    public function hasPendingInvitation()
    {
        return $this->invitations()->where('status', 'pending')->exists();
    }

    // ตรวจสอบว่าสามารถเสนอหัวข้อโครงงานได้หรือไม่
    public function canProposeProject()
    {
        // ต้อง status_group = 'created', 'member_added' หรือ 'member_left'
        if (!in_array($this->status_group, ['created', 'member_added', 'member_left'])) {
            return false;
        }

        // ถ้ามีคำเชิญที่รอการตอบรับอยู่ → ยังเสนอไม่ได้
        if ($this->hasPendingInvitation()) {
            return false;
        }

        // ถ้าโครงงานได้รับการอนุมัติหรืออยู่ระหว่างดำเนินการแล้ว → ไม่สามารถเสนอใหม่ได้
        if ($this->project && in_array($this->project->status_project, [
            'approved', 'in_progress', 'submitted', 'late_submission', 'passed', 'failed'
        ])) {
            return false;
        }

        // ถ้าไม่มีคำเชิญรออยู่ และยังไม่ได้รับการอนุมัติ → เสนอได้
        return true;
    }

    // Relationship กับ Project
    public function project()
    {
    return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    // Relationship กับ Project Proposals
    public function proposals()
    {
        return $this->hasMany(ProjectProposal::class, 'group_id', 'group_id');
    }

    public function latestProposal()
    {
        return $this->hasOne(ProjectProposal::class, 'group_id', 'group_id')->latestOfMany('proposed_at');
    }

    // Accessor สำหรับ ID ที่ coordinator เห็น (format: 01-01 คือ semester-group_id)
    public function getDisplayIdAttribute()
    {
        return sprintf('%02d-%02d', $this->semester, $this->group_id);
    }

    // ดึงสมาชิกคนที่ 1 (คนสร้างกลุ่ม)
    public function getFirstMemberAttribute()
    {
        return $this->members()->orderBy('groupmem_id', 'asc')->first()?->student;
    }

    // ดึงสมาชิกคนที่ 2 (คนที่กดรับคำเชิญ)
    public function getSecondMemberAttribute()
    {
        return $this->members()->orderBy('groupmem_id', 'asc')->skip(1)->first()?->student;
    }
}
