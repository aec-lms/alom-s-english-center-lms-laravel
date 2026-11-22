@extends('layouts.admin')

@section('title', __('plugins.scorm::messages.admin_title'))

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ __('plugins.scorm::messages.admin_title') }}</h1>
            <p class="text-muted mb-0">{{ __('plugins.scorm::messages.admin_description') }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-header"><h2 class="h5 mb-0">{{ __('plugins.scorm::messages.import_package') }}</h2></div>
        <div class="card-body">
            @if ($courses->isEmpty())
                <div class="alert alert-info mb-0">{{ __('plugins.scorm::messages.enable_course_management') }}</div>
            @else
                <form method="POST" action="{{ route('plugins.scorm.admin.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-lg-5">
                            <label for="course_id" class="form-label">{{ __('plugins.scorm::messages.select_course') }}</label>
                            <select id="course_id" name="course_id" class="form-select" required>
                                <option value="">{{ __('plugins.scorm::messages.select_published_course') }}</option>
                                @foreach ($courses as $course)
                                    <option value="{{ $course->id }}" @selected(old('course_id') == $course->id)>{{ $course->title }}</option>
                                @endforeach
                            </select>
                            @error('course_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-lg-5">
                            <label for="replace_package_id" class="form-label">{{ __('plugins.scorm::messages.replace_package') }}</label>
                            <select id="replace_package_id" name="replace_package_id" class="form-select">
                                <option value="">{{ __('plugins.scorm::messages.new_package') }}</option>
                                @foreach ($replacementOptions as $option)
                                    <option value="{{ $option->id }}" @selected(old('replace_package_id') == $option->id)>{{ $option->course_title }} — {{ $option->title }} (revision {{ $option->revision_number }})</option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('plugins.scorm::messages.replacement_help') }}</div>
                            @error('replace_package_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-lg-5">
                            <label for="package" class="form-label">{{ __('plugins.scorm::messages.zip_file') }}</label>
                            <input id="package" name="package" type="file" class="form-control" accept=".zip,application/zip" required>
                            <div class="form-text">{{ __('plugins.scorm::messages.upload_limit') }}</div>
                            @error('package')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-lg-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('plugins.scorm::messages.import') }}</button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><h2 class="h5 mb-0">{{ __('plugins.scorm::messages.package_library') }}</h2></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>{{ __('plugins.scorm::messages.package') }}</th><th>{{ __('plugins.scorm::messages.course_id') }}</th><th>{{ __('plugins.scorm::messages.files') }}</th><th>{{ __('plugins.scorm::messages.expanded_size') }}</th><th>{{ __('plugins.scorm::messages.attempts') }}</th><th>{{ __('plugins.scorm::messages.completed') }}</th><th>{{ __('plugins.scorm::messages.active') }}</th><th>{{ __('plugins.scorm::messages.average_score') }}</th><th>{{ __('plugins.scorm::messages.status') }}</th><th class="text-end">{{ __('plugins.scorm::messages.actions') }}</th></tr></thead>
                <tbody>
                @forelse ($packages as $package)
                    <tr>
                        <td><strong>{{ $package->title }}</strong><div class="small text-muted">SCORM {{ $package->version }} · {{ __('plugins.scorm::messages.revision') }} {{ $package->revision_number }} · {{ $package->created_at }}</div>
                            @if ($package->replaces_package_id)
                                <div class="small text-muted">{{ __('plugins.scorm::messages.replaces') }} #{{ $package->replaces_package_id }}</div>
                            @endif</td>
                        <td>{{ $package->course_id }}</td>
                        <td>{{ number_format($package->file_count) }}</td>
                        <td>{{ number_format($package->expanded_bytes / 1048576, 1) }} MB</td>
                        <td>{{ number_format($package->attempts_count) }}</td>
                        <td>{{ number_format($package->completed_count) }}</td>
                        <td>{{ number_format($package->active_count) }}</td>
                        <td>{{ $package->average_score === null ? '—' : number_format((float) $package->average_score, 1) . '%' }}</td>
                        <td><span class="badge text-bg-{{ $package->status === 'published' ? 'success' :  (in_array($package->status, ['archived', 'superseded'], true) ? 'secondary' : 'warning') }}">{{ __('plugins.scorm::messages.status_' . $package->status) }}</span></td>
                        <td class="text-end">
                            @if ($package->status === 'draft')
                                <form method="POST" action="{{ route('plugins.scorm.admin.publish', $package->id) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-success">{{ __('plugins.scorm::messages.publish') }}</button>
                                </form>
                            @elseif ($package->status === 'published')
                                <form method="POST" action="{{ route('plugins.scorm.admin.archive', $package->id) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('plugins.scorm::messages.archive') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-5">{{ __('plugins.scorm::messages.no_packages') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $packages->links() }}</div>
    </div>
</div>
@endsection
