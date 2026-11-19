@php($loaderSetting=\Plugins\Modules\AlBrandingLoader\Models\LoaderSetting::current())
@if($loaderSetting->shouldRenderForPath(request()->path()))
<div id="al-branding-loader" class="lb-style-{{ $loaderSetting->animation_style }}" role="status" aria-live="polite" aria-label="{{ $loaderSetting->headline }}" data-min="{{ $loaderSetting->min_duration_ms??500 }}" data-fade="{{ $loaderSetting->fade_duration_ms??250 }}" data-max="{{ $loaderSetting->max_timeout_ms??5000 }}" data-trigger="{{ $loaderSetting->trigger_mode??'every-load' }}" data-excluded-paths="{{ e(json_encode((array)($loaderSetting->excluded_paths??[]))) }}" style="--lb-bg:{{ $loaderSetting->background_color??'#F01800' }};--lb-accent:{{ $loaderSetting->accent_color??'#38BDF8' }};--lb-text:{{ $loaderSetting->text_color??'#F8FAFC' }};--lb-fade:{{ $loaderSetting->fade_duration_ms??250 }}ms;backdrop-filter:{{ $loaderSetting->backdrop_blur?'blur(8px)':'none' }}">
 @if($loaderSetting->logo_url)<img class="lb-logo" src="{{ $loaderSetting->logo_url }}" alt="">@else
  @if($loaderSetting->animation_style==='dual-orbit')<div class="lb-orbits" aria-hidden="true"><i></i><b></b></div>@else<div class="lb-spin" aria-hidden="true"></div>@endif
 @endif
 <strong>{{ $loaderSetting->headline }}</strong>@if($loaderSetting->subtext)<span>{{ $loaderSetting->subtext }}</span>@endif
 @if($loaderSetting->show_progress_label)<div class="lb-track" aria-hidden="true"><i></i></div>@endif
