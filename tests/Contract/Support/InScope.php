<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Contract\Support;

/**
 * The operations the SDK promises to cover. Each must be reachable through a public SDK
 * method and may never end up in {@see OutOfScope}.
 */
final class InScope
{
    /**
     * @return list<string> `METHOD /path` as the document keys them
     */
    public static function operations(): array
    {
        return [
            // Reference and the calling token
            'GET /me',
            'GET /probes',
            'GET /meta/check-types',

            // Tags
            'GET /tags',
            'POST /tags',
            'POST /tags/ensure',
            'GET /tags/{tag}',
            'PUT /tags/{tag}',
            'DELETE /tags/{tag}',

            // Services and everything beneath them
            'GET /services',
            'POST /services',
            'GET /services/{service}',
            'PUT /services/{service}',
            'DELETE /services/{service}',
            'POST /services/{service}/pause',
            'POST /services/{service}/resume',
            'POST /services/{service}/status-override',
            'DELETE /services/{service}/status-override',
            'GET /services/{service}/metrics',
            'GET /services/{service}/uptime',
            'GET /services/{service}/response-times',
            'GET /services/{service}/checks',
            'GET /services/{service}/incidents',
            'GET /services/{service}/maintenances',

            // Incidents and maintenances, the read side
            'GET /incidents',
            'GET /incidents/{incident}',
            'GET /maintenances',
            'GET /maintenances/{maintenance}',

            // Status pages with their assets, components and custom domains
            'GET /statuspages',
            'POST /statuspages',
            'GET /statuspages/{statuspage}',
            'PUT /statuspages/{statuspage}',
            'DELETE /statuspages/{statuspage}',
            'POST /statuspages/{statuspage}/publish',
            'POST /statuspages/{statuspage}/unpublish',
            'POST /statuspages/{statuspage}/assets/{asset}',
            'DELETE /statuspages/{statuspage}/assets/{asset}',
            'GET /statuspages/{statuspage}/components',
            'POST /statuspages/{statuspage}/components',
            'GET /statuspages/{statuspage}/components/{component}',
            'PUT /statuspages/{statuspage}/components/{component}',
            'DELETE /statuspages/{statuspage}/components/{component}',
            'GET /statuspages/{statuspage}/custom-domains',
            'POST /statuspages/{statuspage}/custom-domains',
            'GET /statuspages/{statuspage}/custom-domains/{domain}',
            'DELETE /statuspages/{statuspage}/custom-domains/{domain}',
            'POST /statuspages/{statuspage}/custom-domains/{domain}/verify',
        ];
    }
}
