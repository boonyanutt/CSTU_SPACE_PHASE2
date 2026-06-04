<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserActivityLog extends Model
{
    use HasFactory;

    protected $primaryKey = 'activity_log_id';

    protected $fillable = [
        'username',
        'user_type',
        'user_id',
        'student_id',
        'role',
        'action',
        'module',
        'target_type',
        'target_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public static function createActivityLog(array $data)
    {
        return self::create([
            'username' => $data['username'] ?? null,
            'user_type' => $data['user_type'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'student_id' => $data['student_id'] ?? null,
            'role' => $data['role'] ?? null,
            'action' => $data['action'],
            'module' => $data['module'],
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'description' => $data['description'] ?? null,
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function getCreatedAtFormatAttribute()
    {
        return Carbon::parse($this->created_at)
            ->timezone('Asia/Bangkok')
            ->format('d/m/Y H:i:s');
    }
}