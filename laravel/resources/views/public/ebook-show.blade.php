@extends('layouts.app')
@section('title', $ebook['title'] . " — Alom's English Center")
@section('content')
@php
    $discount = (!$ebook['is_free'] && $ebook['original_price'] > $ebook['price'])
        ? (int) round((($ebook['original_price'] - $ebook['price']) / $ebook['original_price']) * 100)
        : 0;
@endphp
<div class="min-h-screen flex flex-col bg-[#F8FAFC]">
    @include('public.partials.navbar')

    <!-- Breadcrumb Strip -->
    <div class="w-full bg-white border-b border-slate-100">
        <div class="max-w-[1160px] mx-auto px-4 sm:px-6 py-3 flex items-center gap-2 text-xs text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-[#0284C7] transition-colors">Home</a>
            <span class="text-slate-400">&rsaquo;</span>
            <a href="{{ route('public.ebooks') }}" class="hover:text-[#0284C7] transition-colors">E-books</a>
            <span class="text-slate-400">&rsaquo;</span>
            <span class="font-semibold text-slate-800 truncate">{{ $ebook['title'] }}</span>
        </div>
    </div>

    <!-- Hero Split Showcase Banner -->
    <section class="w-full py-10 sm:py-14 px-4 sm:px-6 border-b border-slate-200/70" style="background: linear-gradient(135deg, #EEF6FF 0%, #FFFFFF 52%, #E5F4FE 100%)">
        <div class="max-w-[980px] mx-auto grid grid-cols-1 md:grid-cols-12 gap-8 sm:gap-12 items-center">
            @php $coverImg = $ebook['pdf_thumbnail_path'] ?? ($ebook['thumbnail_path'] ?? null); @endphp
            <!-- Left Tilted Book Cover -->
            <div class="md:col-span-5 flex justify-center">
                <div class="relative w-[260px] sm:w-[295px] aspect-[3/4] rounded-2xl overflow-hidden shadow-[0_28px_60px_-15px_rgba(15,23,42,0.32)] border border-white/80 transform -rotate-1 hover:rotate-0 transition-transform duration-300 bg-gradient-to-br {{ $ebook['gradient'] }} p-6 flex flex-col justify-between text-white">
                    @if ($coverImg)
                        <img src="{{ $coverImg }}" alt="{{ $ebook['title'] }}" class="absolute inset-0 w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/35 to-slate-950/45"></div>
                    @endif
                    <span class="relative z-10 text-[10px] font-bold tracking-widest uppercase text-white/90">ALOM'S ENGLISH CENTER</span>

                    <div class="relative z-10 my-auto space-y-3 text-center">
                        <div class="inline-block px-3 py-1 rounded-full bg-white/15 border border-white/20 text-[10px] font-extrabold uppercase tracking-widest">
                            {{ $ebook['category'] }}
                        </div>
                        <h2 class="text-xl sm:text-2xl font-black leading-tight text-white drop-shadow">{{ $ebook['title'] }}</h2>
                        <div class="inline-block px-3 py-0.5 rounded-md bg-white text-slate-900 font-extrabold text-[10px] tracking-wider uppercase shadow-sm">
                            {{ $ebook['is_free'] ? '100% Free Edition' : 'ShortNote 2.0 E-Book' }}
                        </div>
                    </div>

                    <div class="relative z-10 pt-3 border-t border-white/15 flex items-center justify-between text-[10px] text-white/90 font-semibold">
                        <span>Verified PDF</span>
                        <span class="font-mono">{{ $ebook['pages'] }} Pages</span>
                    </div>
                </div>
            </div>

            <!-- Right Details & CTA -->
            <div class="md:col-span-7 space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2.5 py-1 rounded-md bg-[#E0F2FE] text-[#0284C7] text-[10px] font-extrabold uppercase tracking-wider">
                        {{ $ebook['is_free'] ? 'FREE E-BOOK' : 'PREMIUM E-BOOK' }}
                    </span>
                    @if ($ebook['is_free'])
                        <span class="px-2.5 py-1 rounded-full bg-[#DCFCE7] text-[#15803D] text-[10px] font-extrabold uppercase tracking-wider">FREE</span>
                    @elseif ($discount > 0)
                        <span class="px-2.5 py-1 rounded-full bg-[#FEE2E2] text-[#B91C1C] text-[10px] font-extrabold uppercase tracking-wider">-{{ $discount }}% OFF</span>
                    @endif
                </div>

                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight leading-tight">
                    {{ $ebook['title'] }}
                </h1>

                <!-- 4 Mini Spec Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                    @foreach ([
                        ['FORMAT', 'PDF · Download'],
                        ['ACCESS', 'Lifetime'],
                        ['READ', 'Any device'],
                        ['SECURE', 'Protected PDF'],
                    ] as [$k, $v])
                        <div class="bg-white/90 border border-slate-200/80 rounded-xl p-3 space-y-1 shadow-2xs">
                            <div class="text-[9px] font-bold uppercase tracking-wider text-slate-400">{{ $k }}</div>
                            <div class="text-[11px] font-bold text-slate-800">{{ $v }}</div>
                        </div>
                    @endforeach
                </div>

                <!-- Elevated White Price & Action Card -->
                <div class="rounded-2xl bg-white border border-slate-200/90 shadow-lg p-5 space-y-4">
                    <div class="space-y-0.5">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">PRICE</div>
                        @if ($ebook['is_free'])
                            <div class="text-3xl font-extrabold text-[#0077CC] tracking-tight">Free</div>
                        @else
                            <div class="flex items-baseline gap-2.5">
                                <span class="text-3xl font-extrabold font-mono text-[#0077CC] tracking-tight">৳{{ $ebook['price'] }}</span>
                                @if ($ebook['original_price'] > $ebook['price'])
                                    <span class="text-sm font-mono text-slate-400 line-through">৳{{ $ebook['original_price'] }}</span>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if (($hasAccess ?? false) && !empty($ebook['download_url']))
                        <a href="{{ route('public.ebooks.download', $ebook['slug']) }}" class="w-full py-3.5 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold flex items-center justify-center gap-2 shadow-sm transition-colors">
                            <span>{{ $ebook['is_free'] ? 'Download Free PDF Now' : 'Download Purchased PDF' }}</span>
                        </a>
                    @elseif ($ebook['is_free'])
                        <p class="text-sm text-slate-500">The free PDF has not been uploaded yet.</p>
                    @elseif (Auth::check() && !($canPurchase ?? false))
                        <p class="text-sm text-amber-700">A protected PDF must be uploaded before this e-book can be sold.</p>
                    @elseif (Auth::check() && Route::has('plugins.universal_payment.checkout'))
                        <form id="aec-premium-checkout" method="POST" action="{{ route('plugins.universal_payment.checkout') }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="storefront_item_id" value="{{ $ebook['id'] }}">
                            <input type="hidden" name="item_title" value="{{ $ebook['title'] }}">
                            <input type="hidden" name="amount" value="{{ $ebook['price'] }}">
                            <input type="hidden" name="payer_name" value="{{ Auth::user()->name }}">
                            <input type="hidden" name="gateway" value="sslcommerz">
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <div class="flex items-center justify-between gap-2 mb-4"><div class="text-xs font-extrabold tracking-wide text-slate-900">PREMIUM CHECKOUT</div><div class="inline-flex items-center gap-1.5 text-[10px] font-bold text-emerald-700"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Secure session</div></div>
                                <ol id="aec-checkout-progress" class="grid grid-cols-4 gap-1.5" aria-label="Checkout progress">
                                    @foreach ([['01','Order'],['02','Details'],['03','Method'],['04','Pay']] as $index => [$number, $label])
                                        <li class="min-w-0"><div class="aec-step-track h-1.5 rounded-full bg-slate-200 mb-2" data-track="{{ $index + 1 }}"></div><div class="flex items-center gap-1.5"><span class="aec-step-number w-6 h-6 shrink-0 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-[10px] font-black" data-number="{{ $index + 1 }}">{{ $number }}</span><span class="aec-step-label text-[10px] font-bold text-slate-400 truncate" data-label="{{ $index + 1 }}">{{ $label }}</span></div></li>
                                    @endforeach
                                </ol>
                            </div>
                            <section class="aec-checkout-panel space-y-3" data-panel="1">
                                <div class="flex items-center gap-3 rounded-xl border border-sky-100 bg-gradient-to-r from-sky-50 to-white p-3">
                                    @if (!empty($coverImg))<img src="{{ $coverImg }}" alt="" class="w-14 aspect-[3/4] rounded-lg object-cover shadow-sm">@else<div class="w-14 aspect-[3/4] rounded-lg bg-sky-100 flex items-center justify-center text-2xl">📘</div>@endif
                                    <div class="min-w-0 flex-1"><div class="text-[10px] font-bold uppercase tracking-wider text-sky-700">Your selected product</div><div class="font-extrabold text-sm text-slate-900 leading-snug">{{ $ebook['title'] }}</div><div class="text-[11px] text-slate-500">{{ $ebook['pages'] }} pages · Protected PDF · Lifetime access</div></div>
                                </div>
                                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 space-y-2 text-xs"><div class="flex justify-between gap-3"><span class="text-slate-500">Item price</span><strong>৳{{ number_format($ebook['price'], 2) }}</strong></div><div class="flex justify-between gap-3"><span class="text-slate-500">Discount</span><strong class="text-emerald-700">No additional discount</strong></div><div class="border-t border-dashed border-slate-300 pt-2 flex justify-between gap-3"><span class="font-bold text-slate-800">Total payable</span><strong class="text-lg text-sky-700">৳{{ number_format($ebook['price'], 2) }}</strong></div></div>
                                <button type="button" data-next="2" class="w-full rounded-xl bg-slate-900 hover:bg-slate-800 text-white py-3 font-bold text-sm">Continue to your details →</button>
                            </section>
                            <section class="aec-checkout-panel hidden space-y-3" data-panel="2" hidden>
                                <div class="rounded-xl bg-sky-50 border border-sky-100 p-3 text-xs text-sky-900"><strong>👤 Payer information</strong><p class="mt-1">Your account details help us match your payment and unlock your PDF.</p></div>
                                <div><label for="aec-payer-name" class="block text-xs font-bold text-slate-600 mb-1">Account name</label><input id="aec-payer-name" value="{{ Auth::user()->name }}" readonly class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600"></div>
                                <div><label for="aec-payer-contact" class="block text-xs font-bold text-slate-600 mb-1">Mobile number <span class="text-rose-600">*</span></label><input id="aec-payer-contact" name="payer_contact" required value="{{ Auth::user()->phone ?? '' }}" autocomplete="tel" inputmode="tel" placeholder="01XXXXXXXXX" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-100"><p class="mt-1 text-[10px] text-slate-500">Use a reachable number for your payment provider.</p></div>
                                <div class="flex gap-2"><button type="button" data-prev="1" class="w-1/3 rounded-xl border border-slate-300 py-3 text-xs font-bold text-slate-700">Back</button><button type="button" data-next="3" class="flex-1 rounded-xl bg-slate-900 py-3 text-sm font-bold text-white">Continue to payment</button></div>
                            </section>
                            <section class="aec-checkout-panel hidden space-y-3" data-panel="3" hidden>
                                <div class="text-xs font-extrabold text-slate-900">Choose your payment experience</div>
                                <label class="block rounded-2xl border-2 border-sky-500 bg-sky-50/70 p-4 cursor-pointer"><span class="flex items-start gap-3"><span class="w-10 h-10 rounded-xl bg-white border border-sky-100 flex items-center justify-center text-xl">💳</span><span class="flex-1"><strong class="block text-sm text-slate-900">SSLCOMMERZ Secure Checkout</strong><span class="block text-[11px] text-slate-600 mt-1">Continue to the provider's hosted payment page. Available channels depend on your merchant account.</span></span><span class="text-sky-600 font-black">✓</span></span><span class="flex flex-wrap gap-1.5 mt-3 pl-[52px]"><span class="rounded-md bg-white border border-slate-200 px-2 py-1 text-[9px] font-black text-slate-700">VISA</span><span class="rounded-md bg-white border border-slate-200 px-2 py-1 text-[9px] font-black text-slate-700">Mastercard</span><span class="rounded-md bg-white border border-slate-200 px-2 py-1 text-[9px] font-black text-slate-700">Mobile Banking*</span></span></label>
                                <p class="text-[10px] text-slate-500">* bKash, Nagad and other mobile banking options appear only when enabled for your SSLCOMMERZ merchant account. AEC never collects your card PIN or OTP.</p>
                                <div class="flex gap-2"><button type="button" data-prev="2" class="w-1/3 rounded-xl border border-slate-300 py-3 text-xs font-bold text-slate-700">Back</button><button type="button" data-next="4" class="flex-1 rounded-xl bg-slate-900 py-3 text-sm font-bold text-white">Review &amp; continue</button></div>
                            </section>
                            <section class="aec-checkout-panel hidden space-y-3" data-panel="4" hidden>
                                <div class="rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-4 text-center"><div class="mx-auto mb-2 w-12 h-12 rounded-2xl bg-emerald-100 flex items-center justify-center text-2xl">🔒</div><div class="font-extrabold text-slate-900">Ready for secure payment</div><p class="text-[11px] text-slate-600 mt-1">You will be redirected to SSLCOMMERZ. Payment confirmation is verified directly with the provider before access is granted.</p></div>
                                <div class="rounded-xl bg-slate-50 p-3 flex justify-between items-center"><span class="text-xs font-bold text-slate-600">Total to pay</span><strong class="text-xl font-black text-sky-700">৳{{ number_format($ebook['price'], 2) }}</strong></div>
                                <div class="flex gap-2"><button type="button" data-prev="3" class="w-1/3 rounded-xl border border-slate-300 py-3 text-xs font-bold text-slate-700">Back</button><button type="submit" class="flex-1 rounded-xl bg-gradient-to-r from-sky-600 to-blue-700 hover:from-sky-700 hover:to-blue-800 text-white py-3.5 text-sm font-extrabold shadow-lg shadow-sky-200">🔐 Pay ৳{{ number_format($ebook['price'], 2) }} securely</button></div>
                            </section>
                            <p class="text-center text-[10px] text-slate-400">Encrypted provider checkout · No card details stored on AEC LMS</p>
                        </form>
                        <style>
                            #aec-premium-checkout .aec-step-track { transition: background-color .25s ease; }
                            #aec-premium-checkout [data-track].is-active { background: linear-gradient(90deg,#0284c7,#2563eb); }
                            #aec-premium-checkout .aec-step-number.is-active { background:#0c4a6e;color:white; }
                            #aec-premium-checkout .aec-step-label.is-active { color:#0c4a6e; }
                            @media (prefers-reduced-motion: reduce) { #aec-premium-checkout * { transition:none !important; } }
                        </style>
                        <script>
                        (() => {
                            const form = document.getElementById('aec-premium-checkout');
                            if (!form || form.dataset.ready === '1') return;
                            form.dataset.ready = '1';
                            let current = 1;
                            const showStep = (step) => {
                                current = Math.max(1, Math.min(4, step));
                                form.querySelectorAll('[data-panel]').forEach(panel => {
                                    const active = Number(panel.dataset.panel) === current;
                                    panel.hidden = !active;
                                    panel.classList.toggle('hidden', !active);
                                });
                                form.querySelectorAll('[data-track]').forEach(el => el.classList.toggle('is-active', Number(el.dataset.track) <= current));
                                form.querySelectorAll('[data-number]').forEach(el => el.classList.toggle('is-active', Number(el.dataset.number) <= current));
                                form.querySelectorAll('[data-label]').forEach(el => el.classList.toggle('is-active', Number(el.dataset.label) <= current));
                                form.querySelector('#aec-checkout-progress')?.setAttribute('aria-label', 'Checkout step ' + current + ' of 4');
                            };
                            form.querySelectorAll('[data-next]').forEach(button => button.addEventListener('click', () => {
                                if (current === 2) {
                                    const phone = form.querySelector('#aec-payer-contact');
                                    if (!phone.checkValidity()) { phone.reportValidity(); return; }
                                }
                                showStep(Number(button.dataset.next));
                            }));
                            form.querySelectorAll('[data-prev]').forEach(button => button.addEventListener('click', () => showStep(Number(button.dataset.prev))));
                            showStep(1);
                        })();
                        </script>
                    @elseif (Auth::check())
                        <p class="text-sm text-amber-700">Universal Payment plugin must be enabled to purchase this e-book.</p>
                    @else
                        <a href="{{ route('login') }}" class="w-full py-3.5 px-5 rounded-xl bg-[#0077CC] hover:bg-[#0060A8] text-white text-sm font-bold flex items-center justify-center gap-2 shadow-sm transition-colors">
                            <span>Sign in to purchase</span>
                        </a>
                    @endif

                    <p class="text-center text-[11px] text-slate-400">
                        {{ $ebook['is_free'] ? 'Free — one-time verification, then instant download' : $ebook['pages'] . ' Pages · Instant protected PDF access after verification' }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Bottom About Card -->
    <section class="flex-1 py-10 px-4 sm:px-6">
        <div class="max-w-[980px] mx-auto">
            <div class="rounded-2xl bg-white border border-slate-200/80 shadow-xs p-6 sm:p-8 space-y-5">
                <h2 class="text-base sm:text-lg font-extrabold text-slate-900 border-l-4 border-[#0077CC] pl-3">
                    About this ebook
                </h2>
                <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">{{ $ebook['summary'] }}</p>
                @if (!empty($ebook['topics']))
                    <ul class="space-y-1.5 text-xs sm:text-sm text-slate-600 pt-1">
                        @foreach ($ebook['topics'] as $topic)
                            <li class="flex items-start gap-2">
                                <span class="text-slate-400 font-bold">-</span>
                                <span>{{ $topic }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </section>

    @include('public.partials.footer')
</div>
@endsection
