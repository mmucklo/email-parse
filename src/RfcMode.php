<?php

namespace Email;

/**
 * RFC compliance modes for email parsing.
 */
final class RfcMode
{
    /**
     * RFC 6531/6532 strict: Full internationalization with UTF-8, Unicode normalization (NFC).
     */
    public const STRICT_INTL = 'strict_intl';

    /**
     * RFC 5322 strict ASCII: no obsolete syntax, strict validation, ASCII only.
     */
    public const STRICT_ASCII = 'strict_ascii';

    /**
     * Alias for STRICT_ASCII (backward compatibility).
     */
    public const STRICT = 'strict_ascii';

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
            self::STRICT_INTL,
            self::STRICT_ASCII,
            self::NORMAL,
            self::RELAXED,
            self::LEGACY,
        ];
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::all(), true) || $mode === 'strict';
    }

    /**
     * Normalize mode name for backward compatibility.
     * 'strict' is treated as an alias for 'strict_ascii'.
     */
    public static function normalize(string $mode): string
    {
        return $mode === 'strict' ? self::STRICT_ASCII : $mode;
    }
}
