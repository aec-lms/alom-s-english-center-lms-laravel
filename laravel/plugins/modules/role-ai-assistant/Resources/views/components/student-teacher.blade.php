@php
    $studentTeacherEnabled = auth()->check()
        && auth()->user()->hasRole('student')
        && app(\App\Core\Plugins\PluginManager::class)->isEnabled('role-ai-assistant')
        && auth()->user()->hasPermission('ai.assistant.use');
@endphp
@if ($studentTeacherEnabled)
<div id="aec-ai-teacher" class="aec-ai-teacher" data-endpoint="{{ route('plugins.ai.ask') }}">
    <button type="button" class="aec-ai-launcher" id="aec-ai-launcher" aria-label="Open AI Teacher" aria-expanded="false" aria-controls="aec-ai-panel">
        <span class="aec-ai-launcher-avatar" aria-hidden="true">
            <svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="48" fill="#dbeafe"/><path d="M20 91c2-19 14-29 30-29s28 10 30 29" fill="#2563eb"/><path d="M31 42c0-20 8-29 20-29 15 0 22 12 20 31l-5 15H37z" fill="#243b53"/><ellipse cx="50" cy="45" rx="19" ry="22" fill="#f2c6a0"/><path d="M31 38c1-17 11-26 23-24 11 1 17 10 17 22-8-2-15-8-19-14-5 9-12 14-21 16" fill="#243b53"/><circle cx="43" cy="45" r="2" fill="#243b53"/><circle cx="57" cy="45" r="2" fill="#243b53"/><path d="M44 55q6 5 12 0" fill="none" stroke="#a34f45" stroke-width="2" stroke-linecap="round"/><path d="M36 39h14m0 0h14" stroke="#334155" stroke-width="2" fill="none"/><rect x="35" y="38" width="14" height="12" rx="5" fill="none" stroke="#334155" stroke-width="2"/><rect x="51" y="38" width="14" height="12" rx="5" fill="none" stroke="#334155" stroke-width="2"/></svg>
        </span>
        <span class="aec-ai-launcher-label">AI Teacher</span>
        <span class="aec-ai-online-dot"></span>
    </button>

    <section class="aec-ai-panel" id="aec-ai-panel" aria-label="AI Teacher chat" hidden>
        <header class="aec-ai-header">
            <div class="aec-ai-header-avatar" aria-hidden="true">
                <svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="48" fill="#dbeafe"/><path d="M17 96c3-22 15-32 33-32s30 10 33 32" fill="#2563eb"/><path d="M29 44c0-22 9-31 22-31 15 0 22 12 20 32l-5 15H34z" fill="#243b53"/><ellipse cx="50" cy="47" rx="19" ry="22" fill="#f2c6a0"/><path d="M31 39c1-17 11-26 23-24 11 1 17 10 17 22-8-2-15-8-19-14-5 9-12 14-21 16" fill="#243b53"/><circle cx="43" cy="47" r="2" fill="#243b53"/><circle cx="57" cy="47" r="2" fill="#243b53"/><path class="aec-ai-mouth" d="M44 57q6 5 12 0" fill="none" stroke="#a34f45" stroke-width="2.5" stroke-linecap="round"/><rect x="35" y="40" width="14" height="12" rx="5" fill="none" stroke="#334155" stroke-width="2"/><rect x="51" y="40" width="14" height="12" rx="5" fill="none" stroke="#334155" stroke-width="2"/></svg>
            </div>
            <div><strong>আপনার AI Teacher</strong><small>পড়াশোনায় সাহায্য করতে প্রস্তুত</small></div>
            <button type="button" id="aec-ai-close" aria-label="Close AI Teacher">×</button>
        </header>
        <div class="aec-ai-greeting"><span>আসসালামু আলাইকুম! 👋</span><p>আজ কী শিখতে চাও? প্রশ্ন করো, আমি সহজভাবে বুঝিয়ে দেব।</p></div>
        <div class="aec-ai-messages" id="aec-ai-messages" role="log" aria-live="polite" aria-relevant="additions">
            <div class="aec-ai-message aec-ai-message-teacher">আমি তোমার AI Teacher। English, Grammar, Vocabulary, IELTS কিংবা তোমার Course-এর যেকোনো বিষয় নিয়ে প্রশ্ন করতে পারো।</div>
        </div>
        <div class="aec-ai-quick-prompts">
            <button type="button" data-prompt="Present Perfect Tense সহজভাবে উদাহরণসহ বুঝিয়ে দিন।">Grammar শেখাও</button>
            <button type="button" data-prompt="আজকের জন্য ৫টি English vocabulary practice দিন।">Vocabulary practice</button>
            <button type="button" data-prompt="আমার জন্য ৫টি English MCQ quiz তৈরি করুন।">একটি Quiz দাও</button>
        </div>
        <form class="aec-ai-compose" id="aec-ai-form">
            <label class="aec-ai-sr-only" for="aec-ai-prompt">আপনার প্রশ্ন</label>
            <textarea id="aec-ai-prompt" rows="1" maxlength="2000" minlength="3" placeholder="আপনার প্রশ্ন লিখুন..." required></textarea>
            <button type="submit" id="aec-ai-send" aria-label="Send question">➤</button>
        </form>
        <footer class="aec-ai-footer">
            <span id="aec-ai-status">AI উত্তর যাচাই করে পড়ুন।</span>
            <button type="button" id="aec-ai-voice" aria-pressed="false">🔊 উত্তর শুনুন</button>
        </footer>
    </section>
