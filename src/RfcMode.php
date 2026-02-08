<?php

namespace Email;

/**
 * RFC compliance modes for email parsing.
 */
final class RfcMode
{
    /**
     * RFC 5322 strict: no obsolete syntax, strict validation.
     */
    public const STRICT = 'strict';

    /**
     * RFC 5322 + obsolete syntax (recommended default).
     */
    public const NORMAL = 'normal';

    /**
     * RFC 2822 relaxed compatibility.
     */
    public const RELAXED = 'relaxed';

    /**
     * Legacy mode: current parser behavior.
     */
    public const LEGACY = 'legacy';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::STRICT,
            self::NORMAL,
            self::RELAXED,
            self::LEGACY,
        ];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }
}
