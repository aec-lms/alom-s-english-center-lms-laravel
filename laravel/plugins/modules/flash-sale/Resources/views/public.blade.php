@extends('layouts.app')
@section('title', $settings->page_title)
@section('content')
<style>
.flash-page{--fp-ink:#171b2e;--fp-muted:#64748b;--fp-line:#e9eaf1;color:var(--fp-ink);padding-bottom:4rem}
.flash-page .fp-hero{position:relative;overflow:hidden;border-radius:28px;padding:clamp(28px,6vw,72px);color:#fff;background:radial-gradient(circle at 88% 10%,rgba(255,255,255,.25),transparent 22%),radial-gradient(circle at 0 100%,rgba(244,63,94,.3),transparent 35%),linear-gradient(125deg,#111827 0%,#312e81 55%,#9f1239 100%)}
.flash-page .fp-eyebrow{letter-spacing:.18em;text-transform:uppercase;font-size:.72rem;font-weight:800;opacity:.75}
.flash-page .fp-title{font-size:clamp(2.25rem,6vw,4.7rem);font-weight:900;letter-spacing:-.065em;line-height:1.02;max-width:850px}
.flash-page .fp-card{border:1px solid var(--fp-line);border-radius:22px;overflow:hidden;background:#fff;box-shadow:0 12px 35px rgba(15,23,42,.055);height:100%;transition:transform .2s ease,box-shadow .2s ease}
.flash-page .fp-card:hover{transform:translateY(-4px);box-shadow:0 18px 44px rgba(15,23,42,.1)}
.flash-page .fp-image{width:100%;height:210px;object-fit:cover;background:linear-gradient(130deg,#312e81,#be123c)}
.flash-page .fp-placeholder{height:210px;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,.22),transparent 25%),linear-gradient(130deg,#312e81,#9f1239);display:grid;place-items:center;color:white;font-size:2.4rem;font-weight:900}
.flash-page .fp-badge{display:inline-flex;border-radius:999px;background:#fff1f2;color:#be123c;padding:7px 12px;font-size:.72rem;font-weight:850;letter-spacing:.04em}
.flash-page .fp-price{font-size:1.8rem;font-weight:900;letter-spacing:-.05em}
.flash-page .fp-old{color:#94a3b8;text-decoration:line-through}
.flash-page .fp-timer{display:flex;gap:8px;flex-wrap:wrap}
.flash-page .fp-time{min-width:58px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);border-radius:12px;padding:9px 12px;text-align:center;backdrop-filter:blur(8px)}
.flash-page .fp-time strong{display:block;font-size:1.2rem;font-variant-numeric:tabular-nums}
.flash-page .fp-time span{font-size:.62rem;text-transform:uppercase;opacity:.72;letter-spacing:.08em}
.flash-page .fp-cta{border-radius:12px;padding:11px 18px;font-weight:750}

/* This layout extends the Tailwind-only public shell; provide the small page-scoped
   layout primitives used here instead of relying on an unloaded Bootstrap bundle. */
.flash-page,.flash-page *,.flash-page *::before,.flash-page *::after{box-sizing:border-box}
.flash-page{width:min(calc(100% - 2rem),1200px);max-width:1200px;margin:0 auto;padding:2rem 0 4rem}
.flash-page .container{width:100%;max-width:none;margin:0;padding:0}
.flash-page .row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;margin:0}
.flash-page .col-md-6,.flash-page .col-xl-4{min-width:0;width:auto;padding:0}
.flash-page .fp-hero{margin-bottom:3rem}
.flash-page .d-flex{display:flex}
.flash-page .flex-wrap{flex-wrap:wrap}
.flash-page .justify-content-between{justify-content:space-between}
.flash-page .align-items-center{align-items:center}
.flash-page .align-items-end{align-items:flex-end}
.flash-page .gap-2{gap:.5rem}
.flash-page .gap-3{gap:1rem}
.flash-page .mb-1{margin-bottom:.25rem}
.flash-page .mb-3{margin-bottom:1rem}
.flash-page .mb-4{margin-bottom:1.5rem}
.flash-page .mb-5{margin-bottom:3rem}
.flash-page .p-4{padding:1.5rem}
.flash-page .p-3{padding:1rem}
.flash-page .mb-0{margin-bottom:0}
.flash-page .mt-2{margin-top:.5rem}
.flash-page .fw-semibold{font-weight:650}
.flash-page .py-4{padding-top:1.5rem;padding-bottom:1.5rem}
.flash-page .fs-5{font-size:1.2rem;line-height:1.6}
.flash-page .fw-bold{font-weight:750}
.flash-page .small{font-size:.875rem}
.flash-page .text-secondary{color:#64748b}
.flash-page .text-danger{color:#be123c}
.flash-page .text-success{color:#15803d}
.flash-page .text-center{text-align:center}
.flash-page .text-bg-light{background:#f8fafc;color:#475569}
.flash-page .badge{display:inline-flex;align-items:center;font-size:.8rem;font-weight:700}
.flash-page .rounded-pill{border-radius:999px}
.flash-page .rounded-3{border-radius:14px}
.flash-page .rounded-4{border-radius:20px}
.flash-page .border{border:1px solid var(--fp-line)}
.flash-page .px-3{padding-left:1rem;padding-right:1rem}
.flash-page .py-2{padding-top:.5rem;padding-bottom:.5rem}
.flash-page .bg-light{background:#f8fafc}
.flash-page .display-5{font-size:2.8rem;line-height:1.1}
.flash-page .h2{font-size:1.8rem;line-height:1.25}
.flash-page .h3{font-size:1.5rem;line-height:1.3}
.flash-page .h4{font-size:1.25rem;line-height:1.35}
.flash-page .btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;cursor:pointer}
.flash-page .btn-dark{background:#171b2e;color:#fff}
.flash-page .w-100{width:100%}
.flash-page .fp-hero .d-flex{row-gap:.75rem}
.flash-page .fp-card .p-4{padding:1.5rem}
.flash-page .fp-time{min-width:58px}
.flash-page .fp-price-line{display:flex;align-items:baseline;flex-wrap:wrap;gap:.5rem}
@media(max-width:991px){.flash-page .row{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){
  .flash-page{width:min(calc(100% - 1.25rem),1200px);padding-top:1rem}
  .flash-page .row{grid-template-columns:1fr;gap:1rem}
  .flash-page .fp-hero{margin-bottom:2rem;padding:1.5rem}
  .flash-page .fp-title{font-size:clamp(2rem,10vw,3rem)}
  .flash-page .p-4{padding:1.1rem}
  .flash-page .fs-5{font-size:1rem}
}
@media(prefers-reduced-motion:reduce){.flash-page .fp-card{transition:none}.flash-page .fp-card:hover{transform:none}}
</style>
<div class="container py-4 py-lg-5 flash-page">
    <section class="fp-hero mb-5">
        <div class="fp-eyebrow mb-3">Alom's English Center • Limited-time learning offers</div>
        <h1 class="fp-title mb-3">{{ $settings->page_title }}</h1>
        <p class="fs-5 opacity-75 mb-4" style="max-width:650px">Invest in your next milestone. Explore selected courses with special campaign pricing while the offer lasts.</p>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <span class="fw-bold">Offers end soon</span>
            <div class="fp-timer" data-countdown-hero>
                <div class="fp-time"><strong data-unit="days">--</strong><span>Days</span></div>
                <div class="fp-time"><strong data-unit="hours">--</strong><span>Hours</span></div>
                <div class="fp-time"><strong data-unit="minutes">--</strong><span>Minutes</span></div>
                <div class="fp-time"><strong data-unit="seconds">--</strong><span>Seconds</span></div>
            </div>
        </div>
    </section>

    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-4">
        <div><div class="fp-eyebrow text-danger">Curated for your progress</div><h2 class="h2 fw-bold mb-1">Featured campaigns</h2><p class="text-secondary mb-0">Transparent prices. Secure checkout. Course access after verified payment.</p></div>
        <span class="badge rounded-pill text-bg-light border px-3 py-2">{{ $sales->count() }} active {{ \Illuminate\Support\Str::plural('offer', $sales->count()) }}</span>
    </div>

    @if($sales->isNotEmpty())
        <div class="row g-4">
        @foreach($sales as $offer)
            <div class="col-md-6 col-xl-4">
                <article class="fp-card">
                    @if($offer->banner_image_url)
                        <img class="fp-image" src="{{ $offer->banner_image_url }}" alt="{{ $offer->title }}" loading="lazy" referrerpolicy="no-referrer">
                    @else
                        <div class="fp-placeholder" aria-hidden="true">AEC</div>
                    @endif
                    <div class="p-4">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                            <span class="fp-badge">{{ $offer->badge_text ?: number_format((float)$offer->discount_percent, 0).'% OFF' }}</span>
                            <span class="small text-secondary">Limited time</span>
                        </div>
                        <h3 class="h4 fw-bold mb-1">{{ $offer->course_title }}</h3>
                        <div class="text-secondary fw-semibold mb-3">{{ $offer->title }}</div>
                        @if($offer->subtitle)<p class="text-secondary">{{ $offer->subtitle }}</p>@endif
                        @if($offer->description)<p class="small text-secondary">{{ $offer->description }}</p>@endif
                        <div class="d-flex align-items-baseline flex-wrap gap-2 mb-3">
                            <span class="fp-price">{{ $currency }} {{ number_format((float)$offer->sale_price,2) }}</span>
                            <span class="fp-old">{{ $currency }} {{ number_format((float)$offer->course_price,2) }}</span>
                            <span class="small fw-bold text-success">Save {{ $currency }} {{ number_format((float)$offer->discount_amount,2) }}</span>
                        </div>
                        <div class="rounded-3 bg-light p-3 mb-3">
                            <div class="small fw-bold mb-2">Offer ends in</div>
                            <div class="d-flex gap-2 flex-wrap" data-countdown="{{ \Illuminate\Support\Carbon::parse($offer->ends_at)->timestamp * 1000 }}">
                                <div><strong data-unit="days">--</strong><span class="small text-secondary">d</span></div>
                                <div><strong data-unit="hours">--</strong><span class="small text-secondary">h</span></div>
                                <div><strong data-unit="minutes">--</strong><span class="small text-secondary">m</span></div>
                                <div><strong data-unit="seconds">--</strong><span class="small text-secondary">s</span></div>
                            </div>
                        </div>
                        @if($offer->terms)<details class="small text-secondary mb-3"><summary class="fw-semibold">Offer terms</summary><p class="mt-2 mb-0">{{ $offer->terms }}</p></details>@endif
                        <a class="btn btn-dark fp-cta w-100" href="{{ url('/courses/'.$offer->course_slug) }}">{{ $offer->cta_label ?: 'Explore offer' }} <span aria-hidden="true">→</span></a>
                    </div>
                </article>
            </div>
        @endforeach
        </div>
    @else
        <div class="text-center rounded-4 border bg-light py-5 px-3"><div class="display-5 mb-3">✦</div><h3 class="h4 fw-bold">New opportunities are on the way</h3><p class="text-secondary mb-0">There are no active campaigns right now. Check back soon for upcoming learning offers.</p></div>
    @endif
</div>
<script>
(function(){
    const format = (value) => String(Math.max(0, value)).padStart(2, '0');
    function tick(){
        document.querySelectorAll('[data-countdown]').forEach((node)=>{
            const end = Number(node.dataset.countdown);
            const distance = Math.max(0, end - Date.now());
            const values = {days:Math.floor(distance/86400000),hours:Math.floor(distance/3600000)%24,minutes:Math.floor(distance/60000)%60,seconds:Math.floor(distance/1000)%60};
            Object.entries(values).forEach(([unit,value])=>{const target=node.querySelector('[data-unit="'+unit+'"]');if(target)target.textContent=format(value);});
            if(distance===0) node.setAttribute('aria-label','Offer ended');
        });
        const nearest = [...document.querySelectorAll('[data-countdown]')].map(n=>Number(n.dataset.countdown)).filter(n=>n>Date.now()).sort((a,b)=>a-b)[0];
        const hero = document.querySelector('[data-countdown-hero]');
        if(hero){const distance=nearest?Math.max(0,nearest-Date.now()):0;const values={days:Math.floor(distance/86400000),hours:Math.floor(distance/3600000)%24,minutes:Math.floor(distance/60000)%60,seconds:Math.floor(distance/1000)%60};Object.entries(values).forEach(([unit,value])=>{const target=hero.querySelector('[data-unit="'+unit+'"]');if(target)target.textContent=format(value);});}
    }
    tick();
    if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches) window.setInterval(tick,1000);
})();
</script>
@endsection
