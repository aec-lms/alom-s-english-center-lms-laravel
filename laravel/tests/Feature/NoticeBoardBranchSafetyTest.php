<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\NoticeBoard\Http\Controllers\NoticeController;
use Tests\TestCase;

class NoticeBoardBranchSafetyTest extends TestCase
{
    public function test_branch_manager_cannot_create_a_notice_for_another_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'notice-board']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'NOTICE-A', 'name' => 'Notice Branch A', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'NOTICE-B', 'name' => 'Notice Branch B', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->createUserWithRole('branch_manager', $branchA);

        $request = Request::create('/admin/notices', 'POST', [
            'title' => 'Branch-only notice',
            'body' => 'This must remain in Branch A.',
            'audience_role' => 'student',
            'branch_id' => $branchB,
        ]);
        $request->setUserResolver(static fn () => $manager);

        app(NoticeController::class)->store($request);

        $this->assertDatabaseHas('notices', [
            'title' => 'Branch-only notice',
            'branch_id' => $branchA,
        ]);
        $this->assertDatabaseMissing('notices', [
            'title' => 'Branch-only notice',
            'branch_id' => $branchB,
        ]);
    }

    public function test_unassigned_branch_manager_is_denied_notice_directory(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['notice-board']);
        $manager = $this->createUserWithRole('branch_manager');
        $request = Request::create('/admin/notices', 'GET');
        $request->setUserResolver(static fn () => $manager);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(NoticeController::class)->index($request);
    }
}
