@extends('layouts.admin')
@section('title', 'Guardian Management — AEC LMS')
@section('content')
<div class="space-y-6 max-w-5xl">
    <h1 class="text-2xl font-extrabold text-slate-900">Guardian & Parent Portal</h1>
    <div class="bg-white rounded-2xl border border-slate-200 p-5">
        <table class="w-full text-xs text-left">
            <thead class="text-slate-500 border-b"><tr><th class="py-2">Guardian</th><th>Email</th><th>Relationship</th><th>Branch</th><th>Linked Students</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($guardians as $guardian)
                    <tr>
                        <td class="py-2 font-semibold">{{ $guardian->user?->name }}</td>
                        <td>{{ $guardian->user?->email }}</td>
                        <td>{{ $guardian->relationship }}</td>
                        <td>{{ $guardian->branch_id }}</td>
                        <td>{{ $guardian->students->map(fn ($s) => $s->student_code)->implode(', ') ?: 'None' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-slate-400">No guardians registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
