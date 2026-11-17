@extends('layouts.admin')
@section('title', 'Admin Menu Editor — AEC LMS')
@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">Admin Menu Editor</h1>
            <p class="mt-1 text-sm text-slate-500">Organize plugin navigation, rename menu groups, change display order, or hide entries without editing code.</p>
        </div>
        <a href="{{ route('admin.dashboard') }}" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Back to Dashboard</a>
    </div>

    <form method="POST" action="{{ route('admin.menu-editor.update') }}" class="space-y-4">
        @csrf
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="font-bold text-slate-900">Plugin Navigation Items</h2>
                    <p class="text-xs text-slate-500">Lower order numbers appear first within each group. Group names can be changed.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ count($items) }} available entries</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Menu Item</th>
                            <th class="px-3 py-3">Group</th>
                            <th class="px-3 py-3 w-28">Order</th>
                            <th class="px-3 py-3 w-32">Visibility</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($items as $index => $item)
                            <tr>
                                <td class="px-5 py-3">
                                    <input type="hidden" name="items[{{ $index }}][key]" value="{{ $item['plugin'].'|'.$item['route'] }}">
                                    <div class="font-semibold text-slate-800">{{ $item['label'] }}</div>
                                    <div class="mt-1 text-[11px] text-slate-400">{{ $item['plugin'] }} · {{ $item['route'] }}</div>
                                </td>
                                <td class="px-3 py-3">
                                    <input name="items[{{ $index }}][group]" value="{{ $item['group'] }}" maxlength="60" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                </td>
                                <td class="px-3 py-3">
                                    <input type="number" name="items[{{ $index }}][order]" value="{{ $item['order'] }}" min="-1000" max="1000" required class="w-24 rounded-lg border border-slate-300 px-2 py-2 text-sm tabular-nums">
                                </td>
                                <td class="px-3 py-3">
                                    <select name="items[{{ $index }}][visibility]" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
                                        <option value="show" @selected(empty($item['hidden']))>Show</option>
                                        <option value="hide" @selected(!empty($item['hidden']))>Hide</option>
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500">No enabled plugin menu entries are available for your account.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-2xl text-xs leading-5 text-slate-500">This editor changes plugin navigation only. Protected Core links, plugin permissions, and route access controls remain enforced independently. Hidden links are not deleted and can be shown again here.</p>
            <button type="submit" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-blue-700">Save Menu Settings</button>
        </div>
    </form>
</div>
@endsection
