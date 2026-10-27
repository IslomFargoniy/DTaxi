<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{{ $title }}</title>
    <style>
        /* Telefonning odatiy qo'ng'iroq ekrani: tepada kim, o'rtada rasm, pastda boshqaruv va qizil tugma */
        :root {
            color-scheme: light dark;
            --bg: linear-gradient(180deg, #eef1f6 0%, #ffffff 60%);
            --fg: #111827; --muted: #6b7280;
            --btn: rgba(17, 24, 39, 0.06); --btn-on: #111827; --icon-on: #ffffff;
            --red: #ef3b3b; --brand: #ffc800;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: linear-gradient(180deg, #1c2333 0%, #0d1119 65%);
                --fg: #f3f4f6; --muted: #9ca3af;
                --btn: rgba(255, 255, 255, 0.09); --btn-on: #f3f4f6; --icon-on: #111827;
            }
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--fg); font-family: system-ui, -apple-system, Roboto, sans-serif; }
        main { height: 100%; display: flex; flex-direction: column; align-items: center; padding: 72px 24px 56px; }
        .who { text-align: center; }
        h1 { margin: 0; font-size: 30px; font-weight: 400; letter-spacing: 0.2px; }
        #status { margin-top: 10px; color: var(--muted); font-size: 16px; min-height: 22px; }
        #timer { margin-top: 4px; color: var(--muted); font-size: 16px; font-variant-numeric: tabular-nums; min-height: 22px; }
        .avatar { margin: auto 0; width: 128px; height: 128px; border-radius: 50%; background: var(--brand); display: grid; place-items: center; }
        .avatar svg { width: 64px; height: 64px; color: #111827; }
        .avatar.live { animation: pulse 2s ease-out infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 200, 0, 0.45); } 100% { box-shadow: 0 0 0 28px rgba(255, 200, 0, 0); } }
        .controls { display: flex; gap: 48px; margin-bottom: 44px; }
        .ctl { display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
        button { border: 0; padding: 0; cursor: pointer; display: grid; place-items: center; }
        .ctl button { width: 68px; height: 68px; border-radius: 50%; background: var(--btn); color: var(--fg); transition: background 0.15s; }
        .ctl button svg { width: 28px; height: 28px; }
        .ctl button.on { background: var(--btn-on); color: var(--icon-on); }
        .ctl .alt { display: none; }
        .ctl button.on .alt { display: block; }
        .ctl button.on .main { display: none; }
        #hangup { width: 76px; height: 76px; border-radius: 50%; background: var(--red); color: #fff; box-shadow: 0 6px 18px rgba(239, 59, 59, 0.35); }
        #hangup svg { width: 34px; height: 34px; transform: rotate(135deg); }
        #hangup:active, .ctl button:active { transform: scale(0.94); }
    </style>
    {{-- Haydovchi (driver-call.ts) va mijoz (client-call.ts) ilovalari ichidagi qo'ng'iroq ekrani --}}
    @vite($script)
</head>
<body>
<main>
    <div class="who">
        <h1 id="title">{{ $title }}</h1>
        <div id="status"></div>
        <div id="timer"></div>
    </div>

    <div class="avatar" id="avatar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 14v-2a8 8 0 0 1 16 0v2"/>
            <rect x="3" y="13" width="4" height="6" rx="1.5"/>
            <rect x="17" y="13" width="4" height="6" rx="1.5"/>
            <path d="M19 19c0 1.5-1.5 2.5-4 2.5h-2"/>
        </svg>
    </div>

    <audio id="remote" autoplay></audio>

    <div class="controls">
        <div class="ctl">
            <button id="mute" aria-label="mute">
                <svg class="main" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9" y="3" width="6" height="11" rx="3"/>
                    <path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M9 21h6"/>
                </svg>
                <svg class="alt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 9.5V6a3 3 0 0 0-5.8-1.1M9 9v2a3 3 0 0 0 4.6 2.5"/>
                    <path d="M5.5 11a6.5 6.5 0 0 0 10.4 5.2M18.5 11c0 .8-.1 1.5-.4 2.2M12 17.5V21M9 21h6M3 3l18 18"/>
                </svg>
            </button>
            <span id="mute-label">Ovozni o'chirish</span>
        </div>
        <div class="ctl">
            <button id="speaker" class="on" aria-label="speaker">
                <svg class="main" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/>
                </svg>
                <svg class="alt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/>
                    <path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>
                </svg>
            </button>
            <span id="speaker-label">Karnay</span>
        </div>
    </div>

    <button id="hangup" aria-label="hangup">
        <svg viewBox="0 0 24 24" fill="currentColor">
            <path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/>
        </svg>
    </button>
</main>
</body>
</html>
