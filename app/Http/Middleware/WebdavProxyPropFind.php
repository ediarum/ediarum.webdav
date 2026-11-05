<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebdavProxyPropFind
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $proxyRoot = $request->header('X-WebDAV-Proxy-Root');

        Log::debug('Webdav Proxy PropFind Middleware Activated with proxy root: ' . $proxyRoot);

        if (!$proxyRoot) {
            return $response;
        }
        // Only proceed if it's a response with content and content-type is XML or HTML-ish
        if (!$response->isSuccessful() || $request->method() !== 'PROPFIND') {
            return $response;
        }

        $contentType = $response->headers->get('Content-Type', '');

        if (stripos($contentType, 'xml') === false && stripos($contentType, 'html') === false) {
            return $response;
        }


        //Log::info('Webdav Proxy' . $proxyRoot);

        $content = $response->getContent();

        $appUrl = config('app.url');
        $appSubpath = parse_url($appUrl, PHP_URL_PATH) ?: '/';
        $backendRoot = rtrim($appSubpath, "/") . '/connection';

        // Normalize trailing slashes (remove trailing slash for consistency)
        $proxyRoot = rtrim($proxyRoot, '/');
        $backendRoot = rtrim($backendRoot, '/');

        //Log::info('Doing a find replace for'. $backendRoot ."and proxy" . $proxyRoot);
        // Replace href URLs in <d:href> elements:
        // e.g. <d:href>/ediarum-webdav/webdav/something</d:href>
        // becomes <d:href>/webdav/connection/something</d:href>

        // Regex to find <d:href>...</d:href> and replace backend root URLs
        $pattern = '#(<d:href>)(\s*' . preg_quote($backendRoot, '#') . ')(/[^<]*)?(</d:href>)#i';

        $replacement = function ($matches) use ($proxyRoot) {
            // $matches:
            // [1] = <d:href>
            // [2] = backend root
            // [3] = rest of path (optional)
            // [4] = </d:href>
            $path = $matches[3] ?? '';
            return $matches[1] . $proxyRoot . $path . $matches[4];
        };

        $newContent = preg_replace_callback($pattern, $replacement, $content);

        if ($newContent !== null) {
            $response->setContent($newContent);
        }

        return $response;
    }
}
