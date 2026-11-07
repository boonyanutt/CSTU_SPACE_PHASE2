<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCS303Enrollments extends Command
{
    protected $signature = 'enrollments:backfill-cs303 {--dry-run}';

    protected $description = 'Backfill historical CS303 enrollments';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $rows = DB::table('groups as g')
            ->join('group_members as gm', 'gm.group_id', '=', 'g.group_id')
            ->join('student as s', 's.username_std', '=', 'gm.username_std')
            ->where('g.subject_code', 'CS303')
            ->select(
                's.student_id',
                's.username_std',
                's.grade_cs303',
                'g.year',
                'g.semester',
                'g.project_id',
                'g.group_id'
            )
            ->get();

        // รวมข้อมูลตามนักศึกษา + ปี + ภาคเรียน
        $records = $rows->groupBy(function ($row) {
            return $row->student_id . '|' . $row->year . '|' . $row->semester;
        });

        // ตรวจว่ามีประวัติ CS303 มากกว่าหนึ่งภาคเรียนหรือไม่
        $studentTermCounts = $rows
            ->groupBy('student_id')
            ->map(function ($studentRows) {
                return $studentRows
                    ->map(fn ($row) => $row->year . '|' . $row->semester)
                    ->unique()
                    ->count();
            });

        $inserted = 0;
        $existing = 0;
        $conflicts = 0;

        foreach ($records as $recordRows) {
            $first = $recordRows->first();

            // ถ้าโครงงานในภาคเรียนเดียวกันขัดแย้งกัน ให้ข้าม
            $projectIds = $recordRows
                ->pluck('project_id')
                ->unique();

            if ($projectIds->count() !== 1 || $first->project_id === null) {
                $conflicts++;

                $this->warn(
                    "Skipped conflicting project: {$first->username_std}"
                );

                continue;
            }

            // ถ้ามีประวัติอยู่แล้ว ไม่เพิ่มซ้ำและไม่แก้ข้อมูลเดิม
            $alreadyExists = DB::table('student_enrollments')
                ->where('student_id', $first->student_id)
                ->where('subject_code', 'CS303')
                ->where('year', $first->year)
                ->where('semester', $first->semester)
                ->exists();

            if ($alreadyExists) {
                $existing++;
                continue;
            }

            // หากเคยเรียนหลายภาคเรียน ไม่เดาว่าเกรดเดิมเป็นของเทอมใด
            $grade = $studentTermCounts[$first->student_id] === 1
                ? $first->grade_cs303
                : null;

            if (!$dryRun) {
                DB::table('student_enrollments')->insert([
                    'student_id' => $first->student_id,
                    'subject_code' => 'CS303',
                    'year' => $first->year,
                    'semester' => $first->semester,
                    'grade' => $grade,
                    'project_id' => $first->project_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $inserted++;
        }

        $this->info($dryRun ? 'DRY RUN — no data changed' : 'BACKFILL COMPLETE');
        $this->line("Records to insert / inserted: {$inserted}");
        $this->line("Already existing: {$existing}");
        $this->line("Conflicts skipped: {$conflicts}");

        return self::SUCCESS;
    }
}