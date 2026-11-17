@inject('siteWidgetManager', 'App\\Core\\Appearance\\SiteWidgetManager')
@php($areaWidgets = collect($siteWidgetManager->all())->where('area', $area)->where('visible', true)->sortBy('order'))
@if ($areaWidgets->isNotEmpty())
    <div class="space-y-4" data-widget-area="{{ $area }}">
        @foreach ($areaWidgets as $widget)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" data-widget-type="{{ $widget['type'] }}">
                <h2 class="text-sm font-extrabold text-slate-900">{{ $widget['title'] }}</h2>
                @if ($widget['type'] === 'text')
                    <p class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-600">{{ $widget['content'] }}</p>
                @elseif ($widget['type'] === 'notice')
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">{{ $widget['content'] }}</div>
                @elseif ($widget['type'] === 'search')
                    <form method="GET" action="{{ route('public.courses') }}" class="mt-3 flex gap-2">
                        <label class="sr-only" for="widget-course-search-{{ $loop->index }}">Search courses</label>
                        <input id="widget-course-search-{{ $loop->index }}" name="q" type="search" placeholder="Search courses..." class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white" type="submit">Search</button>
                    </form>
                @elseif ($widget['type'] === 'cta' && $widget['url'])
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $widget['content'] }}</p>
                    <a href="{{ $widget['url'] }}" @if (str_starts_with($widget['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif class="mt-3 inline-flex rounded-lg bg-blue-600 px-4 py-2.5 text-xs font-bold text-white">{{ $widget['label'] }}</a>
                @endif
            </section>
        @endforeach
    </div>
@endif
