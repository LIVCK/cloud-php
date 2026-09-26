<?php

declare(strict_types=1);

namespace LIVCK\Cloud\Support;

use LIVCK\Cloud\Data\Tag;
use LIVCK\Cloud\Exceptions\InvalidArgumentException;

/**
 * Tag ids for a service's `tags` list, from Tag DTOs or plain ids.
 *
 * The services API takes tag IDS only, never labels: a label that does not exist would
 * otherwise silently create a junk tag. A string that is not shaped like an id (21 URL-safe
 * characters) is refused here, so `customer:4711` fails at once instead of as a 422 later;
 * resolve labels with `tags()->findByLabel()` or `tags()->ensure()` first.
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
