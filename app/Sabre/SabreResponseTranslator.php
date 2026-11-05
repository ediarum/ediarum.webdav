<?php

namespace App\Sabre;

use Illuminate\Support\Facades\Response as ResponseFactory;
use Sabre\HTTP\Response as SabreResponse;
use Symfony\Component\HttpFoundation\Response;

class SabreResponseTranslator
{
    public static function toLaravelResponse(SabreResponse $sabreResponse): Response
    {
        /** @var resource|string|null */
        $body = $sabreResponse->getBody();
        $status = $sabreResponse->getStatus();
        $headers = $sabreResponse->getHeaders();

        if (is_null($body) || is_string($body)) {
            return ResponseFactory::make($body, $status, $headers);
        }

        $contentLength = $sabreResponse->getHeader('Content-Length');

        return ResponseFactory::stream(function () use ($body, $contentLength): void {
            if (is_numeric($contentLength) || (!is_null($contentLength) && ctype_digit($contentLength))) {
                echo stream_get_contents($body, intval($contentLength));
            } else {
                echo stream_get_contents($body);
            }
        }, $status, $headers);
    }
}