</div>
<noscript><style>#al-branding-loader{display:none!important}</style></noscript>
<style>
#al-branding-loader{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:24px;background:var(--lb-bg);color:var(--lb-text);transition:opacity var(--lb-fade) ease,visibility var(--lb-fade) ease}
#al-branding-loader.lb-hidden{opacity:0;visibility:hidden;pointer-events:none}#al-branding-loader>*{position:relative;z-index:1}
.lb-logo{width:64px;height:64px;object-fit:contain}
.lb-spin{width:44px;height:44px;border:3px solid #ffffff55;border-top-color:var(--lb-accent);border-radius:50%;animation:lb-spin .8s linear infinite}
.lb-orbits{position:relative;width:48px;height:48px}.lb-orbits i,.lb-orbits b{position:absolute;inset:0;border:3px solid transparent;border-top-color:var(--lb-accent);border-radius:50%;animation:lb-spin .9s linear infinite}.lb-orbits b{inset:8px;border-top-color:var(--lb-text);animation-direction:reverse}
.lb-track{width:min(220px,70vw);height:3px;background:#ffffff44;overflow:hidden;border-radius:8px}.lb-track i{display:block;width:40%;height:100%;background:var(--lb-accent);animation:lb-sweep 1.2s ease-in-out infinite}
.lb-style-minimal-pulse .lb-spin{animation:lb-pulse 1.1s ease-in-out infinite}.lb-style-shimmer-sweep:before{content:"";position:absolute;inset:0;background:linear-gradient(110deg,transparent 20%,#ffffff22 48%,transparent 76%);background-size:220% 100%;animation:lb-shimmer 2s linear infinite;pointer-events:none}.lb-style-curtain-reveal{transform:translateY(0);transition:transform var(--lb-fade) ease,opacity var(--lb-fade) ease}.lb-style-curtain-reveal.lb-hidden{transform:translateY(-100%)}
@keyframes lb-spin{to{transform:rotate(360deg)}}@keyframes lb-sweep{to{transform:translateX(260%)}}@keyframes lb-pulse{50%{transform:scale(.82);opacity:.55}}@keyframes lb-shimmer{to{background-position-x:-220%}}
@media(prefers-reduced-motion:reduce){#al-branding-loader.lb-respect-motion{transition-duration:.01ms!important;scroll-behavior:auto!important}#al-branding-loader.lb-respect-motion .lb-spin,#al-branding-loader.lb-respect-motion .lb-orbits i,#al-branding-loader.lb-respect-motion .lb-orbits b,#al-branding-loader.lb-respect-motion .lb-track i,#al-branding-loader.lb-respect-motion.lb-style-shimmer-sweep:before{animation:none!important;transition:none!important}}
</style>
<script>
(function () {
    'use strict';

    const overlay = document.getElementById('al-branding-loader');
    if (!overlay) return;

    const mode = overlay.dataset.trigger || 'every-load';
    const storageKey = 'al-loader-seen';
    let configuredExcludedPaths = [];
    try { configuredExcludedPaths = JSON.parse(overlay.dataset.excludedPaths || '[]'); } catch (error) {}
    function matchesConfiguredExclusion(pathname) {
        let path = String(pathname || '');
        while (path.startsWith('/')) path = path.slice(1);
        return configuredExcludedPaths.some(function (rawPattern) {
            let pattern = String(rawPattern || '');
            while (pattern.startsWith('/')) pattern = pattern.slice(1);
            while (pattern.endsWith('/')) pattern = pattern.slice(0, -1);
            if (!pattern || pattern.includes('..')) return false;
            const parts = pattern.split('*');
            let offset = 0;
            for (let index = 0; index < parts.length; index++) {
                const part = parts[index];
                const found = path.indexOf(part, offset);
                if (found < 0 || (index === 0 && found !== 0)) return false;
                offset = found + part.length;
            }
            return pattern.endsWith('*') || offset === path.length;
        });
    }
    const minDuration = Math.max(0, Number(overlay.dataset.min || 0));
    const maxDuration = Math.max(1000, Number(overlay.dataset.max || 5000));
    let startedAt = Date.now();
    let completed = false;
    let maxTimer = null;
    let hideTimer = null;

    if (@json((bool) $loaderSetting->respect_reduced_motion)) {
        overlay.classList.add('lb-respect-motion');
    }

    try {
        if (mode === 'first-visit-session' && sessionStorage.getItem(storageKey)) {
            overlay.remove();
            return;
        }
        if (mode === 'first-visit-daily' && localStorage.getItem(storageKey) === new Date().toDateString()) {
            overlay.remove();
            return;
        }
    } catch (error) {
        // Storage may be unavailable in private browsing; keep the loader functional.
    }

    function hideAfterMinimumDuration(markVisit) {
        if (completed) return;
        completed = true;
        const elapsed = Date.now() - startedAt;
        const remaining = Math.min(
            Math.max(0, minDuration - elapsed),
            Math.max(0, maxDuration - elapsed)
        );

        hideTimer = window.setTimeout(function () {
            overlay.classList.add('lb-hidden');
            overlay.setAttribute('aria-hidden', 'true');
            document.documentElement.removeAttribute('aria-busy');

            if (!markVisit) return;
            try {
                if (mode === 'first-visit-session') sessionStorage.setItem(storageKey, '1');
                if (mode === 'first-visit-daily') localStorage.setItem(storageKey, new Date().toDateString());
            } catch (error) {
                // A storage failure must never prevent the page from becoming usable.
            }
        }, remaining);
    }

    function forceHide() {
        if (completed) return;
        completed = true;
        if (hideTimer !== null) window.clearTimeout(hideTimer);
        overlay.classList.add('lb-hidden');
        overlay.setAttribute('aria-hidden', 'true');
        document.documentElement.removeAttribute('aria-busy');
    }

    document.documentElement.setAttribute('aria-busy', 'true');
    window.addEventListener('load', function () {
        if (maxTimer !== null) window.clearTimeout(maxTimer);
        hideAfterMinimumDuration(true);
    }, { once: true });

    // A broken asset or stalled request must not leave the whole LMS covered forever.
    maxTimer = window.setTimeout(forceHide, maxDuration);

    document.addEventListener('click', function (event) {
        if (mode !== 'every-load' || event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const target = event.target instanceof Element ? event.target : event.target?.parentElement;
        const link = target?.closest('a[href]');
        if (!link || link.target || link.hasAttribute('download') ||
            link.getAttribute('rel')?.split(/\s+/).includes('external')) return;

        let destination;
        try {
            destination = new URL(link.href, window.location.href);
        } catch (error) {
            return;
        }

        if (destination.origin !== window.location.origin ||
            destination.pathname === window.location.pathname ||
            matchesConfiguredExclusion(destination.pathname) ||
            /^\/(admin|api|payment|payments|checkout|webhook|webhooks|live-class|live-classes)(\/|$)/.test(destination.pathname)) return;

        completed = false;
        if (hideTimer !== null) window.clearTimeout(hideTimer);
        startedAt = Date.now();
        overlay.classList.remove('lb-hidden');
        overlay.removeAttribute('aria-hidden');
        document.documentElement.setAttribute('aria-busy', 'true');
        if (maxTimer !== null) window.clearTimeout(maxTimer);
        maxTimer = window.setTimeout(forceHide, maxDuration);
    }, true);
})();
</script>
@endif