@extends('layouts.admin')
@section('title', 'Plugin Center — AEC LMS')
@section('content')
<div class="space-y-6">
    <div class="bg-white rounded-2xl border border-slate-200 p-5 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold">
                Core LMS + Independent Plugin Architecture · Zero Dependencies
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-1">Plugin Center (Install, Update, Uninstall)</h1>
            <p class="text-xs text-slate-500 mt-0.5">Every module works independently on top of AEC Core LMS. Install, update, enable, disable, or uninstall any plugin without breaking or blocking others.</p>
        </div>

        <form method="POST" action="{{ route('admin.plugins.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl p-2.5">
            @csrf
            <input type="file" name="plugin_zip" accept=".zip" required class="text-xs text-slate-600 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-slate-200 file:text-slate-700 file:font-semibold">
            <button type="submit" class="px-3.5 py-2 rounded-lg bg-[#0057FF] hover:bg-blue-700 text-white text-xs font-bold">
                Upload & Install / Update (.zip)
            </button>
        </form>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($records as $plugin)
            <div class="bg-white rounded-2xl border border-slate-200 p-5 flex flex-col justify-between space-y-4">
                <div class="space-y-2">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-bold text-slate-900">{{ $plugin->name }}</h2>
                            <div class="text-[11px] text-slate-500">v{{ $plugin->version }} · {{ $plugin->author }} · <span class="uppercase">{{ $plugin->category }}</span></div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $plugin->is_enabled ? 'bg-emerald-100 text-emerald-800' : ($plugin->is_installed ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') }}">
                            {{ $plugin->is_enabled ? 'Active' : ($plugin->is_installed ? 'Installed (Disabled)' : 'Available') }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-600">{{ $plugin->description }}</p>
                    <div class="text-[11px] text-slate-500 space-y-1">
                        <div>Core compatibility: <strong>{{ $plugin->core_compatibility }}</strong> ({{ $plugin->is_compatible ? 'Compatible' : 'Incompatible' }})</div>
                        <div>
                            Dependencies:
                            @if (!empty($plugin->dependencies))
                                <span class="font-mono text-amber-700 font-semibold">{{ implode(', ', (array) $plugin->dependencies) }}</span>
                            @else
                                <span class="text-emerald-700 font-semibold">None (Standalone)</span>
                            @endif
                        </div>
                        @if ($plugin->recent_logs && $plugin->recent_logs->isNotEmpty())
                            <div class="pt-1 text-[10px] text-slate-400">
                                Last log: <span class="font-semibold {{ $plugin->recent_logs->first()->status === 'applied' ? 'text-emerald-600' : 'text-rose-600' }}">{{ strtoupper($plugin->recent_logs->first()->status) }}</span>
                                — {{ \Illuminate\Support\Str::limit($plugin->recent_logs->first()->notes, 60) }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 pt-3 border-t border-slate-100 text-xs">
                    @if (!$plugin->is_installed)
                        <form method="POST" action="{{ route('admin.plugins.install', $plugin->slug) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-lg bg-sky-600 text-white font-semibold hover:bg-sky-700">Install</button>
                        </form>
                    @else
                        @if (!$plugin->is_enabled)
                            <form method="POST" action="{{ route('admin.plugins.enable', $plugin->slug) }}">
                                @csrf
                                <button class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-700">Enable</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.plugins.disable', $plugin->slug) }}">
                                @csrf
                                <button class="px-3 py-1.5 rounded-lg bg-amber-500 text-white font-semibold hover:bg-amber-600">Disable</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('admin.plugins.update', $plugin->slug) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-lg {{ $plugin->update_available ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-indigo-200 text-indigo-700 hover:bg-indigo-50' }} font-semibold">
                                {{ $plugin->update_available ? 'Update to v' . $plugin->manifest_version : 'Update / Sync' }}
                            </button>
                        </form>

                        @if ($plugin->has_settings)
                            <a href="{{ route('admin.plugins.configure', $plugin->slug) }}" class="px-3 py-1.5 rounded-lg border border-slate-300 text-slate-700 font-semibold hover:bg-slate-50">Configure</a>
                        @endif

                        <form method="POST" action="{{ route('admin.plugins.uninstall', $plugin->slug) }}" onsubmit="return confirm('Uninstall {{ $plugin->name }}? Shared LMS records will be preserved.')">
                            @csrf
                            <input type="hidden" name="confirm_uninstall" value="1">
                            <button class="px-3 py-1.5 rounded-lg border border-rose-300 text-rose-700 font-semibold hover:bg-rose-50">Uninstall</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
