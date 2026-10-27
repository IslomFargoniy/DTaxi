/**
 * Ilova ichidagi (WebView) qo'ng'iroq sahifalari uchun umumiy: ICE kutish va suhbatdosh ovozini <audio>ga ulash.
 */

/**
 * JsSIP INVITE'ni ICE yig'ish to'liq tugaguncha ushlab turadi: STUN/TURN javob bermasa bu o'nlab
 * soniya. Kerakli nomzod kelsa (TURN bo'lsa relay, aks holda srflx) yoki 3 s o'tsa — darhol yuboriladi.
 */
export function iceGathering(
    servers: RTCIceServer[],
): (e: { candidate: RTCIceCandidate; ready: () => void }) => void {
    const wanted = servers.some((s) => s.username) ? 'relay' : 'srflx';
    let timeout = 0;

    return ({ candidate, ready }) => {
        if (candidate.type === wanted) {
            window.clearTimeout(timeout);
            ready();
        } else if (!timeout) {
            timeout = window.setTimeout(ready, 3000);
        }
    };
}

let attached: RTCPeerConnection | null = null;

function playRemote(stream: MediaStream, remote: HTMLAudioElement): void {
    if (remote.srcObject === stream) {
        return;
    }
    remote.srcObject = stream;
    // Ba'zi Android WebView'larda autoplay o'zi boshlanmaydi
    void remote.play().catch((err: unknown) => {
        console.warn('remote audio play failed', String(err));
    });
}

export function attachRemote(
    pc: RTCPeerConnection,
    remote: HTMLAudioElement,
): void {
    if (attached === pc) {
        return;
    }
    attached = pc;
    pc.addEventListener('track', (e: RTCTrackEvent) => {
        playRemote(e.streams[0] ?? new MediaStream([e.track]), remote);
    });
    // Trek ulanishdan oldin kelgan bo'lsa
    const tracks = pc
        .getReceivers()
        .map((r) => r.track)
        .filter((tr) => tr.kind === 'audio');
    if (tracks.length > 0) {
        playRemote(new MediaStream(tracks), remote);
    }
}
