@extends('layouts.admin')

@section('title', 'SCORM Packages')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">SCORM 1.2 Packages</h1>
            <p class="text-muted mb-0">Import standards-based learning packages and attach them to published courses.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-header"><h2 class="h5 mb-0">Import a package</h2></div>
        <div class="card-body">
            @if ($courses->isEmpty())
                <div class="alert alert-info mb-0">Enable Course Management and publish a course before importing a SCORM package.</div>
            @else
                <form method="POST" action="{{ route('plugins.scorm.admin.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-lg-5">
                            <label for="course_id" class="form-label">Course</label>
                            <select id="course_id" name="course_id" class="form-select" required>
                                <option value="">Select a published course</option>
                                @foreach ($courses as $course)
                                    <option value="{{ $course->id }}" @selected(old('course_id') == $course->id)>{{ $course->title }}</option>
                                @endforeach
                            </select>
                            @error('course_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-lg-5">
                            <label for="package" class="form-label">SCORM ZIP file</label>
                            <input id="package" name="package" type="file" class="form-control" accept=".zip,application/zip" required>
                            <div class="form-text">Maximum upload size: 200 MB. SCORM 1.2 packages only.</div>
                            @error('package')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-lg-2">
                            <button type="submit" class="btn btn-primary w-100">Import package</button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><h2 class="h5 mb-0">Package library</h2></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Package</th><th>Course ID</th><th>Files</th><th>Expanded size</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse ($packages as $package)
                    <tr>
                        <td><strong>{{ $package->title }}</strong><div class="small text-muted">SCORM {{ $package->version }} · {{ $package->created_at }}</div></td>
                        <td>{{ $package->course_id }}</td>
                        <td>{{ number_format($package->file_count) }}</td>
                        <td>{{ number_format($package->expanded_bytes / 1048576, 1) }} MB</td>
                        <td><span class="badge text-bg-{{ $package->status === 'published' ? 'success' : ($package->status === 'archived' ? 'secondary' : 'warning') }}">{{ ucfirst($package->status) }}</span></td>
                        <td class="text-end">
                            @if ($package->status === 'draft')
                                <form method="POST" action="{{ route('plugins.scorm.admin.publish', $package->id) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-success">Publish</button>
                                </form>
                            @elseif ($package->status === 'published')
                                <form method="POST" action="{{ route('plugins.scorm.admin.archive', $package->id) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Archive</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">No SCORM packages have been imported yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $packages->links() }}</div>
    </div>
</div>
@endsection
