@extends('layouts.admin')
@section('title', 'Offline Attendance — AEC LMS')
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<div class="mx-auto max-w-6xl space-y-5" id="offline-attendance">
    <div><h1 class="text-2xl font-extrabold">Offline Attendance</h1><p class="text-sm text-slate-500">Records are stored on this device first, then synced automatically when internet returns.</p></div>
    <div class="grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-3">
        <label class="text-sm">Batch<select id="batch" class="mt-1 w-full rounded-lg border-slate-300"><option value="">Select batch</option>@foreach($batches ?? [] as $batch)<option value="{{ $batch->id }}">{{ $batch->name }} — {{ $batch->batch_code }}</option>@endforeach</select></label>
        <label class="text-sm">Attendance date<input id="date" type="date" value="{{ now()->toDateString() }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
        <label class="flex items-center gap-2 pt-6 text-sm"><input id="consent" type="checkbox" class="rounded"> I consent to capture my current location for this attendance submission</label>
    </div>
    <div class="flex flex-wrap items-end gap-2 rounded-xl border bg-white p-4"><label class="text-sm">Assign student ID to selected batch<input id="assign-student-id" type="number" min="1" class="mt-1 block rounded-lg border-slate-300"></label><button id="assign-student" class="rounded-lg border px-4 py-2">Assign student</button><span class="text-xs text-slate-500">Staff can add a student to a batch before loading its roster.</span></div>
    <div class="flex flex-wrap gap-2"><button id="load-roster" class="rounded-lg bg-slate-900 px-4 py-2 text-white">Load batch roster</button><button id="save-pending" class="rounded-lg bg-indigo-600 px-4 py-2 text-white">Save attendance on device</button><button id="sync-now" class="rounded-lg border px-4 py-2">Sync now</button><span id="connection" class="self-center text-sm text-slate-500"></span></div>
    <div class="rounded-2xl border bg-white p-4"><div class="mb-3 flex flex-wrap gap-4 text-sm"><span>Pending: <b id="pending-count">0</b></span><span>Synced: <b id="synced-count">0</b></span><span>Failed: <b id="failed-count">0</b></span><span>Conflict: <b id="conflict-count">0</b></span></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-2">Student</th><th class="p-2">Code</th><th class="p-2">Attendance</th><th class="p-2">Remarks</th></tr></thead><tbody id="roster"><tr><td colspan="4" class="p-4 text-slate-400">Load a batch roster while online.</td></tr></tbody></table></div>
    </div>
    <p id="message" class="text-sm text-slate-600" role="status"></p><div id="sync-details" class="space-y-1 text-xs text-slate-600"></div>
    <p class="text-xs text-slate-500">Location is requested only when you save attendance. This page does not track location in the background. Keep this page available on the device; saved records remain in local IndexedDB until sync succeeds.</p>
