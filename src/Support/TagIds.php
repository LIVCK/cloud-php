<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Tag ids for the fields that name one existing tag by id (a synced group's `sync_tag_id`), from
 * Tag DTOs or plain ids.
 *
 * Such a field takes an id only, never a name. A string that is not shaped like an id (21
 * URL-safe characters) is refused here, so `customer:4711` fails at once instead of as a 422
 * later; resolve names with `tags()->findByLabel()` or `tags()->ensure()` first. A service's
 * `tags` list takes names as well and goes through {@see TagEntries}.
 */
final class TagIds
{
    private const string ID_PATTERN = '/\A[A-Za-z0-9_-]{21}\z/';

    /**
     * @return list<string>
     */
    public static function of(Tag|string ...$tags): array
    {
        $ids = [];

        foreach ($tags as $tag) {
            $id = $tag instanceof Tag ? $tag->id : $tag;

            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is not a tag id. Tags are referenced by id (21 URL-safe characters), not by label; resolve a label with tags()->findByLabel() or tags()->ensure() first.',
                    $id,
                ));
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
