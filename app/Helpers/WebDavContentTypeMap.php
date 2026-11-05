<?php

namespace App\Helpers;

/**
 * Shared file-extension -> content-type overrides for pushing WebDAV files to eXist-db.
 *
 * Previously PushToExistDb::setMimeType() and ExistDbClient::createResource() each maintained their
 * own independent map, and had already drifted apart (one mapped 'jpg', the other didn't). Each call
 * site still owns its own fallback for extensions not listed here (File::mimeType() detection vs a
 * hardcoded default) -- only the override list itself is shared, since that's what silently drifted.
 */
class WebDavContentTypeMap
{
    private const MAP = [
        'xml' => 'application/xml',
        'html' => 'text/html',
        'json' => 'application/json',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public static function forExtension(string $extension): ?string
    {
        return self::MAP[strtolower($extension)] ?? null;
    }
}
