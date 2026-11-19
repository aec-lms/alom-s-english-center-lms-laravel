<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugins\Modules\CmsWebsite\Http\Controllers\CmsController;

Route::middleware(['web', 'installed', 'auth', 'plugin:cms-website', 'permission:cms.manage', 'central.admin'])
    ->prefix('admin/cms')
    ->name('plugins.cms.')
    ->group(function (): void {
        Route::get('/', [CmsController::class, 'index'])->name('index');
        Route::post('/categories', [CmsController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [CmsController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [CmsController::class, 'destroyCategory'])->name('categories.destroy');
        Route::post('/posts', [CmsController::class, 'storePost'])->name('posts.store');
        Route::put('/posts/{post}', [CmsController::class, 'updatePost'])->name('posts.update');
        Route::post('/posts/{post}/submit-review', [CmsController::class, 'submitPostForReview'])->name('posts.submit-review');
        Route::post('/posts/{post}/approve', [CmsController::class, 'approvePost'])->name('posts.approve');
        Route::post('/posts/{post}/request-changes', [CmsController::class, 'requestPostChanges'])->name('posts.request-changes');
        Route::post('/posts/{post}/publish-approved', [CmsController::class, 'publishApprovedPost'])->name('posts.publish-approved');
        Route::post('/posts/{post}/revisions/{revisionId}/restore', [CmsController::class, 'restoreRevision'])->whereNumber('revisionId')->name('posts.revisions.restore');
        Route::delete('/posts/{post}', [CmsController::class, 'destroyPost'])->name('posts.destroy');
        Route::post('/posts/{postId}/restore', [CmsController::class, 'restorePost'])->whereNumber('postId')->name('posts.restore');
        Route::delete('/posts/{postId}/force', [CmsController::class, 'forceDeletePost'])->whereNumber('postId')->name('posts.force-delete');
        Route::post('/homepage', [CmsController::class, 'saveHomepage'])->name('homepage.save');
        Route::post('/inquiries/{id}/status', [CmsController::class, 'updateInquiryStatus'])->name('inquiries.status');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:cms-website', 'permission:cms.posts.view'])
    ->prefix('admin/cms')
    ->name('plugins.cms.')
    ->group(function (): void {
        Route::get('/editorial', [CmsController::class, 'editorialDashboard'])->name('editorial');
        Route::post('/editorial/posts', [CmsController::class, 'storeEditorialPost'])->middleware('permission:cms.posts.create')->name('editorial.posts.store');
        Route::put('/editorial/posts/{post}', [CmsController::class, 'updateEditorialPost'])->middleware('permission:cms.posts.edit_own')->name('editorial.posts.update');
        Route::put('/editorial/posts/{post}/assignments', [CmsController::class, 'updatePostAssignments'])->middleware('permission:cms.authors.assign')->name('editorial.posts.assignments');
        Route::post('/editorial/posts/{post}/submit-review', [CmsController::class, 'submitPostForReview'])->middleware('permission:cms.posts.submit')->name('editorial.posts.submit-review');
        Route::post('/editorial/posts/{post}/approve', [CmsController::class, 'approvePost'])->middleware('permission:cms.posts.review')->name('editorial.posts.approve');
        Route::post('/editorial/posts/{post}/request-changes', [CmsController::class, 'requestPostChanges'])->middleware('permission:cms.posts.review')->name('editorial.posts.request-changes');
        Route::post('/editorial/posts/{post}/publish', [CmsController::class, 'publishApprovedPost'])->middleware('permission:cms.posts.publish')->name('editorial.posts.publish');
    });

Route::middleware(['web', 'installed', 'auth', 'plugin:cms-website', 'permission:cms.authors.manage'])
    ->prefix('admin/cms')
    ->name('plugins.cms.')
    ->group(function (): void {
        Route::get('/contributors', [CmsController::class, 'contributorsIndex'])->name('contributors.index');
        Route::put('/contributors/{user}', [CmsController::class, 'updateContributorAccess'])->name('contributors.update');
    });
