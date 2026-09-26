<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Enums;

use LIVCK\Cloud\Enums\Concerns\ToleratesUnknownValues;

/**
 * The image files of a status page, by their path segment in
 * `/statuspages/{id}/assets/{asset}`.
 *
 * The server checks type and size by the file's content: logos take JPEG, PNG, WebP or SVG
 * up to 2 MB, the favicon ICO, PNG or SVG up to 1 MB. SVGs are sanitized, raster images
 * must be readable. After an upload the page's `logoUrl`, `logoDarkUrl` or `faviconUrl`
 * holds the served address.
 */
enum AssetType: string implements ApiEnum
{
    use ToleratesUnknownValues;

    /** Shown in the page header (`logoUrl`). */
    case Logo = 'logo';

    /** The logo for the dark theme (`logoDarkUrl`). */
    case LogoDark = 'logo-dark';

    /** The browser tab icon (`faviconUrl`). */
    case Favicon = 'favicon';

    /** A value this SDK version does not know; cannot be sent. */
    case Unrecognized = '__unrecognized__';
}
