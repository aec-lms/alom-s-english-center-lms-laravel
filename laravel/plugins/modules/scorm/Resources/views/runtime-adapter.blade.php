<script>
(() => {
    'use strict';

    const playerOrigin = @json($playerOrigin);
    const attemptId = @json($attemptId);
    const bridgeNonce = @json($bridgeNonce);
    let initialized = false;
    let finished = false;
    let lastError = '0';
    function notifyStatus(message) {
        if (window.parent !== window) {
            window.parent.postMessage({
                type: 'scorm:status',
                bridge_nonce: bridgeNonce,
                message: String(message || '').slice(0, 160)
            }, playerOrigin);
        }
    }
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
    // SCORM 1.2 score.raw/min/max are numeric and are not limited to 0–100.
    const isScore = value => value === '' || (Number.isFinite(Number(value)) && Math.abs(Number(value)) <= 99999.999);

    function isValidDynamicValue(element, value) {
        if (element.length > 255) return false;
        const interaction = element.match(/^cmi\.interactions\.\d+\.(.+)$/);
        if (interaction) {
            const field = interaction[1];
            if (field === 'id' || field.endsWith('.id') || field.endsWith('.pattern')) return value.length <= 255;
            if (field === 'type') return ['true-false','choice','fill-in','matching','performance','sequencing','likert','numeric','other'].includes(value);
            if (field === 'time') return /^\d{8}T\d{6}(?:\.\d{1,2})?$/.test(value);
            if (field === 'weighting') return value !== '' && Number.isFinite(Number(value));
            if (field === 'student_response') return value.length <= 4096;
            if (field === 'result') return ['correct','wrong','unanticipated','neutral'].includes(value) || (value !== '' && Number.isFinite(Number(value)));
            if (field === 'latency') return isSessionTime(value);
            return false;
        }
        if (element.endsWith('.id')) return value.length <= 255;
        if (element.endsWith('.status')) return ['passed','completed','failed','incomplete','browsed','not attempted'].includes(value);
        return isScore(value);
    }

    let pendingRequest = null;
    let queuedRequest = null;

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
        if (!pendingRequest) return false;
        try {
            window.parent.postMessage({
                type: 'scorm:commit',
                method: pendingRequest.finish ? 'LMSFinish' : 'LMSCommit',
                attempt_id: attemptId,
                bridge_nonce: bridgeNonce,
                request_id: pendingRequest.request_id,
                data: pendingRequest.data
            }, playerOrigin);
            return true;
        } catch (error) {
            lastError = '101';
            notifyStatus(@json(__('plugins.scorm::messages.connection_error')));
            return false;
        }
    }

    function persist(finish) {
        const requestedFinish = Boolean(finish);
        const next = {
            request_id: newRequestId(),
            data: Object.assign({}, data),
            finish: requestedFinish
        };

        if (pendingRequest) {
            queuedRequest = next;
        } else {
            pendingRequest = next;
            if (!sendPendingRequest()) {
                pendingRequest = null;
                return false;
            }
        }

        if (requestedFinish) finished = true;
        lastError = '0';
        return true;
    }

    window.addEventListener('message', event => {
        if (event.source !== window.parent || event.origin !== playerOrigin
            || !event.data || typeof event.data !== 'object'
            || event.data.type !== 'scorm:commit-result'
            || event.data.bridge_nonce !== bridgeNonce) return;

        if (!pendingRequest || event.data.request_id !== pendingRequest.request_id) return;

        const completed = pendingRequest;
        pendingRequest = null;
        if (event.data.ok === true) {
            lastError = '0';
            notifyStatus(completed.finish ? @json(__('plugins.scorm::messages.saved')) : @json(__('plugins.scorm::messages.progress_saved')));
        } else {
            lastError = '101';
            notifyStatus(event.data.message || @json(__('plugins.scorm::messages.save_failed')));
        }

        if (queuedRequest) {
            pendingRequest = queuedRequest;
            queuedRequest = null;
            if (!sendPendingRequest()) {
                lastError = '101';
                pendingRequest = null;
            }
        }
    });

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
                default: valid = (dynamicPattern.test(element) || objectivePattern.test(element)) && isValidDynamicValue(element, value);
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
})();
</script>
