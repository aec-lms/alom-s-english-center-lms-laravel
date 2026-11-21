<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }} — SCORM Player</title>
    <style>
        html, body { height: 100%; margin: 0; font-family: system-ui, sans-serif; background: #f4f6f8; }
        .player { height: 100%; display: flex; flex-direction: column; }
        .player__header { min-height: 3rem; display: flex; align-items: center; padding: .5rem 1rem; background: #fff; border-bottom: 1px solid #dce1e7; }
        .player__title { margin: 0; font-size: 1rem; font-weight: 600; }
        .player__status { margin-left: auto; font-size: .875rem; color: #475569; }
        .player__content { flex: 1; min-height: 0; width: 100%; border: 0; background: #fff; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
    </style>
</head>
<body>
<div class="player">
    <header class="player__header">
        <h1 class="player__title">{{ $title }}</h1>
        <span id="runtime-status" class="player__status" role="status" aria-live="polite">Ready</span>
    </header>
    <iframe
        id="scorm-sco"
        class="player__content"
        src="{{ $launchUrl }}"
        title="{{ $title }}"
        sandbox="allow-scripts allow-same-origin"
        referrerpolicy="no-referrer"
    ></iframe>
</div>
<script>
(() => {
    'use strict';

    const runtimeUrl = @json($runtimeUrl);
    const attemptId = @json($attemptId);
    const token = @json($token);
    let stateVersion = @json($stateVersion);
    let initialized = false;
    let finished = false;
    let lastError = '0';
    const readOnly = {
        'cmi._version': '3.4',
        'cmi.core.student_id': @json($studentId),
        'cmi.core.student_name': @json($studentName),
        'cmi.core.credit': 'credit',
        'cmi.core.entry': @json($entryMode),
        'cmi.core.lesson_mode': 'normal',
        'cmi.core.total_time': @json($totalTime),
        'cmi.core._children': 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time',
        'cmi.launch_data': '',
        'cmi.student_data._children': 'mastery_score,max_time_allowed,time_limit_action',
        'cmi.interactions._children': 'id,objectives,time,type,correct_responses,weighting,student_response,result,latency',
        'cmi.interactions._count': '0',
        'cmi.objectives._count': '0',
        'cmi.core.score._children': 'raw,max,min',
        'cmi.student_preference._children': 'audio,language,speed,text'
    };
    const data = Object.assign({
        'cmi.core.lesson_location': '',
        'cmi.core.lesson_status': 'not attempted',
        'cmi.core.score.raw': '',
        'cmi.core.score.max': '',
        'cmi.core.score.min': '',
        'cmi.core.exit': '',
        'cmi.core.session_time': '0000:00:00.00',
        'cmi.suspend_data': '',
        'cmi.comments': '',
        'cmi.student_preference.audio': '0',
        'cmi.student_preference.language': '',
        'cmi.student_preference.speed': '0',
        'cmi.student_preference.text': '0'
    }, @json($savedData));

    const errors = {
        '0': 'No error',
        '101': 'General exception',
        '201': 'Invalid argument error',
        '301': 'Not initialized',
        '401': 'Not implemented error',
        '402': 'Invalid set value',
        '403': 'Element is read only',
        '404': 'Element is write only',
        '405': 'Incorrect data type'
    };
    const writable = new Set([
        'cmi.core.lesson_location', 'cmi.core.lesson_status',
        'cmi.core.score.raw', 'cmi.core.score.max', 'cmi.core.score.min',
        'cmi.core.exit', 'cmi.core.session_time', 'cmi.suspend_data', 'cmi.comments',
        'cmi.student_preference.audio', 'cmi.student_preference.language',
        'cmi.student_preference.speed', 'cmi.student_preference.text'
    ]);
    const dynamicPattern = /^cmi\.interactions\.\d+\.(?:id|type|time|weighting|student_response|result|latency|objectives\.\d+\.id|correct_responses\.\d+\.pattern)$/;
    const objectivePattern = /^cmi\.objectives\.\d+\.(?:id|score\.(?:raw|min|max)|status)$/;
    const readOnlyDynamicPattern = /^cmi\.interactions\.\d+\.(?:objectives|correct_responses)\._count$/;
    const setError = code => { lastError = code; return 'false'; };
    const isSessionTime = value => /^\d{4}:[0-5]\d:[0-5]\d(?:\.\d{1,2})?$/.test(value);
    const isScore = value => value !== '' && Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 100;

    let pendingRequest = null;

    function newRequestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function sendPendingRequest() {
        const payload = pendingRequest;
        const dataSnapshot = JSON.stringify(payload.data);
        const wasFinish = payload.finish;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', runtimeUrl, false);
        xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
        try {
            xhr.send(JSON.stringify(payload));
            const response = JSON.parse(xhr.responseText || '{}');
            if (xhr.status < 200 || xhr.status >= 300 || response.ok !== true) {
                lastError = '101';
                document.getElementById('runtime-status').textContent = response.message || 'Save failed';
                return { ok: false };
            }
            stateVersion = response.version;
            pendingRequest = null;
            lastError = '0';
            document.getElementById('runtime-status').textContent = wasFinish ? 'Saved' : 'Progress saved';
            return { ok: true, dataSnapshot, wasFinish };
        } catch (error) {
            lastError = '101';
            document.getElementById('runtime-status').textContent = 'Connection error — progress may not be saved';
            return { ok: false };
        }
    }

    function persist(finish) {
        const requestedFinish = Boolean(finish);
        if (pendingRequest) {
            const pendingResult = sendPendingRequest();
            if (!pendingResult.ok) return false;
            if (requestedFinish && !pendingResult.wasFinish) return persist(true);
            if (JSON.stringify(data) !== pendingResult.dataSnapshot) return persist(requestedFinish);
            if (requestedFinish) finished = true;
            return true;
        }

        pendingRequest = {
            request_id: newRequestId(),
            attempt_id: attemptId,
            token,
            version: stateVersion,
            data: Object.assign({}, data),
            finish: requestedFinish
        };
        const result = sendPendingRequest();
        if (!result.ok) return false;
        if (requestedFinish) finished = true;
        if (JSON.stringify(data) !== result.dataSnapshot) return persist(requestedFinish);
        return true;
    }

    const api = {
        LMSInitialize(parameter) {
            if (parameter !== '' || initialized || finished) return setError('101');
            initialized = true;
            lastError = '0';
            return 'true';
        },
        LMSFinish(parameter) {
            if (parameter !== '' || !initialized || finished) return setError('301');
            if (!persist(true)) return 'false';
            lastError = '0';
            return 'true';
        },
        LMSGetValue(element) {
            if (!initialized || finished) { lastError = '301'; return ''; }
            if (element === 'cmi.core.exit' || element === 'cmi.core.session_time') { lastError = '404'; return ''; }
            if (element === 'cmi.interactions._count') { lastError = '0'; return String(new Set(Object.keys(data).map(key => key.match(/^cmi\.interactions\.(\d+)\./)?.[1]).filter(Boolean)).size); }
            if (element === 'cmi.objectives._count') { lastError = '0'; return String(new Set(Object.keys(data).map(key => key.match(/^cmi\.objectives\.(\d+)\./)?.[1]).filter(Boolean)).size); }
            if (readOnlyDynamicPattern.test(element)) {
                const match = element.match(/^cmi\.interactions\.(\d+)\.(objectives|correct_responses)\._count$/);
                const indexes = new Set(Object.keys(data).flatMap(key => {
                    const item = key.match(/^cmi\.interactions\.(\d+)\.(objectives|correct_responses)\.(\d+)\./);
                    return item && item[1] === match[1] && item[2] === match[2] ? [item[3]] : [];
                }));
                lastError = '0';
                return String(indexes.size);
            }
            if (Object.prototype.hasOwnProperty.call(readOnly, element)) { lastError = '0'; return String(readOnly[element]); }
            if (writable.has(element) || dynamicPattern.test(element) || objectivePattern.test(element)) {
                lastError = '0';
                return Object.prototype.hasOwnProperty.call(data, element) ? String(data[element]) : '';
            }
            lastError = '201';
            return '';
        },
        LMSSetValue(element, value) {
            if (!initialized || finished) return setError('301');
            if (Object.prototype.hasOwnProperty.call(readOnly, element) || readOnlyDynamicPattern.test(element)) return setError('403');
            if (typeof value !== 'string') return setError('405');
            let valid = false;
            switch (element) {
                case 'cmi.core.lesson_location': valid = value.length <= 255; break;
                case 'cmi.core.lesson_status': valid = ['passed','completed','failed','incomplete','browsed','not attempted'].includes(value); break;
                case 'cmi.core.score.raw':
                case 'cmi.core.score.max':
                case 'cmi.core.score.min': valid = isScore(value); break;
                case 'cmi.core.exit': valid = ['time-out','suspend','logout','normal',''].includes(value); break;
                case 'cmi.core.session_time': valid = isSessionTime(value); break;
                case 'cmi.suspend_data':
                case 'cmi.comments': valid = value.length <= 4096; break;
                case 'cmi.student_preference.audio':
                case 'cmi.student_preference.text': valid = ['-1','0','1'].includes(value); break;
                case 'cmi.student_preference.speed': valid = Number.isFinite(Number(value)) && Number(value) >= -100 && Number(value) <= 100; break;
                case 'cmi.student_preference.language': valid = value.length <= 255; break;
                default: valid = (dynamicPattern.test(element) || objectivePattern.test(element)) && element.length <= 255;
            }
            if (!valid) return setError(writable.has(element) || dynamicPattern.test(element) ? '405' : '401');
            data[element] = value;
            lastError = '0';
            return 'true';
        },
        LMSCommit(parameter) {
            if (parameter !== '' || !initialized || finished) return setError('301');
            return persist(false) ? 'true' : 'false';
        },
        LMSGetLastError() { return lastError; },
        LMSGetErrorString(code) { return errors[String(code)] || 'Unknown error'; },
        LMSGetDiagnostic(code) { return errors[String(code)] || 'Unknown error'; }
    };

    window.API = api;
    window.addEventListener('message', event => {
        if (event.source === document.getElementById('scorm-sco').contentWindow && event.data === 'scorm:ready') {
            document.getElementById('runtime-status').textContent = 'Course loaded';
        }
    });
})();
</script>
</body>
</html>
