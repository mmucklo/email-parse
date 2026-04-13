<?php

namespace Email;

/**
 * Immutable value object representing a single parsed email address.
 *
 * Produced by {@see Parse::parseSingle()} and {@see Parse::parseMultiple()}.
 * Every field is also present in the legacy array output of {@see Parse::parse()};
 * callers preferring typed access with IDE autocomplete should use the new methods.
 */
final class ParsedEmailAddress
{
    /**
     * @param string              $address           Canonical address, comments stripped (e.g. `"J Doe" <j@x.com>`).
     * @param string              $originalAddress   Raw address as given, comments included.
     * @param string              $simpleAddress     local-part@domain-part (no display name).
     * @param string              $name              Display name including surrounding quotes if quoted.
     * @param string              $nameParsed        Display name without quotes.
     * @param string              $localPart         Local-part including quotes if quoted.
     * @param string              $localPartParsed   Local-part without quotes.
     * @param string              $domain            Domain after `@` (may be Unicode / U-label). Empty when an IP literal is used.
     * @param ?string             $domainAscii       Punycode (A-label) domain when `ParseOptions::$includeDomainAscii` is `true`; else `null`.
     * @param string              $ip                IP address if a domain-literal `[IP]` was used; else empty string.
     * @param string              $domainPart        Domain or `[IP]` as it appears after the `@`.
     * @param bool                $invalid           `true` if the address failed validation.
     * @param ?string             $invalidReason     Human-readable failure reason; `null` if valid.
     * @param ?ParseErrorCode     $invalidReasonCode Structured failure code; `null` if valid.
     * @param array<int, string>  $comments          RFC 5322 comments extracted from the address.
     * @param ?string             $obsRoute          RFC 5322 §4.4 obs-route prefix if one was stripped from inside angle-addr (e.g. `@host1,@host2`); `null` otherwise. Only populated when {@see ParseOptions::$allowObsRoute} is enabled.
     */
    public function __construct(
        public readonly string $address,
        public readonly string $originalAddress,
        public readonly string $simpleAddress,
        public readonly string $name,
        public readonly string $nameParsed,
        public readonly string $localPart,
        public readonly string $localPartParsed,
        public readonly string $domain,
        public readonly ?string $domainAscii,
        public readonly string $ip,
        public readonly string $domainPart,
        public readonly bool $invalid,
        public readonly ?string $invalidReason,
        public readonly ?ParseErrorCode $invalidReasonCode,
        public readonly array $comments,
        public readonly ?string $obsRoute = null,
    ) {
    }

    /**
     * Build from the array shape produced by {@see Parse::parse()}.
     *
     * @param array<string,mixed> $arr
     */
    public static function fromArray(array $arr): self
    {
        return new self(
            address:           $arr['address'],
            originalAddress:   $arr['original_address'],
            simpleAddress:     $arr['simple_address'],
            name:              $arr['name'],
            nameParsed:        $arr['name_parsed'],
            localPart:         $arr['local_part'],
            localPartParsed:   $arr['local_part_parsed'],
            domain:            $arr['domain'],
            domainAscii:       $arr['domain_ascii'],
            ip:                $arr['ip'],
            domainPart:        $arr['domain_part'],
            invalid:           $arr['invalid'],
            invalidReason:     $arr['invalid_reason'],
            invalidReasonCode: $arr['invalid_reason_code'],
            comments:          $arr['comments'],
            obsRoute:          $arr['obs_route'] ?? null,
        );
    }

    /**
     * Severity of the validation failure, derived from {@see $invalidReasonCode}.
     * Returns `null` when the address is valid (no failure to classify).
     *
     * Callers can use this to distinguish structural failures from policy
     * violations:
     *
     *   if ($parsed->invalid && $parsed->invalidSeverity() === ValidationSeverity::Warning) {
     *       // Well-formed but violates a configured rule — e.g. private-range IP
     *       // literal, non-FQDN domain, octet length over RFC 5321 §4.5.3.1.
     *       // Safe to accept in non-SMTP contexts.
     *   }
     */
    public function invalidSeverity(): ?ValidationSeverity
    {
        return $this->invalidReasonCode?->severity();
    }
}
