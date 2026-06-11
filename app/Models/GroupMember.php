<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupMember extends Model
{
    protected $table = 'group_members';
    protected $primaryKey = 'groupmem_id';
    
    protected $fillable = [
        'group_id',
        'username_std',
        'topic2_confirmation_status',
        'topic2_confirmed_at',
        'confirmation_remark',
    ];

    protected $casts = [
        'topic2_confirmed_at' => 'datetime',
    ];

    // Relationships
    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id', 'group_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'username_std', 'username_std');
    }

    public function isTopic2Pending()
    {
        return $this->topic2_confirmation_status === 'pending';
    }

    public function isTopic2Confirmed()
    {
        return $this->topic2_confirmation_status === 'confirmed';
    }

    public function isTopic2Rejected()
    {
        return $this->topic2_confirmation_status === 'rejected';
    }
}