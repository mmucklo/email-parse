<?php

namespace Email;

/**
 * Immutable value object representing the outcome of parsing one or more addresses.
 *
 * Produced by {@see Parse::parseMultiple()}. For single-address parsing,
 * {@see Parse::parseSingle()} returns a {@see ParsedEmailAddress} directly.
 */
final class ParseResult
{
    /**
     * @param bool                      $success        `true` when every address parsed successfully.
     * @param ?string                   $reason         Summary failure message when `$success` is `false`; else `null`.
     * @param array<int, ParsedEmailAddress> $emailAddresses Parsed addresses in input order.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $reason,
        public readonly array $emailAddresses,
    ) {
    }

    /**
     * Build from the array shape produced by {@see Parse::parse()} in multi-address mode.
     *
     * @param array{success: bool, reason: ?string, email_addresses: array<int, array<string, mixed>>} $arr
     */
    public static function fromArray(array $arr): self
    {
        return new self(
            success: $arr['success'],
            reason:  $arr['reason'],
            emailAddresses: array_map(
                fn (array $a) => ParsedEmailAddress::fromArray($a),
                $arr['email_addresses'],
            ),
        );
    }
}
