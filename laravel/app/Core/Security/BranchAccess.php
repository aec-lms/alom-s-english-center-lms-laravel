<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Plugins\PluginManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BranchAccess
{
    public function isBranchManager(?object $actor): bool
    {
        return $actor !== null && method_exists($actor, 'hasRole') && $actor->hasRole('branch_manager');
    }

    public function assignedBranchId(?object $actor): ?int
    {
        if (!$this->isBranchManager($actor)) {
            return null;
        }

        abort_unless(
            isset($actor->branch_id)
                && $actor->branch_id !== null
                && app(PluginManager::class)->isEnabled('branch-management')
                && Schema::hasTable('branches')
                && \Illuminate\Support\Facades\DB::table('branches')
                    ->where('id', $actor->branch_id)
                    ->where('is_active', true)
                    ->exists(),
            403,
            'Branch manager must be assigned to an active branch.'
        );

        return (int) $actor->branch_id;
    }

    public function contextBranchId(?object $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        if ($this->isBranchManager($actor)) {
            return $this->assignedBranchId($actor);
        }

        $plugins = app(PluginManager::class);
        $profiles = [
            ['student-management', 'students'],
            ['teacher-management', 'teachers'],
            ['guardian-management', 'guardians'],
            ['hr-staff-management', 'staff_profiles'],
        ];

        $branchId = null;
        foreach ($profiles as [$plugin, $table]) {
            if (!$plugins->isEnabled($plugin)
                || !Schema::hasTable($table)
                || !Schema::hasColumn($table, 'user_id')
                || !Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            $profileBranchId = DB::table($table)->where('user_id', $actor->id)->value('branch_id');
            if ($profileBranchId !== null) {
                $branchId = (int) $profileBranchId;
                break;
            }
        }

        if ($branchId === null && isset($actor->branch_id) && $actor->branch_id !== null) {
            $branchId = (int) $actor->branch_id;
        }

        if ($branchId !== null
            && $plugins->isEnabled('branch-management')
            && Schema::hasTable('branches')
            && !DB::table('branches')->where('id', $branchId)->where('is_active', true)->exists()) {
            return null;
        }

        return $branchId;
    }

    public function scope(QueryBuilder|EloquentBuilder $query, ?object $actor, string $column = 'branch_id'): QueryBuilder|EloquentBuilder
    {
        $branchId = $this->assignedBranchId($actor);
        if ($branchId !== null) {
            $query->where($column, $branchId);
        }

        return $query;
    }

    public function assertRecord(?object $actor, mixed $record, string $branchColumn = 'branch_id'): void
    {
        $branchId = $this->assignedBranchId($actor);
        if ($branchId === null) {
            return;
        }

        abort_unless(
            is_object($record)
                && isset($record->{$branchColumn})
                && (int) $record->{$branchColumn} === $branchId,
            404,
            'The requested record is not available in your assigned branch.'
        );
    }

    public function assertRequestBranch(Request $request, string $input = 'branch_id'): void
    {
        $branchId = $this->assignedBranchId($request->user());
        if ($branchId === null || !$request->has($input)) {
            return;
        }

        abort_unless((int) $request->input($input) === $branchId, 403, 'You cannot access or create records for another branch.');
    }
}