</div>
<style>
#aec-ai-teacher{--ai-blue:#2563eb;--ai-ink:#172554;position:fixed;right:22px;bottom:22px;z-index:60;font-family:inherit}
.aec-ai-launcher{display:flex;align-items:center;gap:9px;padding:7px 15px 7px 7px;border:1px solid #bfdbfe;border-radius:999px;background:#fff;color:#172554;box-shadow:0 10px 35px #1e3a8a2b;font-weight:800;cursor:pointer;transition:transform .2s,box-shadow .2s}
.aec-ai-launcher:hover{transform:translateY(-3px);box-shadow:0 15px 38px #1e3a8a3d}
.aec-ai-launcher-avatar,.aec-ai-header-avatar{display:grid;place-items:center;border-radius:50%;overflow:hidden;background:#dbeafe}
.aec-ai-launcher-avatar{width:43px;height:43px}.aec-ai-launcher-avatar svg{width:100%;height:100%}
.aec-ai-online-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px #dcfce7}
.aec-ai-panel{position:absolute;right:0;bottom:65px;width:min(390px,calc(100vw - 28px));height:min(590px,calc(100dvh - 110px));background:#fff;border:1px solid #dbeafe;border-radius:23px;box-shadow:0 24px 70px #0f172a35;overflow:hidden;display:flex;flex-direction:column}
.aec-ai-panel[hidden]{display:none}
.aec-ai-header{display:flex;align-items:center;gap:11px;padding:13px 15px;background:linear-gradient(120deg,#eff6ff,#dbeafe);color:#172554}
.aec-ai-header-avatar{width:49px;height:49px;flex:none}.aec-ai-header-avatar svg{width:100%;height:100%}
.aec-ai-header strong,.aec-ai-header small{display:block}.aec-ai-header strong{font-size:14px}.aec-ai-header small{font-size:11px;color:#475569;margin-top:3px}
.aec-ai-header>button{margin-left:auto;border:0;background:#fff9;border-radius:50%;width:31px;height:31px;font-size:22px;cursor:pointer;color:#334155}
.aec-ai-greeting{padding:12px 15px 8px;background:#f8fafc;font-size:12px;color:#334155}.aec-ai-greeting span{font-weight:800;color:#1d4ed8}.aec-ai-greeting p{margin:3px 0 0}
.aec-ai-messages{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:linear-gradient(#fff,#f8fbff)}
.aec-ai-message{max-width:90%;padding:10px 12px;border-radius:14px;font-size:13px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}
.aec-ai-message-teacher{align-self:flex-start;background:#eff6ff;color:#1e3a8a;border-bottom-left-radius:4px}
.aec-ai-message-student{align-self:flex-end;background:#2563eb;color:white;border-bottom-right-radius:4px}
.aec-ai-quick-prompts{display:flex;gap:6px;padding:8px 12px;overflow-x:auto;border-top:1px solid #eef2ff}
.aec-ai-quick-prompts button{white-space:nowrap;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:6px 9px;font-size:10px;font-weight:700;cursor:pointer}
.aec-ai-compose{display:flex;align-items:end;gap:8px;padding:10px 12px;border-top:1px solid #e2e8f0}
.aec-ai-compose textarea{flex:1;resize:none;max-height:90px;border:1px solid #cbd5e1;border-radius:13px;padding:10px;font:inherit;font-size:13px;outline:none}
.aec-ai-compose textarea:focus{border-color:#3b82f6;box-shadow:0 0 0 3px #dbeafe}
.aec-ai-compose button{width:39px;height:39px;flex:none;border:0;border-radius:12px;background:#2563eb;color:#fff;font-size:19px;cursor:pointer}
.aec-ai-compose button:disabled{opacity:.55;cursor:wait}
.aec-ai-footer{display:flex;justify-content:space-between;gap:8px;padding:7px 12px 10px;color:#64748b;font-size:10px}
.aec-ai-footer button{border:0;background:transparent;color:#1d4ed8;font-weight:800;cursor:pointer}
.aec-ai-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
#aec-ai-teacher.aec-ai-speaking .aec-ai-mouth{animation:aec-ai-talk .18s ease-in-out infinite alternate;transform-origin:center}
@keyframes aec-ai-talk{from{transform:scaleY(.5)}to{transform:scaleY(2.4)}}
@keyframes aec-ai-blink{0%,45%,49%,100%{transform:scaleY(1)}47%{transform:scaleY(.08)}}
#aec-ai-teacher .aec-ai-header-avatar svg{animation:aec-ai-blink 5s infinite;transform-origin:50% 47%}
@media(max-width:480px){#aec-ai-teacher{right:12px;bottom:12px}.aec-ai-panel{right:-2px;bottom:62px;height:min(590px,calc(100dvh - 90px))}}
@media(prefers-reduced-motion:reduce){#aec-ai-teacher *,#aec-ai-teacher *:before,#aec-ai-teacher *:after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
</style>
<script>
(function(){
    const root=document.getElementById('aec-ai-teacher');
    if(!root||root.dataset.ready==='1')return;
    root.dataset.ready='1';
    const panel=document.getElementById('aec-ai-panel'),launcher=document.getElementById('aec-ai-launcher');
    const close=document.getElementById('aec-ai-close'),form=document.getElementById('aec-ai-form');
    const input=document.getElementById('aec-ai-prompt'),send=document.getElementById('aec-ai-send');
    const messages=document.getElementById('aec-ai-messages'),status=document.getElementById('aec-ai-status');
    const voiceButton=document.getElementById('aec-ai-voice');
    let lastAnswer='',voiceEnabled=false;
    const setOpen=(open)=>{panel.hidden=!open;launcher.setAttribute('aria-expanded',String(open));if(open)input.focus();};
    launcher.addEventListener('click',()=>setOpen(panel.hidden));
    close.addEventListener('click',()=>setOpen(false));
    function addMessage(text,kind){const node=document.createElement('div');node.className='aec-ai-message '+(kind==='student'?'aec-ai-message-student':'aec-ai-message-teacher');node.textContent=text;messages.appendChild(node);messages.scrollTop=messages.scrollHeight;return node;}
    function speak(text){
        if(!('speechSynthesis' in window)){status.textContent='এই Browser-এ Voice সুবিধা নেই।';return;}
        window.speechSynthesis.cancel();
        const utterance=new SpeechSynthesisUtterance(text);
        const voices=window.speechSynthesis.getVoices();
        utterance.voice=voices.find(v=>/^bn(-|_)(BD|IN)/i.test(v.lang))||voices.find(v=>/^bn/i.test(v.lang))||null;
        utterance.lang=utterance.voice?.lang||'bn-BD';utterance.rate=.94;utterance.pitch=1.03;
        utterance.onstart=()=>root.classList.add('aec-ai-speaking');
        utterance.onend=utterance.onerror=()=>root.classList.remove('aec-ai-speaking');
        window.speechSynthesis.speak(utterance);
    }
    voiceButton.addEventListener('click',()=>{voiceEnabled=!voiceEnabled;voiceButton.setAttribute('aria-pressed',String(voiceEnabled));voiceButton.textContent=voiceEnabled?'🔊 Voice চালু':'🔇 Voice বন্ধ';if(voiceEnabled&&lastAnswer)speak(lastAnswer);else if('speechSynthesis'in window)window.speechSynthesis.cancel();});
    async function ask(prompt){
        addMessage(prompt,'student');send.disabled=true;status.textContent='শিক্ষক উত্তর তৈরি করছেন...';
        const waiting=addMessage('একটু অপেক্ষা করো, বুঝিয়ে বলছি...','teacher');
        try{
            const response=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({prompt})});
            const data=await response.json();
            waiting.remove();
            if(!response.ok)throw new Error(data.error||'এই মুহূর্তে উত্তর পাওয়া যাচ্ছে না।');
            lastAnswer=String(data.reply||'উত্তর পাওয়া যায়নি।');addMessage(lastAnswer,'teacher');status.textContent='উত্তর প্রস্তুত।';if(voiceEnabled)speak(lastAnswer);
        }catch(error){waiting.remove();addMessage(error.message||'সংযোগে সমস্যা হয়েছে। আবার চেষ্টা করো।','teacher');status.textContent='আবার চেষ্টা করুন।';}
        finally{send.disabled=false;input.focus();}
    }
    form.addEventListener('submit',event=>{event.preventDefault();const prompt=input.value.trim();if(prompt.length<3)return;input.value='';ask(prompt);});
    root.querySelectorAll('[data-prompt]').forEach(button=>button.addEventListener('click',()=>{setOpen(true);ask(button.dataset.prompt);}));
    input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();form.requestSubmit();}});
})();
</script>
@endif
