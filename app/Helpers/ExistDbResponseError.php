<?php

namespace App\Helpers;

use Illuminate\Http\Client\Response;

/**
 * A single place to format "eXist-db rejected this request" messages, shared by PushToExistDb and
 * ExistDbClient (previously each built its own slightly different string). eXist-db's REST API
 * often returns a near-empty body on a 400 (the real detail tends to be in eXist's own server log,
 * not the HTTP response) -- an unquoted, unlabelled body left the message ambiguous about whether
 * there was no response text at all, or the response text just happened to be something terse like
 * a single ".". Quoting a present body and calling out an absent one explicitly resolves that.
 */
class ExistDbResponseError
{
    public static function describe(Response $response): string
    {
        $body = trim($response->body());
        $bodyDescription = $body === '' ? 'no response body' : 'response body: "' . $body . '"';

        return "Request to {$response->effectiveUri()} failed with status {$response->status()} ({$bodyDescription}).";
    }
}
