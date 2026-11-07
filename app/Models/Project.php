<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'projects';
    protected $primaryKey = 'project_id';

    protected $fillable = [
        'group_id',
        'parent_project_id',
        'project_name',
        'project_code',
        'exam_datetime',
        'exam_end_time',
        'student_type',
        'status_project',
        'project_type',
        'submission_file',
        'submission_original_name',
        'submitted_at',
        'submitted_by',
    ];

    protected $casts = [
        'exam_datetime' => 'datetime',
        'exam_end_time' => 'datetime',
        'submitted_at'  => 'datetime',
    ];

    // ──────────────────────────────────────────
    // Core relationships
    // ──────────────────────────────────────────

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id', 'group_id');
    }

    public function groups()
    {
    return $this->hasMany(Group::class, 'project_id', 'project_id');
    }

    public function parentProject()
    {
        return $this->belongsTo(Project::class, 'parent_project_id', 'project_id');
    }

    public function childProjects()
    {
        return $this->hasMany(Project::class, 'parent_project_id', 'project_id');
    }

    public function examSchedule()
    {
        return $this->hasOne(ExamSchedule::class, 'project_id', 'project_id');
    }

    public function evaluations()
    {
        return $this->hasMany(ProjectEvaluation::class, 'project_id', 'project_id');
    }

    public function latestProposal()
    {
        return $this->hasOneThrough(
            ProjectProposal::class,
            Group::class,
            'group_id',
            'group_id',
            'group_id',
            'group_id'
        )->latest('proposed_at');
    }

    // ──────────────────────────────────────────
    // Lecturer relationships (new)
    // ──────────────────────────────────────────

    public function projectLecturers()
    {
        return $this->hasMany(ProjectLecturer::class, 'project_id', 'project_id');
    }

    public function advisorLecturer()
    {
        return $this->hasOne(ProjectLecturer::class, 'project_id', 'project_id')
            ->where('relationship_id', 1);
    }

    public function committeeLecturers()
    {
        return $this->hasMany(ProjectLecturer::class, 'project_id', 'project_id')
            ->where('relationship_id', 2)
            ->orderBy('sort_order');
    }

    public function coAdvisors()
    {
        return $this->hasMany(ProjectLecturer::class, 'project_id', 'project_id')
            ->whereIn('relationship_id', [3, 4])
            ->orderBy('relationship_id')->orderBy('sort_order');
    }

    public function coAdvisorInternal()
    {
        return $this->hasOne(ProjectLecturer::class, 'project_id', 'project_id')
            ->where('relationship_id', 3);
    }

    public function coAdvisorExternal()
    {
        return $this->hasOne(ProjectLecturer::class, 'project_id', 'project_id')
            ->where('relationship_id', 4);
    }

    // ──────────────────────────────────────────
    // Backward-compat accessors (views unchanged)
    // ──────────────────────────────────────────

    public function getAdvisorAttribute()
    {
        return $this->advisorLecturer?->user;
    }

    public function getAdvisorCodeAttribute()
    {
        return $this->advisorLecturer?->user_code;
    }

    public function getCommittee1Attribute()
    {
        return $this->committeeLecturers->get(0)?->user;
    }

    public function getCommittee1CodeAttribute()
    {
        return $this->committeeLecturers->get(0)?->user_code;
    }

    public function getCommittee2Attribute()
    {
        return $this->committeeLecturers->get(1)?->user;
    }

    public function getCommittee2CodeAttribute()
    {
        return $this->committeeLecturers->get(1)?->user_code;
    }

    public function getCommittee3Attribute()
    {
        return $this->committeeLecturers->get(2)?->user;
    }

    public function getCommittee3CodeAttribute()
    {
        return $this->committeeLecturers->get(2)?->user_code;
    }

    // ──────────────────────────────────────────
    // Helper: map user_code → evaluator_role
    // ──────────────────────────────────────────

    public function getEvaluatorRole(string $userCode): ?string
    {
        $pl = $this->projectLecturers->firstWhere('user_code', $userCode);
        if (!$pl) return null;

        if ($pl->relationship_id === 1) return 'advisor';
        if ($pl->relationship_id === 2) return 'committee' . $pl->sort_order;

        return null;
    }

    // ──────────────────────────────────────────
    // Sync helper for controllers
    // ──────────────────────────────────────────

    public function syncLecturers(
        ?string $advisorCode,
        ?string $c1 = null,
        ?string $c2 = null,
        ?string $c3 = null
    ): void {
        $this->projectLecturers()->whereIn('relationship_id', [1, 2])->delete();

        if ($advisorCode) {
            $this->projectLecturers()->create([
                'user_code'       => $advisorCode,
                'relationship_id' => 1,
                'sort_order'      => 1,
            ]);
        }

        foreach (array_filter([$c1, $c2, $c3]) as $i => $code) {
            $this->projectLecturers()->create([
                'user_code'       => $code,
                'relationship_id' => 2,
                'sort_order'      => $i + 1,
            ]);
        }
    }

    public function syncCoAdvisors(?string $coAdvInt = null, ?string $coAdvExt = null): void
    {
        $this->projectLecturers()->whereIn('relationship_id', [3, 4])->delete();

        if ($coAdvInt) {
            $this->projectLecturers()->create([
                'user_code'       => $coAdvInt,
                'relationship_id' => 3,
                'sort_order'      => 1,
            ]);
        }

        if ($coAdvExt) {
            $this->projectLecturers()->create([
                'user_code'       => $coAdvExt,
                'relationship_id' => 4,
                'sort_order'      => 1,
            ]);
        }
    }

    // ──────────────────────────────────────────
    // Other helpers
    // ──────────────────────────────────────────

    /**
     * Returns late submission info if project was submitted late.
     * Deadline = exam_datetime - 1 day.
     * @return array{days:int,penalty_pct:int,deadline:\Carbon\Carbon}|null
     */
    public function getLateSubmissionInfo(): ?array
    {
        if ($this->status_project !== 'late_submission') return null;
        if (!$this->submitted_at || !$this->exam_datetime) return null;

        $deadline = $this->exam_datetime->copy()->subDay();

        if (!$this->submitted_at->gt($deadline)) return null;

        $seconds = $deadline->diffInSeconds($this->submitted_at);
        $days    = max(1, (int) ceil($seconds / 86400));

        return [
            'days'        => $days,
            'penalty_pct' => min($days * 20, 100),
            'deadline'    => $deadline,
        ];
    }

    public function getDisplayIdAttribute()
    {
        return sprintf('%02d-%02d', $this->group->semester, $this->group->group_id);
    }

    public function getFullProjectCodeAttribute()
    {
        $advisorCode = $this->advisorLecturer?->user_code ?? 'xxx';
        $memberCount = $this->group->getMemberCount();

        return sprintf(
            '%02d-%d-%02d_%s-%s%d',
            $this->group->year % 100,
            $this->group->semester,
            $this->group->group_id,
            $advisorCode,
            $this->student_type,
            $memberCount
        );
    }

    public function getFirstMemberAttribute()
    {
        return $this->group->first_member;
    }

    public function getSecondMemberAttribute()
    {
        return $this->group->second_member;
    }

    public function getProjectTypesAttribute()
    {
        if (!$this->project_type) return [];
        return array_map('trim', explode(',', $this->project_type));
    }

    public function hasProjectType($type)
    {
        return in_array($type, $this->project_types);
    }

    public function setProjectTypesAttribute($types)
    {
        $this->attributes['project_type'] = is_array($types)
            ? implode(',', $types)
            : $types;
    }
}
