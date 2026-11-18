@php($loaderSetting=\Plugins\Modules\AlBrandingLoader\Models\LoaderSetting::current())
@if($loaderSetting->shouldRenderForPath(request()->path()))
<div id="al-branding-loader" class="lb-style-{{ $loaderSetting->animation_style }}" role="status" aria-live="polite" aria-label="{{ $loaderSetting->headline }}" data-min="{{ $loaderSetting->min_duration_ms??500 }}" data-fade="{{ $loaderSetting->fade_duration_ms??250 }}" data-max="{{ $loaderSetting->max_timeout_ms??5000 }}" data-trigger="{{ $loaderSetting->trigger_mode??'every-load' }}" style="--lb-bg:{{ $loaderSetting->background_color??'#F01800' }};--lb-accent:{{ $loaderSetting->accent_color??'#38BDF8' }};--lb-text:{{ $loaderSetting->text_color??'#F8FAFC' }};--lb-fade:{{ $loaderSetting->fade_duration_ms??250 }}ms">
 @if($loaderSetting->logo_url)<img class="lb-logo" src="{{ $loaderSetting->logo_url }}" alt="">@else
  @if($loaderSetting->animation_style==='dual-orbit')<div class="lb-orbits" aria-hidden="true"><i></i><b></b></div>@else<div class="lb-spin" aria-hidden="true"></div>@endif
 @endif
 <strong>{{ $loaderSetting->headline }}</strong>@if($loaderSetting->subtext)<span>{{ $loaderSetting->subtext }}</span>@endif
 @if($loaderSetting->show_progress_label)<div class="lb-track" aria-hidden="true"><i></i></div>@endif
</div>
<noscript><style>#al-branding-loader{display:none!important}</style></noscript>
<style>
#al-branding-loader{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:24px;background:var(--lb-bg);color:var(--lb-text);transition:opacity var(--lb-fade) ease,visibility var(--lb-fade) ease}
#al-branding-loader.lb-hidden{opacity:0;visibility:hidden;pointer-events:none}
.lb-logo{width:64px;height:64px;object-fit:contain}
.lb-spin{width:44px;height:44px;border:3px solid #ffffff55;border-top-color:var(--lb-accent);border-radius:50%;animation:lb-spin .8s linear infinite}
.lb-orbits{position:relative;width:48px;height:48px}.lb-orbits i,.lb-orbits b{position:absolute;inset:0;border:3px solid transparent;border-top-color:var(--lb-accent);border-radius:50%;animation:lb-spin .9s linear infinite}.lb-orbits b{inset:8px;border-top-color:var(--lb-text);animation-direction:reverse}
.lb-track{width:min(220px,70vw);height:3px;background:#ffffff44;overflow:hidden;border-radius:8px}.lb-track i{display:block;width:40%;height:100%;background:var(--lb-accent);animation:lb-sweep 1.2s ease-in-out infinite}
.lb-style-minimal-pulse .lb-spin{animation:lb-pulse 1.1s ease-in-out infinite}.lb-style-curtain-reveal{transform:translateY(0);transition:transform var(--lb-fade) ease,opacity var(--lb-fade) ease}.lb-style-curtain-reveal.lb-hidden{transform:translateY(-100%)}
@keyframes lb-spin{to{transform:rotate(360deg)}}@keyframes lb-sweep{to{transform:translateX(260%)}}@keyframes lb-pulse{50%{transform:scale(.82);opacity:.55}}
@media(prefers-reduced-motion:reduce){#al-branding-loader.lb-respect-motion .lb-spin,#al-branding-loader.lb-respect-motion .lb-orbits i,#al-branding-loader.lb-respect-motion .lb-orbits b,#al-branding-loader.lb-respect-motion .lb-track i{animation:none}}
</style>
<script>
(function(){
 const o=document.getElementById('al-branding-loader');if(!o)return;
 if(@json((bool)$loaderSetting->respect_reduced_motion))o.classList.add('lb-respect-motion');
 const mode=o.dataset.trigger,key='al-loader-seen';
 try{if(mode==='first-visit-session'&&sessionStorage.getItem(key)){o.remove();return}if(mode==='first-visit-daily'&&localStorage.getItem(key)===new Date().toDateString()){o.remove();return}}catch(e){}
 const start=Date.now(),hide=()=>{const delay=Math.max(0,Number(o.dataset.min||0)-(Date.now()-start));setTimeout(()=>o.classList.add('lb-hidden'),delay)};
 addEventListener('load',hide,{once:true});setTimeout(hide,Math.max(1000,Number(o.dataset.max||5000)));
 try{if(mode==='first-visit-session')sessionStorage.setItem(key,'1');if(mode==='first-visit-daily')localStorage.setItem(key,new Date().toDateString())}catch(e){}
 document.addEventListener('click',e=>{if(mode!=='every-load')return;const a=e.target.closest('a[href]');if(!a||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||a.target||a.hasAttribute('download'))return;const u=new URL(a.href,location.href);if(u.origin!==location.origin||u.pathname===location.pathname||/^\/(admin|api|payment|payments|checkout|webhook|webhooks|live-class|live-classes)(\/|$)/.test(u.pathname))return;o.classList.remove('lb-hidden')},true);
})();
</script>
@endif