<?php

declare(strict_types=1);

namespace Plugins\\Modules\\CourseWishlist\\Http\\Controllers;

use App\\Core\\Plugins\\PluginManager;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\View\\View;

class CourseWishlistController extends Controller
{
    public function index(Request $request, PluginManager $plugins): View
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Student accounts only.');
        abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Course Management must be enabled to browse saved courses.');
        abort_unless(Schema::hasTable('course_wishlists'), 503, 'Course Wishlist storage is not installed.');

        $courses = DB::table('courses')
            ->join('course_wishlists', function ($join) use ($actor): void {
                $join->on('courses.id', '=', 'course_wishlists.course_id')
                    ->where('course_wishlists.user_id', '=', $actor->id);
            })
            ->where('courses.status', 'published')
            ->when($actor->branch_id !== null, fn ($query) => $query->where(function ($scope) use ($actor): void {
                $scope->whereNull('courses.branch_id')->orWhere('courses.branch_id', $actor->branch_id);
            }))
            ->when($actor->branch_id === null, fn ($query) => $query->whereNull('courses.branch_id'))
            ->select('courses.id', 'courses.title', 'courses.slug', 'courses.description', 'courses.price', 'courses.thumbnail', 'courses.branch_id', 'course_wishlists.created_at as saved_at')
            ->orderByDesc('course_wishlists.created_at')
            ->paginate(12);

        return view('course-wishlist::index', compact('courses'));
    }

    public function store(Request $request, int $course, PluginManager $plugins): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Student accounts only.');
        abort_unless($plugins->isEnabled('course-management') && Schema::hasTable('courses'), 503, 'Course Management is not available.');
        abort_unless(Schema::hasTable('course_wishlists'), 503, 'Course Wishlist storage is not installed.');

        $courseQuery = DB::table('courses')->where('id', $course)->where('status', 'published');
        $courseQuery->when($actor->branch_id !== null, fn ($query) => $query->where(function ($scope) use ($actor): void {
            $scope->whereNull('branch_id')->orWhere('branch_id', $actor->branch_id);
        }));
        $courseQuery->when($actor->branch_id === null, fn ($query) => $query->whereNull('branch_id'));
        abort_unless($courseQuery->exists(), 404, 'Published course not found in your available branch.');

        DB::table('course_wishlists')->insertOrIgnore([
            'user_id' => $actor->id,
            'course_id' => $course,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Course saved to your wishlist.');
    }

    public function destroy(Request $request, int $course): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor && $actor->hasRole('student'), 403, 'Student accounts only.');
        abort_unless(Schema::hasTable('course_wishlists'), 503, 'Course Wishlist storage is not installed.');

        DB::table('course_wishlists')
            ->where('user_id', $actor->id)
            ->where('course_id', $course)
            ->delete();

        return back()->with('status', 'Course removed from your wishlist.');
    }
}