</div>
<script>
(() => {
 const root=document.getElementById('offline-attendance'); if(!root)return;
 const $=id=>document.getElementById(id), dbName='aec-attendance-v1';
 let db, roster=[];
 const openDb=()=>new Promise((resolve,reject)=>{const req=indexedDB.open(dbName,1);req.onupgradeneeded=()=>req.result.createObjectStore('queue',{keyPath:'client_uuid'});req.onsuccess=()=>{db=req.result;resolve(db)};req.onerror=()=>reject(req.error)});
 const all=()=>new Promise((resolve,reject)=>{const q=db.transaction('queue').objectStore('queue').getAll();q.onsuccess=()=>resolve(q.result);q.onerror=()=>reject(q.error)});
 const put=row=>new Promise((resolve,reject)=>{const tx=db.transaction('queue','readwrite');tx.objectStore('queue').put(row);tx.oncomplete=resolve;tx.onerror=()=>reject(tx.error)});
 const updateCounts=async()=>{const rows=await all();for(const k of ['pending','synced','failed','conflict'])$(k+'-count').textContent=rows.filter(x=>x.local_status===k).length;const attention=rows.filter(x=>['failed','conflict'].includes(x.local_status));$('sync-details').innerHTML=attention.map(x=>'<div class="rounded border p-2">'+escapeHtml(x.attendance_date)+' · Student #'+x.student_id+' · '+escapeHtml(x.local_status)+' · '+escapeHtml(x.last_error||('Server status: '+(x.server_record?.status||'review required')) )+'</div>').join('')};
 const msg=t=>$('message').textContent=t;
 const statusOptions=['present','absent','late','excused'];
 const render=()=>{$('roster').innerHTML=roster.map(s=>'<tr class="border-b"><td class="p-2">'+escapeHtml(s.name)+'</td><td class="p-2">'+escapeHtml(s.student_code||'')+'</td><td class="p-2"><select data-status="'+s.id+'" class="rounded border-slate-300">'+statusOptions.map(v=>'<option value="'+v+'">'+v+'</option>').join('')+'</select></td><td class="p-2"><input data-remarks="'+s.id+'" class="w-full rounded border-slate-300" maxlength="255"></td></tr>').join('')};
 const escapeHtml=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const location=()=>new Promise((resolve,reject)=>{if(!navigator.geolocation)return reject(Error('This device does not support location.'));navigator.geolocation.getCurrentPosition(p=>resolve({latitude:p.coords.latitude,longitude:p.coords.longitude,location_accuracy:p.coords.accuracy}),e=>reject(Error(e.message||'Location permission is required.')),{enableHighAccuracy:true,timeout:15000,maximumAge:0})});
 const csrf=()=>document.querySelector('meta[name="csrf-token"]').content;
 async function sync(){if(!navigator.onLine){msg('Offline: attendance is safely queued on this device.');return}const rows=(await all()).filter(x=>['pending','failed'].includes(x.local_status));if(!rows.length){msg('No pending attendance to sync.');return}for(let i=0;i<rows.length;i+=50){const chunk=rows.slice(i,i+50);try{const res=await fetch('{{ url('/admin/attendance/sync') }}',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify({records:chunk.map(({local_status,...r})=>r)})});if(!res.ok)throw Error('Sync rejected ('+res.status+'). Sign in again if your session expired.');const payload=await res.json();for(const result of payload.results||[]){const row=chunk.find(x=>x.client_uuid===result.client_uuid);if(row){row.local_status=result.status==='synced'?'synced':result.status==='conflict'?'conflict':'failed';row.server_record=result.server_record||null;await put(row)}}}catch(e){for(const row of chunk){row.local_status='failed';row.last_error=e.message;await put(row)}}}await updateCounts();msg('Sync attempt finished. Review conflicts before taking further action.')}
 $('assign-student').addEventListener('click',async()=>{const batch=$('batch').value,student_id=Number($('assign-student-id').value);if(!batch||!student_id)return msg('Select a batch and enter a student ID.');try{const res=await fetch('{{ url('/admin/attendance/batches') }}/'+batch+'/students',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify({student_id})});if(!res.ok)throw Error('Student assignment failed ('+res.status+').');msg('Student assigned to batch. Load the roster again.')}catch(e){msg(e.message)}});
 const cacheRoster=id=>{try{localStorage.setItem('aec-attendance-roster-'+id,JSON.stringify(roster));localStorage.setItem('aec-attendance-last-batch',id)}catch(e){}};
 const loadCachedRoster=id=>{try{const cached=localStorage.getItem('aec-attendance-roster-'+id);if(cached){roster=JSON.parse(cached)||[];render();msg(roster.length+' cached students loaded for offline attendance.');return true}}catch(e){}return false};
 $('batch').addEventListener('change',()=>{if(!navigator.onLine)loadCachedRoster($('batch').value)});
 $('load-roster').addEventListener('click',async()=>{const id=$('batch').value;if(!id)return msg('Select a batch first.');try{const res=await fetch('{{ url('/admin/attendance/batches') }}/'+id+'/roster',{headers:{Accept:'application/json'}});if(!res.ok)throw Error('Could not load roster.');const data=await res.json();roster=data.students||[];render();cacheRoster(id);msg(roster.length+' students loaded and cached on this device.')}catch(e){if(!loadCachedRoster(id))msg(e.message+' Roster must be loaded before going offline.')}});
 $('save-pending').addEventListener('click',async()=>{if(!roster.length)return msg('Load the batch roster first.');if(!$('consent').checked)return msg('Please consent to one-time location capture.');if(!db)return msg('Local storage is not available.');try{const loc=await location();let count=0;for(const student of roster){const status=document.querySelector('[data-status="'+student.id+'"]').value;const remarks=document.querySelector('[data-remarks="'+student.id+'"]').value;const row={client_uuid:crypto.randomUUID(),batch_id:Number($('batch').value),student_id:student.id,attendance_date:$('date').value,status,remarks,location_consent:true,...loc,local_status:'pending',saved_at:new Date().toISOString()};await put(row);count++}await updateCounts();msg(count+' attendance entries saved locally. They will sync when online.');if(navigator.onLine)await sync()}catch(e){msg(e.message)}});
 $('sync-now').addEventListener('click',sync);
 window.addEventListener('online',sync);
 window.addEventListener('offline',()=>msg('Offline mode: new entries will remain pending on this device.'));
 $('connection').textContent=navigator.onLine?'Online':'Offline';
 window.addEventListener('online',()=>$('connection').textContent='Online');
 window.addEventListener('offline',()=>$('connection').textContent='Offline');
 if('serviceWorker' in navigator){navigator.serviceWorker.register('/attendance-offline-sw.js',{scope:'/admin/attendance/'}).catch(()=>{});}
 const lastBatch=localStorage.getItem('aec-attendance-last-batch');if(lastBatch&&$('batch').querySelector('option[value="'+lastBatch+'"]')){$('batch').value=lastBatch;if(!navigator.onLine)loadCachedRoster(lastBatch)}
 openDb().then(updateCounts).catch(()=>msg('IndexedDB is unavailable in this browser.'));
})();
</script>
@endsection
