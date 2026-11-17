@extends('layouts.admin')
@section('title', 'Flash Sale Campaigns')
@section('content')
<div class="container-fluid py-4">
    <h1 class="h3 mb-3">Flash Sale Campaigns</h1>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card mb-3">
        <div class="card-header">Public page settings</div>
        <div class="card-body">
            <form method="post" action="{{ url('/admin/flash-sales/page-settings') }}">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="flash-sale-page-title">Page title</label>
                        <input id="flash-sale-page-title" class="form-control" name="page_title" value="{{ $settings->page_title ?? 'Flash Sale' }}" required>
                    </div>
                    <div class="col-md-3">
                        <input type="hidden" name="page_enabled" value="0">
                        <label><input type="checkbox" name="page_enabled" value="1" @checked($settings?->page_enabled ?? true)> Page active</label>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary">Save settings</button></div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Create Flash Sale</div>
        <div class="card-body">
            <form method="post" action="{{ url('/admin/flash-sales') }}">
                @csrf
                <div class="row g-2">
                    <div class="col-md-3"><label class="form-label">Campaign title</label><input class="form-control" name="title" required maxlength="160"></div>
                    <div class="col-md-3"><label class="form-label">Course</label><select class="form-select" name="course_id" required><option value="">Choose course</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label class="form-label">Discount type</label><select class="form-select" name="discount_type"><option value="percent">Percent</option><option value="fixed">Fixed amount</option></select></div>
                    <div class="col-md-2"><label class="form-label">Discount value</label><input class="form-control" type="number" step="0.01" min="0.01" name="discount_value" required></div>
                    <div class="col-md-3"><label class="form-label">Starts at</label><input class="form-control" type="datetime-local" name="starts_at" required></div>
                    <div class="col-md-3"><label class="form-label">Ends at</label><input class="form-control" type="datetime-local" name="ends_at" required></div>
                    <div class="col-md-2"><label><input type="checkbox" name="is_published" value="1"> Published</label></div>
                    <div class="col-md-2"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
                </div>
                <button class="btn btn-success mt-3">Create campaign</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Campaign list</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Campaign</th><th>Course</th><th>Discount</th><th>Schedule</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($sales as $sale)
                    <tr>
                        <td>{{ $sale->title }}</td>
                        <td>{{ $sale->course_title ?? 'Course integration unavailable' }}</td>
                        <td>{{ $sale->discount_type }} {{ $sale->discount_value }}</td>
                        <td>{{ $sale->starts_at }} – {{ $sale->ends_at }}</td>
                        <td>{{ $sale->is_published ? 'Published' : 'Draft' }} / {{ $sale->is_active ? 'Active' : 'Inactive' }}</td>
                        <td>
                            <details class="mb-2">
                                <summary class="btn btn-sm btn-outline-primary d-inline-block">Edit</summary>
                                <form class="border rounded p-3 mt-2" method="post" action="{{ url('/admin/flash-sales/'.$sale->id) }}">
                                    @csrf @method('PUT')
                                    <div class="row g-2">
                                        <div class="col-md-4"><label class="form-label">Title</label><input class="form-control" name="title" value="{{ $sale->title }}" required></div>
                                        <div class="col-md-4"><label class="form-label">Course</label><select class="form-select" name="course_id" required>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((int)$course->id === (int)$sale->course_id)>{{ $course->title }}</option>@endforeach</select></div>
                                        <div class="col-md-2"><label class="form-label">Type</label><select class="form-select" name="discount_type"><option value="percent" @selected($sale->discount_type === 'percent')>Percent</option><option value="fixed" @selected($sale->discount_type === 'fixed')>Fixed</option></select></div>
                                        <div class="col-md-2"><label class="form-label">Value</label><input class="form-control" type="number" step="0.01" min="0.01" name="discount_value" value="{{ $sale->discount_value }}" required></div>
                                        <div class="col-md-3"><label class="form-label">Starts at</label><input class="form-control" type="datetime-local" name="starts_at" value="{{ \Illuminate\Support\Carbon::parse($sale->starts_at)->format('Y-m-d\\TH:i') }}" required></div>
                                        <div class="col-md-3"><label class="form-label">Ends at</label><input class="form-control" type="datetime-local" name="ends_at" value="{{ \Illuminate\Support\Carbon::parse($sale->ends_at)->format('Y-m-d\\TH:i') }}" required></div>
                                        <div class="col-md-2"><label><input type="checkbox" name="is_published" value="1" @checked($sale->is_published)> Published</label></div>
                                        <div class="col-md-2"><label><input type="checkbox" name="is_active" value="1" @checked($sale->is_active)> Active</label></div>
                                    </div>
                                    <button class="btn btn-primary btn-sm mt-2">Save changes</button>
                                </form>
                            </details>
                            <form class="d-inline" method="post" action="{{ url('/admin/flash-sales/'.$sale->id.'/toggle') }}">@csrf<button class="btn btn-sm btn-warning">{{ $sale->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                            <form class="d-inline" method="post" action="{{ url('/admin/flash-sales/'.$sale->id) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" onclick="return confirm('Delete this campaign?')">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted text-center py-4">No Flash Sale campaigns yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $sales->links() }}</div>
    </div>
</div>
@endsection
