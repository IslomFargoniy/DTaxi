<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Dispetcher</title>
    <style>
        :root { color-scheme: light dark; --bg: #ffffff; --fg: #111827; --muted: #6b7280; --red: #dc2626; --btn: #e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --fg: #f9fafb; --muted: #9ca3af; --btn: #374151; } }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--fg); font-family: system-ui, sans-serif; }
        main { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 16px; box-sizing: border-box; }
        h1 { margin: 0; font-size: 26px; }
        #status { color: var(--muted); font-size: 17px; min-height: 24px; }
        #timer { font-size: 32px; font-variant-numeric: tabular-nums; min-height: 40px; }
        .row { display: flex; gap: 28px; margin-top: 32px; }
        button { width: 72px; height: 72px; border-radius: 50%; border: 0; font-size: 28px; background: var(--btn); color: var(--fg); }
        button.hangup { background: var(--red); color: #fff; }
        button.on { background: var(--fg); color: var(--bg); }
    </style>
    @vite('resources/js/driver-call.ts')
</head>
<body>
<main>
    <h1 id="title">Dispetcher</h1>
    <div id="status"></div>
    <div id="timer"></div>
    <audio id="remote" autoplay></audio>
    <div class="row">
        <button id="mute" aria-label="mute">🎙</button>
        <button id="speaker" class="on" aria-label="speaker">🔊</button>
        <button id="hangup" class="hangup" aria-label="hangup">✕</button>
    </div>
</main>
</body>
</html>
