<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Payloads;

use LIVCK\Cloud\Exceptions\InvalidArgumentException;
use LIVCK\Cloud\Support\Translatable;

/**
 * A new status page for `POST /v1/statuspages`: a name and, optionally, the slug.
 *
 *     CreateStatuspage::make('Acme Hosting')->withSlug('acme-hosting')
 *
 * Everything else starts at the server's defaults (public access, the organization's
 * languages, the logo shown, incident history on) and is changed with
 * {@see UpdateStatuspage} afterwards. Whether the page goes online right away is not up to
 * the request: it is published when the plan has a free published slot and created
 * unpublished otherwise.
 */
final readonly class CreateStatuspage
{
    public Translatable $name;

    /**
     * @param Translatable|string $name a plain string is stored in the organization's default language
     * @param string|null $slug the subdomain on statuspage.de; derived from the name when null
     */
    public function __construct(
        Translatable|string $name,
        public ?string $slug = null,
    ) {
        $this->name = Translatable::from($name);

        if ($slug !== null && trim($slug) === '') {
            throw new InvalidArgumentException('A slug must not be blank; leave it out to derive one from the name.');
        }
    }

    public static function make(Translatable|string $name): self
    {
        return new self($name);
    }

    /**
     * The subdomain on statuspage.de: lower-case letters, digits and single inner hyphens, at
     * most 100 characters, unique across all organizations. Slugs freed by other
     * organizations stay reserved for a while. Without one the server derives a free slug
     * from the name, appending `-1`, `-2`, … where needed.
     */
    public function withSlug(string $slug): self
    {
        return new self($this->name, $slug);
    }

    /**
     * @return array{name: string|array<string, string>, slug?: string}
     */
    public function toArray(): array
    {
        $attributes = ['name' => $this->name->jsonSerialize()];

        if ($this->slug !== null) {
            $attributes['slug'] = $this->slug;
        }

        return $attributes;
    }
}
