<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $headline }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f7f9; --card: #ffffff; --ink: #16181d; --muted: #6b7280;
            --line: #e5e7eb; --accent: #b45309; --accent-bg: #fffbeb;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d0e12; --card: #16181d; --ink: #e8eaed; --muted: #9aa0a6;
                --line: #2a2d34; --accent: #fbbf24; --accent-bg: #1f1b10;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: var(--bg); color: var(--ink);
            font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%; max-width: 560px; background: var(--card);
            border: 1px solid var(--line); border-radius: 14px; padding: 32px;
            box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.06);
        }
        .badge {
            display: inline-flex; align-items: center; gap: 7px; margin-bottom: 20px;
            padding: 5px 11px; border-radius: 999px; background: var(--accent-bg);
            color: var(--accent); font-size: 12px; font-weight: 600;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        h1 { margin: 0 0 12px; font-size: 21px; font-weight: 650; letter-spacing: -.01em; }
        p { margin: 0 0 16px; color: var(--muted); }
        .message {
            margin: 20px 0 0; padding: 16px 18px; border-radius: 9px;
            background: var(--accent-bg); border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent);
            color: var(--ink);
        }
        .message strong { display: block; margin-bottom: 6px; font-size: 12px;
            letter-spacing: .04em; text-transform: uppercase; color: var(--accent); }
        .note { margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--line); font-size: 13px; color: var(--muted); }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">{{ $state === 'halted' ? 'License' : ucfirst($state) }}</span>

        <h1>{{ $headline }}</h1>

        <p>This system cannot be modified until its license is renewed. Please contact your vendor to restore access.</p>

        @if (! empty($message))
            {{-- The vendor's own words. Without them the customer knows only
                 that something stopped, and the first thing they do is
                 telephone somebody to ask why. --}}
            <div class="message">
                <strong>From your vendor</strong>
                {{ $message }}
            </div>
        @endif

        {{-- Stated plainly, because it is the first thing an anxious
             administrator needs to know, and because it is true. --}}
        <div class="note">
            Your data has not been altered or removed, and remains readable and
            exportable. Once the license is renewed the system resumes exactly
            where it left off.
        </div>
    </main>
</body>
</html>
