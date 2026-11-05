<?php

namespace App\Sabre;

class WebDavBaseUri
{
    public static function build(?string $subpath, string $projectSlug): string
    {
        return rtrim((string) $subpath, '/') . '/connection/' . $projectSlug;
    }
}
