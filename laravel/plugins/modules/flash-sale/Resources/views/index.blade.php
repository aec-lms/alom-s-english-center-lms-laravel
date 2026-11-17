@extends('layouts.app')
@section('content')
<div class="container-fluid py-4">
    <h1>Flash Sale Campaigns</h1>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card mb-3">
        <div class="card-header">Public page settings</div>
        <div class="card-body">
            <form method="POST" action="{{ url('/admin/flash-sales/page-settings') }}" class="row g-2">
                @csrf
                <div class="col-md-6"><input class="form-control" name="page_title" maxlength="160" value="{{ $settings->page_title ?? 'Flash Sale' }}" required></div>
                <div class="col-md-3"><label><input type="hidden" name="page_enabled" value="0"><input type="checkbox" name="page_enabled" value="1" @checked($settings?->page_enabled ?? true)> Public page enabled</label></div>
                <div class="col-md-3"><button class="btn btn-primary">Save settings</button></div>
            </form>
        </div>
    </div>

    @if($courseIntegrationEnabled)
    <div class="card mb-3">
        <div class="card-header">Create campaign</div>
        <div class="card-body">
            <form method="POST" action="{{ url('/admin/flash-sales') }}" class="row g-2">
                @csrf
                <div class="col-md-3"><input class="form-control" name="title" maxlength="160" placeholder="Campaign title" required></div>
                <div class="col-md-3"><select class="form-select" name="course_id" required><option value="">Choose course</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->title }}</option>@endforeach</select></div>
                <div class="col-md-2"><select class="form-select" name="discount_type"><option value="percent">Percent</option><option value="fixed">Fixed amount</option></select></div>
                <div class="col-md-2"><input class="form-control" type="number" step="0.01" min="0.01" name="discount_value" placeholder="Discount" required></div>
                <div class="col-md-2"><input class="form-control" type="datetime-local" name="starts_at" required></div>
                <div class="col-md-2"><input class="form-control" type="datetime-local" name="ends_at" required></div>
                <div class="col-md-2"><label><input type="checkbox" name="is_published" value="1"> Published</label></div>
                <div class="col-md-2"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
                <div class="col-md-2"><button class="btn btn-success">Create campaign</button></div>
            </form>
        </div>
    </div>
    @else
        <div class="alert alert-warning">Enable the optional Course Management plugin to create or publish course-specific campaigns. Existing campaign records remain preserved.</div>
    @endif

    <div class="card">
        <div class="card-header">Campaigns</div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Campaign</th><th>Course</th><th>Discount</th><th>Schedule</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($sales as $sale)
                    <tr>
                        <td>{{ $sale->title }}</td>
                        <td>{{ $sale->course_title ?? ('Course #' . $sale->course_id) }}</td>
                        <td>{{ $sale->discount_type === 'percent' ? $sale->discount_value . '%' : '৳' . number_format((float)$sale->discount_value, 2) }}</td>
                        <td>{{ $sale->starts_at }}<br>to {{ $sale->ends_at }}</td>
                        <td>{{ $sale->is_published ? 'Published' : 'Draft' }} / {{ $sale->is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="text-nowrap">
                            <form class="d-inline" method="POST" action="{{ url('/admin/flash-sales/' . $sale->id . '/toggle') }}">@csrf<button class="btn btn-sm btn-warning">{{ $sale->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                            <form class="d-inline" method="POST" action="{{ url('/admin/flash-sales/' . $sale->id) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" onclick="return confirm('Delete this campaign?')">Delete</button></form>
                            @if($courseIntegrationEnabled)
                            <details class="mt-2">
                                <summary class="btn btn-sm btn-outline-primary">Edit</summary>
                                <form method="POST" action="{{ url('/admin/flash-sales/' . $sale->id) }}" class="border rounded p-3 mt-2 text-start">
                                    @csrf @method('PUT')
                                    <label class="form-label">Title<input class="form-control" name="title" value="{{ $sale->title }}" maxlength="160" required></label>
                                    <label class="form-label">Course<select class="form-select" name="course_id" required>@foreach($courses as $course)<option value="{{ $course->id }}" @selected((int)$sale->course_id === (int)$course->id)>{{ $course->title }}</option>@endforeach</select></label>
                                    <label class="form-label">Discount type<select class="form-select" name="discount_type"><option value="percent" @selected($sale->discount_type === 'percent')>Percent</option><option value="fixed" @selected($sale->discount_type === 'fixed')>Fixed amount</option></select></label>
                                    <label class="form-label">Discount value<input class="form-control" type="number" min="0.01" step="0.01" name="discount_value" value="{{ $sale->discount_value }}" required></label>
                                    <label class="form-label">Starts<input class="form-control" type="datetime-local" name="starts_at" value="{{ \Illuminate\Support\Carbon::parse($sale->starts_at)->format('Y-m-d\\TH:i') }}" required></label>
                                    <label class="form-label">Ends<input class="form-control" type="datetime-local" name="ends_at" value="{{ \Illuminate\Support\Carbon::parse($sale->ends_at)->format('Y-m-d\\TH:i') }}" required></label>
                                    <label><input type="checkbox" name="is_published" value="1" @checked($sale->is_published)> Published</label>
                                    <label class="ms-2"><input type="checkbox" name="is_active" value="1" @checked($sale->is_active)> Active</label>
                                    <button class="btn btn-primary btn-sm mt-2">Save changes</button>
                                </form>
                            </details>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No campaigns created yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $sales->links() }}
    </div>
</div>
@endsection
