/* Isolated recovery state-machine checks; no browser, WordPress, or imports. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/admin.js'), 'utf8');

function section(start, end) {
    const first = source.indexOf(start), last = source.indexOf(end, first);
    assert.ok(first >= 0 && last > first, `missing recovery section: ${start}`);
    return source.slice(first, last);
}

function harness() {
    const requests = [], timers = new Map(), nodes = new Map(), storage = new Map();
    let timerId = 0;
    function node() {
        return {
            values: {}, prop(key, value) { if (arguments.length === 1) return this.values[key]; this.values[key] = value; return this; },
            text() { return this; }, val(value) { if (arguments.length === 0) return this.values.val; this.values.val = value; return this; },
            removeClass() { return this; }, addClass() { return this; }, filter() { return this; }, is() { return false; }
        };
    }
    function $(selector) { if (!nodes.has(selector)) nodes.set(selector, node()); return nodes.get(selector); }
    $.post = function (url, payload) {
        const request = { payload, done(fn) { this.onDone = fn; return this; }, fail(fn) { this.onFail = fn; return this; }, always(fn) { this.onAlways = fn; return this; } };
        requests.push(request); return request;
    };
    const context = {
        $, config: { ajaxUrl: '/admin-ajax.php', nonce: 'nonce' }, sessionId: 'session', sessionToken: 'token',
        archiveHash: '', planHash: '', plan: {}, reportStatus: '', allowSensitiveSettings: false, importTemplateImages: false,
        busy: true, requestBusy: false, mutationActive: true, cancelPending: false, continuationTimer: 0,
        cancelButtons: node(), confirmButton: node(), backup: node(), warningAck: node(), warningWrap: node(), conflict: node(), overwrite: node(),
        progressModal: node(), confirmModal: node(), message: node(),
        disableForm() {}, close() {}, open() {}, progress() {}, renderReview() {}, resetProgress() {}, resetFormState() {},
        responseEnvelope(response) { const data = response && (response.responseJSON || response).data || {}; return { data, code: data.code || '' }; },
        isImportInProgress(response) { return this.responseEnvelope(response).code === 'import_in_progress'; },
        errorMessage() { return 'error'; }, t(key, fallback) { return fallback; },
        window: {
            sessionStorage: { setItem(key, value) { storage.set(key, value); }, removeItem(key) { storage.delete(key); } },
            setTimeout(fn) { const id = ++timerId; timers.set(id, fn); return id; }, clearTimeout(id) { timers.delete(id); }
        }
    };
    context.terminal = function () { context.cancelPending = false; context.clearContinuation(); };
    vm.createContext(context);
    vm.runInContext(
        section('    function clearContinuation()', '    function terminal(') +
        section('    function recoveredStatus(data)', '    function cancel()') +
        section('    function cancel()', '    function confirmImport()'),
        context
    );
    return {
        context, requests, timers, nodes, storage,
        runTimer() { assert.equal(timers.size, 1); const [id, fn] = timers.entries().next().value; timers.delete(id); fn(); },
        actions() { return requests.map(request => request.payload.action); }
    };
}

{
    const h = harness();
    h.context.cancel();
    assert.deepEqual(h.actions(), ['bricks_ie_import_cancel']);
    assert.equal(h.context.cancelPending, true);
    assert.equal(JSON.parse(h.storage.get('bricks-ie-import-session-v1')).cancelPending, true);
    h.requests[0].onAlways({}); // The cancellation response was lost.
    assert.deepEqual(h.actions(), ['bricks_ie_import_cancel', 'bricks_ie_import_status']);
    h.requests[1].onDone({ success: true, data: { status: 'confirmed', processing: false } });
    assert.deepEqual(h.actions(), ['bricks_ie_import_cancel', 'bricks_ie_import_status', 'bricks_ie_import_cancel']);
    h.requests[2].onAlways({ success: true, data: {} });
    assert.equal(h.context.cancelPending, false);
}

{
    const h = harness();
    h.context.nextStep();
    h.requests[0].onFail({}); // The server may still be running the step.
    assert.deepEqual(h.actions(), ['bricks_ie_import_step', 'bricks_ie_import_status']);
    h.requests[1].onDone({ success: true, data: { status: 'confirmed', processing: true } });
    assert.deepEqual(h.actions(), ['bricks_ie_import_step', 'bricks_ie_import_status']);
    h.runTimer();
    h.requests[2].onDone({ success: true, data: { status: 'confirmed', processing: false } });
    h.runTimer();
    assert.deepEqual(h.actions(), ['bricks_ie_import_step', 'bricks_ie_import_status', 'bricks_ie_import_status', 'bricks_ie_import_step']);
}

{
    const h = harness();
    h.context.cancel();
    h.requests[0].onAlways({ responseJSON: { data: { code: 'import_in_progress' } } });
    h.requests[1].onDone({ success: true, data: { status: 'confirmed', processing: true } });
    assert.equal(h.timers.size, 1);
    h.runTimer();
    h.requests[2].onDone({ success: true, data: { status: 'confirmed', processing: false } });
    assert.deepEqual(h.actions(), ['bricks_ie_import_cancel', 'bricks_ie_import_status', 'bricks_ie_import_status', 'bricks_ie_import_cancel']);
    assert.ok(!h.actions().includes('bricks_ie_import_step'));
}

{
    const h = harness();
    h.context.nextStep();
    h.requests[0].onDone({ success: true, data: { done: false } });
    assert.equal(h.timers.size, 1);
    h.context.cancel();
    assert.equal(h.timers.size, 0, 'queued mutation continuation must be cancelled');
    h.context.nextStep();
    assert.deepEqual(h.actions(), ['bricks_ie_import_step', 'bricks_ie_import_cancel']);
}

{
    const h = harness();
    h.context.cancelButtons.prop('disabled', true);
    h.context.recoveredStatus({ status: 'awaiting_confirmation', processing: false, preflight: { status: 'ready', plan: { conflict_mode: 'replace', allow_overwrite: true }, archive_hash: 'hash', plan_hash: 'plan' } });
    assert.equal(h.context.cancelButtons.prop('disabled'), false);
    assert.equal(h.context.confirmButton.prop('disabled'), true);
    assert.equal(h.context.backup.prop('checked'), false);
    assert.equal(h.context.overwrite.prop('required'), true);
    assert.deepEqual(h.actions(), []);
}

console.log('5 admin recovery tests passed.');
