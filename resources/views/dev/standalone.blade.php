<!doctype html>
{{-- Local development only, see App\Http\Controllers\DevStandaloneController. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ediarum.webdav (standalone dev)</title>
    <style>
        /* Like the portal's content area, the mount element fills the window. */
        html, body, #app { height: 100%; }
        body { margin: 0; font-family: system-ui, sans-serif; }
        #wd-dev-error { padding: 1rem; color: #b91c1c; white-space: pre-wrap; }
    </style>
</head>
<body>
<div id="app"></div>
<div id="wd-dev-error" hidden></div>
<script type="module">
    const ctx = @json($ctx);
    const showError = (message) => {
        const el = document.getElementById('wd-dev-error');
        el.textContent = message;
        el.hidden = false;
    };

    // A 401 means the session is gone: reload, which logs in again. A second 401 right after a
    // reload means the session doesn't stick (e.g. SESSION_SECURE_COOKIE=true over http), so stop.
    const RELOADED = 'wd-dev-reloaded';
    try {
        if (Date.now() - Number(sessionStorage.getItem(RELOADED) || 0) > 10000) {
            sessionStorage.removeItem(RELOADED);
        }
    } catch {}
    ctx.onUnauthorized = () => {
        let reloadedRecently = false;
        try {
            reloadedRecently = sessionStorage.getItem(RELOADED) !== null;
            sessionStorage.setItem(RELOADED, String(Date.now()));
        } catch {}
        if (reloadedRecently) {
            showError('Still 401 after logging in again. Check SESSION_SECURE_COOKIE (false over http) and the Laravel log.');
        } else {
            location.reload();
        }
    };

    // The router base is /webdav/; Laravel's redirects and links drop the trailing slash.
    if (location.pathname === '/webdav') {
        history.replaceState(null, '', '/webdav/' + location.search + location.hash);
    }

    try {
        const {mount, contractVersion} = await import('/apps/webdav/embed.js');
        if (contractVersion !== 1) {
            showError(`embed.js has contractVersion ${contractVersion}; this page mounts version 1.`);
        } else {
            mount(document.getElementById('app'), ctx);
        }
    } catch (e) {
        showError(`Could not load /apps/webdav/embed.js. Is \`npm run dev\` (or \`composer dev\`) running?\n\n${e}`);
    }
</script>
</body>
</html>
