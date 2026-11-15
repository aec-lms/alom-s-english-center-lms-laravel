@php
    $roleSlug = auth()->user()?->roles()->first()?->slug ?? 'admin';
    $roleLabel = ucfirst(str_replace('_', ' ', $roleSlug));
@endphp
<div id="aec-panel-ai-root" class="fixed bottom-5 right-5 z-50">
    <button
        type="button"
        id="aec-ai-toggle"
        class="inline-flex items-center gap-2 px-4 py-3 rounded-full bg-[#0057FF] hover:bg-blue-700 text-white text-xs font-bold shadow-xl transition-transform hover:scale-105"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/></svg>
        <span>{{ $roleLabel }} AI Assistant</span>
    </button>

    <div id="aec-ai-drawer" class="hidden mt-3 w-[340px] sm:w-[390px] bg-white rounded-2xl border border-slate-200 shadow-2xl overflow-hidden">
        <div class="bg-[#022233] text-white px-4 py-3 flex items-center justify-between">
            <div>
                <div class="text-xs font-extrabold">{{ $roleLabel }} Panel AI Assistant</div>
                <div class="text-[10px] text-sky-300">Role-scoped intelligent helper</div>
            </div>
            <button type="button" id="aec-ai-close" class="text-slate-300 hover:text-white text-xs font-bold px-2 py-1">Close</button>
        </div>
        <div id="aec-ai-messages" class="p-3 space-y-2.5 max-h-72 overflow-y-auto text-xs bg-slate-50">
            <div class="bg-white border border-slate-200 rounded-xl p-2.5 text-slate-700">
                আসসালামু আলাইকুম! আমি আপনার <strong>{{ $roleLabel }}</strong> প্যানেলের AI Assistant। যেকোনো কাজে প্রশ্ন করুন।
            </div>
        </div>
        <form id="aec-ai-form" class="p-3 border-t border-slate-200 bg-white flex items-center gap-2">
            <input id="aec-ai-input" type="text" required placeholder="Ask your {{ $roleLabel }} AI Assistant..." class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs">
            <button type="submit" class="px-3 py-2 rounded-lg bg-[#0057FF] text-white text-xs font-bold">Send</button>
        </form>
    </div>
</div>
<script>
(function () {
    var toggle = document.getElementById('aec-ai-toggle');
    var drawer = document.getElementById('aec-ai-drawer');
    var closeBtn = document.getElementById('aec-ai-close');
    var form = document.getElementById('aec-ai-form');
    var input = document.getElementById('aec-ai-input');
    var box = document.getElementById('aec-ai-messages');
    if (!toggle || !drawer || !form) return;

    toggle.addEventListener('click', function () { drawer.classList.toggle('hidden'); });
    closeBtn && closeBtn.addEventListener('click', function () { drawer.classList.add('hidden'); });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var prompt = (input.value || '').trim();
        if (!prompt) return;
        input.value = '';

        var u = document.createElement('div');
        u.className = 'bg-[#0057FF] text-white rounded-xl p-2.5 ml-6';
        u.textContent = prompt;
        box.appendChild(u);
        box.scrollTop = box.scrollHeight;

        fetch("{{ route('plugins.ai.ask') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') || ''
            },
            body: JSON.stringify({ prompt: prompt })
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
            var a = document.createElement('div');
            a.className = 'bg-white border border-slate-200 rounded-xl p-2.5 mr-4 text-slate-700 whitespace-pre-wrap';
            a.textContent = res.ok ? (res.d.reply || 'Done.') : (res.d.error || 'Assistant unavailable.');
            box.appendChild(a);
            box.scrollTop = box.scrollHeight;
        })
        .catch(function () {
            var err = document.createElement('div');
            err.className = 'bg-rose-50 border border-rose-200 rounded-xl p-2.5 text-rose-700';
            err.textContent = 'Network error reaching AI endpoint.';
            box.appendChild(err);
        });
    });
})();
</script>
