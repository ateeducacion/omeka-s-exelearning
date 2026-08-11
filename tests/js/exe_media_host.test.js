// Unit test for the parent-side modal media host (exe-media-host.js). Loads the policy
// first (sets window.exeMediaPolicy) then the host (sets window.exeMediaHost), completes
// the window-identity handshake, and drives commands over the transferred MessageChannel
// port. Globals come from vitest.config.mjs.
require('../../asset/js/exe-media-policy.js');
require('../../asset/js/exe-media-host.js');
const host = window.exeMediaHost;

const TYPE = 'exe-media';
const V = 1;

function makeWin() {
    const handlers = [];
    return {
        addEventListener(t, cb) { if (t === 'message') handlers.push(cb); },
        removeEventListener(t, cb) { const i = handlers.indexOf(cb); if (i >= 0) handlers.splice(i, 1); },
        _emit(evt) { handlers.slice().forEach((h) => h(evt)); },
    };
}

function makeIframe() {
    return { contentWindow: { postMessage() {} } };
}

// Two linked fake MessagePorts: posting on one delivers to the other's onmessage.
function makeFakeChannel() {
    const port1 = { onmessage: null, start() {}, close() {} };
    const port2 = { onmessage: null, start() {}, close() {} };
    port1.postMessage = (m) => { if (port2.onmessage) port2.onmessage({ data: m }); };
    port2.postMessage = (m) => { if (port1.onmessage) port1.onmessage({ data: m }); };
    return { port1, port2 };
}

describe('exe-media-host openMedia() — single active media (audit L-2)', () => {
    afterEach(() => {
        document.querySelectorAll('dialog.exe-media-modal').forEach((d) => d.remove());
        if (host._resetForTests) host._resetForTests();
    });

    it('on a second open, tears down the previous adapter and modal (no stacking/leak)', () => {
        const win = makeWin();
        const iframe = makeIframe();
        const ch = makeFakeChannel();
        const adapters = [];
        const factory = () => {
            const a = {
                destroyed: false,
                play() {}, pause() {}, seek() {},
                getCurrentTime() { return 0; }, getDuration() { return 0; },
                destroy() { this.destroyed = true; },
            };
            adapters.push(a);
            return a;
        };
        host.attach(iframe, { win, genId: () => 'N1', channelFactory: () => ch, youtubeFactory: factory, document });
        win._emit({ source: iframe.contentWindow, data: { type: TYPE, v: V, action: 'hello', helloId: 'H1' } });
        const send = (cmd) => ch.port2.postMessage(Object.assign({ type: TYPE, v: V, exelearningBridge: 'N1' }, cmd));
        send({ action: 'open', reqId: 1, provider: 'youtube', videoId: 'dQw4w9WgXcQ' });
        send({ action: 'open', reqId: 2, provider: 'youtube', videoId: 'oHg5SJYRHA0' });
        expect(adapters.length).toBe(2);
        expect(adapters[0].destroyed).toBe(true);   // previous torn down
        expect(adapters[1].destroyed).toBe(false);  // current is live
        expect(document.querySelectorAll('dialog.exe-media-modal').length).toBe(1);
    });
});
