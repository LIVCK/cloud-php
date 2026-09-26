<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Tests\Fixtures;

/**
 * `GET /v1/meta/check-types` as the server answered it (CheckTypeCatalog::all(), taken
 * from a development stack on 2026-09-26). The catalog validation tests run against this.
 */
final class CatalogFixture
{
    /**
     * The `data` object keyed by check type, decoded.
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode(self::json(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['data'];
    }

    /**
     * The whole response body.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        return ['data' => self::data()];
    }

    public static function json(): string
    {
        return <<<'CATALOG'
{
    "data": {
        "http": {
            "key": "http",
            "label": "HTTP/HTTPS",
            "description": "Monitors HTTP endpoints (websites, APIs)",
            "target_required": true,
            "fields": [
                {"name": "method", "type": "select", "label": "HTTP Method", "required": false, "secret": false, "options": ["GET", "POST", "PUT", "DELETE", "PATCH", "HEAD", "OPTIONS"], "default": "GET"},
                {"name": "headers", "type": "key_value_array", "label": "Custom Headers", "required": false, "secret": true, "help": "Optional HTTP headers to send with the request", "default": []},
                {"name": "auth", "type": "auth", "label": "Authentication", "required": false, "secret": false, "help": "Optional. Bearer token, basic auth, or an API-key header. Secrets are encrypted at rest.", "default": {"type": "none"}},
                {"name": "body", "type": "textarea", "label": "Request Body", "required": false, "secret": false, "help": "Body content for POST/PUT/PATCH requests", "default": ""},
                {"name": "follow_redirects", "type": "boolean", "label": "Follow Redirects", "required": false, "secret": false, "help": "Follow HTTP redirects (3xx). Disable to check the initial response only.", "default": true},
                {"name": "verify_ssl", "type": "boolean", "label": "Verify SSL Certificate", "required": false, "secret": false, "help": "Verify the SSL certificate is valid. Disable for self-signed certificates.", "default": true},
                {"name": "ip_version", "type": "select", "label": "IP Version", "required": false, "secret": false, "help": "Auto tries both IPv4 and IPv6 and reports \"up\" as soon as either is reachable. Choose ipv4 or ipv6 to force a single family (no fallback).", "options": ["auto", "ipv4", "ipv6"], "default": "auto"},
                {"name": "smart_dualstack", "type": "boolean", "label": "Smart dual-stack detection", "required": false, "secret": false, "help": "If the host offers both IPv4 and IPv6 but only one is reachable, report \"Degraded\" instead of \"Up\". Only applies when IP version is set to \"auto\".", "default": false}
            ],
            "conditions": {
                "fields": [
                    {"field": "status_code", "label": "Status Code", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte", "in", "not_in"]},
                    {"field": "response_time_ms", "label": "Response Time", "type": "number", "operators": ["gt", "gte", "lt", "lte"], "unit": "ms"},
                    {"field": "body", "label": "Response body (text)", "type": "string", "operators": ["contains", "not_contains"]},
                    {"field": "json", "label": "JSON field", "type": "json", "operators": ["eq", "neq", "gt", "gte", "lt", "lte", "contains", "not_contains"], "parametric": true, "prefix": "json.", "key_label": "JSON path", "key_placeholder": "data.status", "key_help": "Dot path into the JSON body — e.g. data.status, items.0.id, or items.# for the array length."},
                    {"field": "header", "label": "Response header", "type": "header", "operators": ["eq", "neq", "contains", "not_contains"], "parametric": true, "prefix": "header.", "key_label": "Header name", "key_placeholder": "content-type", "key_help": "The response header to check (case-insensitive), e.g. content-type or x-cache."},
                    {"field": "metadata.redirects_followed", "label": "Redirects followed", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]},
                    {"field": "metadata.content_length", "label": "Response size", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"], "unit": "bytes"},
                    {"field": "metadata.protocol", "label": "HTTP protocol", "type": "string", "operators": ["eq", "neq", "contains", "not_contains"]},
                    {"field": "metadata.final_url", "label": "Final URL (after redirects)", "type": "string", "operators": ["eq", "neq", "contains", "not_contains"]}
                ],
                "defaults": [
                    {"field": "status_code", "operator": "gte", "value": 400, "status": "down"}
                ]
            }
        },
        "tcp": {
            "key": "tcp",
            "label": "TCP Port",
            "description": "Checks if a TCP port is reachable",
            "target_required": true,
            "fields": [
                {"name": "ip_version", "type": "select", "label": "IP Version", "required": false, "secret": false, "help": "Auto tries both IPv4 and IPv6 and reports \"up\" as soon as either is reachable. Choose ipv4 or ipv6 to force a single family (no fallback).", "options": ["auto", "ipv4", "ipv6"], "default": "auto"},
                {"name": "smart_dualstack", "type": "boolean", "label": "Smart dual-stack detection", "required": false, "secret": false, "help": "If the host offers both IPv4 and IPv6 but only one is reachable, report \"Degraded\" instead of \"Up\". Only applies when IP version is set to \"auto\".", "default": false}
            ],
            "conditions": {
                "fields": [
                    {"field": "response_time_ms", "label": "Response Time", "type": "number", "operators": ["gt", "gte", "lt", "lte"], "unit": "ms"},
                    {"field": "metadata.resolved_ip_count", "label": "Resolved IP count", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]}
                ],
                "defaults": []
            }
        },
        "dns": {
            "key": "dns",
            "label": "DNS",
            "description": "Monitors DNS records",
            "target_required": true,
            "fields": [
                {"name": "dns_type", "type": "select", "label": "Record Type", "required": true, "secret": false, "options": ["A", "AAAA", "MX", "CNAME", "TXT", "NS"], "default": "A"}
            ],
            "conditions": {
                "fields": [
                    {"field": "response_time_ms", "label": "Response Time", "type": "number", "operators": ["gt", "gte", "lt", "lte"], "unit": "ms"},
                    {"field": "metadata.ip_count", "label": "IP Count", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]},
                    {"field": "metadata.ips", "label": "Resolved IPs", "type": "array", "operators": ["contains", "not_contains"]},
                    {"field": "metadata.ns_count", "label": "NS Count", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]},
                    {"field": "metadata.ns_records", "label": "Nameservers", "type": "array", "operators": ["contains", "not_contains"]},
                    {"field": "metadata.mx_count", "label": "MX Count", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]},
                    {"field": "metadata.mx_records", "label": "Mail Servers", "type": "array", "operators": ["contains", "not_contains"]},
                    {"field": "metadata.cname", "label": "CNAME Target", "type": "string", "operators": ["eq", "neq", "contains", "not_contains"]},
                    {"field": "metadata.txt_count", "label": "TXT Count", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]},
                    {"field": "metadata.txt_records", "label": "TXT Records", "type": "array", "operators": ["contains", "not_contains"]}
                ],
                "defaults": [
                    {"field": "metadata.ip_count", "operator": "eq", "value": 0, "status": "down"}
                ],
                "by_subtype": {
                    "field": "dns_type",
                    "map": {
                        "A": ["response_time_ms", "metadata.ip_count", "metadata.ips"],
                        "AAAA": ["response_time_ms", "metadata.ip_count", "metadata.ips"],
                        "MX": ["response_time_ms", "metadata.mx_count", "metadata.mx_records"],
                        "NS": ["response_time_ms", "metadata.ns_count", "metadata.ns_records"],
                        "TXT": ["response_time_ms", "metadata.txt_count", "metadata.txt_records"],
                        "CNAME": ["response_time_ms", "metadata.cname"]
                    }
                }
            }
        },
        "icmp": {
            "key": "icmp",
            "label": "ICMP (Ping)",
            "description": "Pings a host with ICMP echo requests",
            "target_required": true,
            "fields": [
                {"name": "ip_version", "type": "select", "label": "IP Version", "required": false, "secret": false, "help": "Auto tries both IPv4 and IPv6 and reports \"up\" as soon as either is reachable. Choose ipv4 or ipv6 to force a single family (no fallback).", "options": ["auto", "ipv4", "ipv6"], "default": "auto"},
                {"name": "smart_dualstack", "type": "boolean", "label": "Smart dual-stack detection", "required": false, "secret": false, "help": "If the host offers both IPv4 and IPv6 but only one is reachable, report \"Degraded\" instead of \"Up\". Only applies when IP version is set to \"auto\".", "default": false}
            ],
            "conditions": {
                "fields": [
                    {"field": "response_time_ms", "label": "Response Time", "type": "number", "operators": ["gt", "gte", "lt", "lte"], "unit": "ms"},
                    {"field": "metadata.packet_loss_pct", "label": "Packet Loss", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"], "unit": "%"},
                    {"field": "metadata.max_rtt_ms", "label": "Max RTT", "type": "number", "operators": ["gt", "gte", "lt", "lte"], "unit": "ms"},
                    {"field": "metadata.packets_received", "label": "Packets received", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]}
                ],
                "defaults": [
                    {"field": "metadata.packet_loss_pct", "operator": "gt", "value": 0, "status": "degraded"}
                ]
            }
        },
        "ssl": {
            "key": "ssl",
            "label": "SSL Certificate",
            "description": "Monitors SSL/TLS certificate expiry",
            "target_required": true,
            "fields": [
                {"name": "ip_version", "type": "select", "label": "IP Version", "required": false, "secret": false, "help": "Auto tries both IPv4 and IPv6 and reports \"up\" as soon as either is reachable. Choose ipv4 or ipv6 to force a single family (no fallback).", "options": ["auto", "ipv4", "ipv6"], "default": "auto"},
                {"name": "smart_dualstack", "type": "boolean", "label": "Smart dual-stack detection", "required": false, "secret": false, "help": "If the host offers both IPv4 and IPv6 but only one is reachable, report \"Degraded\" instead of \"Up\". Only applies when IP version is set to \"auto\".", "default": false}
            ],
            "conditions": {
                "fields": [
                    {"field": "metadata.days_until_expiry", "label": "Days Until Expiry", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"], "unit": "days"},
                    {"field": "metadata.issuer", "label": "Certificate Issuer", "type": "string", "operators": ["eq", "neq", "contains", "not_contains"]},
                    {"field": "metadata.subject", "label": "Certificate Subject (CN)", "type": "string", "operators": ["eq", "neq", "contains", "not_contains"]},
                    {"field": "metadata.dns_names", "label": "Certificate SANs", "type": "array", "operators": ["contains", "not_contains"]},
                    {"field": "metadata.tls_version", "label": "TLS Version", "type": "string", "operators": ["eq", "neq", "in", "not_in", "contains", "not_contains"]},
                    {"field": "metadata.cipher_suite", "label": "Cipher Suite", "type": "string", "operators": ["eq", "neq", "contains", "not_contains"]},
                    {"field": "metadata.chain_length", "label": "Certificate chain length", "type": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"]}
                ],
                "defaults": [
                    {"field": "metadata.days_until_expiry", "operator": "lt", "value": 14, "status": "degraded"}
                ]
            },
            "interval": {"default": 21600, "min": 3600, "max": 604800}
        },
        "manual": {
            "key": "manual",
            "label": "Manual",
            "description": "Manually managed service (not monitored by workers)",
            "target_required": false,
            "fields": [],
            "conditions": {"fields": [], "defaults": []}
        }
    }
}
CATALOG;
    }
}
