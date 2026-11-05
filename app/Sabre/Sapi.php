<?php


namespace App\Sabre;

use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi as BaseSapi;

/**
 * Mock version of Sapi server to avoid 'header()' calls.
 * Taken from: https://github.com/monicahq/laravel-sabre/blob/main/src/Sabre/Sapi.php
 */
class Sapi extends BaseSapi
{
    public static function sendResponse(ResponseInterface $response)
    {
    }
}
