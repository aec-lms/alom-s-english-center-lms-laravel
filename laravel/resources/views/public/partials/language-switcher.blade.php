<div class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white/90 p-1 text-xs font-semibold shadow-sm" aria-label="{{ __('Language') }}">
    <form method="POST" action="{{ route('language.switch', 'en') }}">
        @csrf
        <button type="submit" aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}" class="rounded-full px-2.5 py-1.5 transition {{ app()->getLocale() === 'en' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">EN</button>
    </form>
    <form method="POST" action="{{ route('language.switch', 'bn') }}">
        @csrf
        <button type="submit" aria-pressed="{{ app()->getLocale() === 'bn' ? 'true' : 'false' }}" class="rounded-full px-2.5 py-1.5 transition {{ app()->getLocale() === 'bn' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">বাংলা</button>
    </form>
</div>
