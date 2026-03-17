<?php
/**
 * Offline Fallback Page
 */
defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <title>Offline - PTP Soccer</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --gold: #FCB900;
            --black: #0A0A0A;
            --gray: #6B7280;
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--black);
            color: #fff;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            padding-top: calc(40px + var(--safe-top));
            padding-bottom: calc(40px + var(--safe-bottom));
            text-align: center;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin-bottom: 32px;
        }
        
        .icon {
            width: 64px;
            height: 64px;
            background: rgba(252, 185, 0, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        
        .icon svg {
            width: 32px;
            height: 32px;
            stroke: var(--gold);
        }
        
        h1 {
            font-family: 'Oswald', sans-serif;
            font-size: 28px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }
        
        p {
            color: var(--gray);
            font-size: 15px;
            line-height: 1.6;
            max-width: 320px;
            margin-bottom: 32px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--gold);
            color: var(--black);
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 14px 28px;
            border-radius: 10px;
            text-decoration: none;
            transition: transform 0.15s, opacity 0.15s;
        }
        
        .btn:active {
            transform: scale(0.97);
            opacity: 0.9;
        }
        
        .btn svg {
            width: 18px;
            height: 18px;
        }
        
        .cached-pages {
            margin-top: 48px;
            padding-top: 32px;
            border-top: 1px solid rgba(255,255,255,0.1);
            width: 100%;
            max-width: 320px;
        }
        
        .cached-pages h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--gray);
            margin-bottom: 16px;
        }
        
        .cached-link {
            display: block;
            padding: 12px 16px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 8px;
            transition: background 0.15s;
        }
        
        .cached-link:active {
            background: rgba(255,255,255,0.1);
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--gray);
            margin-top: 24px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            background: #EF4444;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        .status-dot.online {
            background: #22C55E;
            animation: none;
        }
    </style>
</head>
<body style="margin: 0; padding: 0; overflow-y: scroll !important; height: auto !important; position: static !important;">
<script>
// v133.2.1: Force scroll to work
(function(){
    document.documentElement.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important;';
    document.body.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important; margin: 0; padding: 0;';
    document.body.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
    document.documentElement.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
})();
</script>
<div id="ptp-scroll-wrapper" style="width: 100%;">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="1" y1="1" x2="23" y2="23"></line>
            <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"></path>
            <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"></path>
            <path d="M10.71 5.05A16 16 0 0 1 22.58 9"></path>
            <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"></path>
            <path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>
            <line x1="12" y1="20" x2="12.01" y2="20"></line>
        </svg>
    </div>
    
    <h1>You're Offline</h1>
    <p>It looks like you've lost your internet connection. Don't worry, you can still browse cached pages.</p>
    
    <button class="btn" onclick="location.reload()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M23 4v6h-6"></path>
            <path d="M1 20v-6h6"></path>
            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
        </svg>
        Try Again
    </button>
    
    <div class="cached-pages" id="cachedPages">
        <h3>Available Offline</h3>
        <a href="/" class="cached-link">🏠 Home</a>
        <a href="/find-trainers/" class="cached-link">🔍 Find Trainers</a>
        <a href="/my-training/" class="cached-link">📋 My Training</a>
    </div>
    
    <div class="status">
        <span class="status-dot" id="statusDot"></span>
        <span id="statusText">Waiting for connection...</span>
    </div>
    
    <script>
        // Check online status
        function updateStatus() {
            const dot = document.getElementById('statusDot');
            const text = document.getElementById('statusText');
            
            if (navigator.onLine) {
                dot.classList.add('online');
                text.textContent = 'Back online! Reloading...';
                setTimeout(() => location.reload(), 1000);
            } else {
                dot.classList.remove('online');
                text.textContent = 'Waiting for connection...';
            }
        }
        
        window.addEventListener('online', updateStatus);
        window.addEventListener('offline', updateStatus);
        updateStatus();
        
        // Check cached pages
        if ('caches' in window) {
            caches.open('ptp-v88').then(cache => {
                cache.keys().then(requests => {
                    const pages = requests
                        .filter(r => r.url.includes(location.origin) && !r.url.includes('.'))
                        .map(r => new URL(r.url).pathname);
                    
                    console.log('Cached pages:', pages);
                });
            });
        }
    </script>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
