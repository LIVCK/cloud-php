<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * The entries of a service's `tags` list: Tag DTOs (sent as their id), tag ids and tag names
 * (`critical`, `customer:4711`, `env=prod`), in any mix.
 *
 * The server resolves every entry inside the token's organization: the id of an existing tag is
 * that tag, anything else is a name, found or created. The one exception is an entry that looks
 * like a tag id (21 letters, digits, `_` or `-`, with at least one uppercase letter): it must be
 * an existing tag's id or name and is never created, so a stale id is a 422 on its entry instead
 * of a new tag. Tag keys are always lowercase, so names are never affected. Names go out
 * as given, trimmed; their grammar (key charset, value length, reserved keys) is the server's to
 * enforce. A blank entry is refused here: it is always a variable that came out empty.
 *
 * Fields that take exactly one existing tag by id (`sync_tag_id`) go through {@see TagIds}.
 */
final class TagEntries
{
    /**
     * @return list<string> each entry once, in the order given
     */
    public static function of(Tag|string ...$tags): array
    {
        $entries = [];

        foreach ($tags as $tag) {
            $entry = $tag instanceof Tag ? $tag->id : trim($tag);

            if ($entry === '') {
                throw new InvalidArgumentException('A tag must not be blank: pass a Tag, a tag id or a tag name such as "customer:4711".');
            }

            $entries[] = $entry;
        }

        return array_values(array_unique($entries));
    }
}
