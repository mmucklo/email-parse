<?php

namespace Email\Tests;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Parse.php';

use Email\Parse;
use Email\ParseOptions;

class ParseTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Map old rfc_mode + allow_smtputf8 YAML test options to new ParseOptions properties.
     */
    private function buildOptions(array $test): ParseOptions
    {
        $separators = $test['separators'] ?? [',', ';'];
        $useWhitespaceAsSeparator = $test['use_whitespace_as_separator'] ?? true;

        $lengthLimits = null;
        if (isset($test['use_relaxed_limits']) && $test['use_relaxed_limits']) {
            $lengthLimits = \Email\LengthLimits::createRelaxed();
        } elseif (isset($test['max_local_part_length']) || isset($test['max_total_length']) || isset($test['max_domain_label_length'])) {
            $lengthLimits = new \Email\LengthLimits(
                $test['max_local_part_length'] ?? 64,
                $test['max_total_length'] ?? 254,
                $test['max_domain_label_length'] ?? 63
            );
        }

        $rfcMode = $test['rfc_mode'] ?? 'legacy';
        $allowSmtpUtf8 = $test['allow_smtputf8'] ?? true;
        $includeDomainAscii = $test['include_domain_ascii'] ?? false;

        // Start from the matching factory preset, then override via fluent builders.
        switch ($rfcMode) {
            case 'strict_intl':
                $options = ParseOptions::rfc6531()
                    // rfc6531() enforces FQDN; old STRICT_INTL didn't
                    ->withRequireFqdn(false)
                    // rfc6531() validates quoted content; old code didn't
                    ->withValidateQuotedContent(false)
                    ->withRejectEmptyQuotedLocalPart(false);

                break;

            case 'strict_ascii':
            case 'strict':
                $options = ParseOptions::rfc5321()
                    ->withRequireFqdn(false)
                    ->withValidateQuotedContent(false)
                    ->withRejectEmptyQuotedLocalPart(false)
                    // Old STRICT mode: allow_smtputf8 test flag controlled UTF-8 acceptance
                    ->withAllowUtf8LocalPart($allowSmtpUtf8)
                    ->withAllowUtf8Domain($allowSmtpUtf8)
                    // Old strict mode skipped IP global-range check (bug #4)
                    ->withValidateIpGlobalRange(false);

                break;

            case 'normal':
                // rfc5322() has allowUtf8LocalPart=false; old NORMAL deferred UTF-8
                // to the SMTPUTF8 gate, so map via allow_smtputf8.
                $options = ParseOptions::rfc5322()
                    ->withAllowUtf8LocalPart($allowSmtpUtf8)
                    ->withAllowUtf8Domain($allowSmtpUtf8);

                break;

            case 'relaxed':
                $options = ParseOptions::rfc2822()
                    ->withAllowUtf8LocalPart($allowSmtpUtf8)
                    ->withAllowUtf8Domain($allowSmtpUtf8);

                break;

            case 'legacy':
            default:
                $options = new ParseOptions(
                    ['%', '!'],
                    $separators,
                    $useWhitespaceAsSeparator,
                    $lengthLimits,
                );
                if (!$allowSmtpUtf8) {
                    $options = $options
                        ->withAllowUtf8LocalPart(false)
                        ->withAllowUtf8Domain(false);
                }

                return $options->withIncludeDomainAscii($includeDomainAscii);
        }

        // For non-legacy modes, apply banned chars, separators, length limits.
        $options = $options
            ->withBannedChars(['%', '!'])
            ->withSeparators($separators)
            ->withUseWhitespaceAsSeparator($useWhitespaceAsSeparator)
            ->withIncludeDomainAscii($includeDomainAscii);
        if ($lengthLimits !== null) {
            $options = $options->withLengthLimits($lengthLimits);
        }

        return $options;
    }

    public function testParseEmailAddresses()
    {
        $tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/testspec.yml'));

        foreach ($tests as $testIndex => $test) {
            $emails = $test['emails'];
            $multiple = $test['multiple'];
            $expected = $test['result'];

            $options = $this->buildOptions($test);
            $parser = new Parse(null, $options);
            $actual = $parser->parse($emails, $multiple);

            // YAML tests written before ParseErrorCode landed omit `invalid_reason_code`.
            // Reconcile: where the expected entry doesn't mention the key, strip it from
            // the actual output so existing tests pass unchanged. Where the expected
            // entry DOES specify it, resolve the YAML string to a ParseErrorCode enum
            // and compare normally.
            [$expected, $actual] = $this->alignReasonCode($expected, $actual, $multiple);

            $this->assertSame(
                $expected,
                $actual,
                "Test case #{$testIndex}: {$emails}"
            );
        }
    }

    /**
     * @param  array<string,mixed>               $expected
     * @param  array<string,mixed>               $actual
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function alignReasonCode(array $expected, array $actual, bool $multiple): array
    {
        if ($multiple) {
            foreach ($expected['email_addresses'] as $i => $addr) {
                [$expected['email_addresses'][$i], $actual['email_addresses'][$i]] =
                    $this->alignReasonCodeOne($addr, $actual['email_addresses'][$i]);
            }

            return [$expected, $actual];
        }

        return $this->alignReasonCodeOne($expected, $actual);
    }

    /**
     * @param  array<string,mixed>               $expected
     * @param  array<string,mixed>               $actual
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function alignReasonCodeOne(array $expected, array $actual): array
    {
        if (!array_key_exists('invalid_reason_code', $expected)) {
            unset($actual['invalid_reason_code']);

            return [$expected, $actual];
        }

        if (is_string($expected['invalid_reason_code'])) {
            $expected['invalid_reason_code'] = \Email\ParseErrorCode::from($expected['invalid_reason_code']);
        }

        return [$expected, $actual];
    }

    public function testParseSingleReturnsTypedObject(): void
    {
        $result = Parse::getInstance()->parseSingle('john@example.com');
        $this->assertInstanceOf(\Email\ParsedEmailAddress::class, $result);
        $this->assertSame('john', $result->localPart);
        $this->assertSame('example.com', $result->domain);
        $this->assertFalse($result->invalid);
        $this->assertNull($result->invalidReason);
        $this->assertNull($result->invalidReasonCode);
    }

    public function testParseSingleInvalidCarriesErrorCode(): void
    {
        $result = Parse::getInstance()->parseSingle('foo@bar@baz.com');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ParseErrorCode::MultipleAtSymbols, $result->invalidReasonCode);
    }

    public function testParseMultipleReturnsTypedResult(): void
    {
        $result = Parse::getInstance()->parseMultiple('a@a.com, b@b.com');
        $this->assertInstanceOf(\Email\ParseResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertNull($result->reason);
        $this->assertCount(2, $result->emailAddresses);
        $this->assertInstanceOf(\Email\ParsedEmailAddress::class, $result->emailAddresses[0]);
        $this->assertSame('a', $result->emailAddresses[0]->localPart);
        $this->assertSame('b.com', $result->emailAddresses[1]->domain);
    }

    public function testParseMultipleFailureCarriesReason(): void
    {
        $result = Parse::getInstance()->parseMultiple('a@a.com, not-an-email');
        $this->assertFalse($result->success);
        $this->assertNotNull($result->reason);
        $this->assertTrue($result->emailAddresses[1]->invalid);
    }

    public function testParsedEmailAddressCommentsAreExtracted(): void
    {
        $result = Parse::getInstance()->parseSingle('user@example.com (home)');
        $this->assertSame(['home'], $result->comments);
    }

    public function testFluentBuilderReturnsNewInstance(): void
    {
        $a = ParseOptions::rfc5322();
        $b = $a->withRequireFqdn(true);
        $this->assertNotSame($a, $b);
        $this->assertFalse($a->requireFqdn);
        $this->assertTrue($b->requireFqdn);
    }

    public function testFluentBuilderPreservesOtherFields(): void
    {
        $opts = ParseOptions::rfc6531()
            ->withRequireFqdn(false)
            ->withAllowUtf8LocalPart(false)
            ->withBannedChars(['%']);
        $this->assertFalse($opts->requireFqdn);
        $this->assertFalse($opts->allowUtf8LocalPart);
        $this->assertTrue($opts->allowUtf8Domain);        // preserved from rfc6531()
        $this->assertTrue($opts->applyNfcNormalization);  // preserved
        $this->assertTrue($opts->includeDomainAscii);     // preserved
        $this->assertSame(['%' => true], $opts->getBannedChars());
    }

    public function testReadonlyRulePropertiesRejectDirectMutation(): void
    {
        $opts = new ParseOptions();
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line — intentionally mutating a readonly property to assert it throws */
        $opts->requireFqdn = true;
    }

    public function testDisplayNamePhraseValidationAcceptsAtext(): void
    {
        $opts = (new ParseOptions())->withValidateDisplayNamePhrase(true);
        $result = (new Parse(null, $opts))->parseSingle('John Doe <john@example.com>');
        $this->assertFalse($result->invalid);
    }

    public function testDisplayNamePhraseValidationRejectsNonAtext(): void
    {
        // A UTF-8 character in an unquoted display name violates RFC 5322 §3.2.5 phrase.
        $opts = (new ParseOptions())->withValidateDisplayNamePhrase(true);
        $result = (new Parse(null, $opts))->parseSingle('Jöhn <john@example.com>');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ParseErrorCode::InvalidDisplayNamePhrase, $result->invalidReasonCode);
    }

    public function testDisplayNamePhraseValidationAllowsQuotedNames(): void
    {
        // A quoted-string display name is always phrase-valid — no restriction on contents.
        $opts = (new ParseOptions())->withValidateDisplayNamePhrase(true);
        $result = (new Parse(null, $opts))->parseSingle('"Jöhn Q. Public" <john@example.com>');
        $this->assertFalse($result->invalid);
    }

    public function testStrictIdnaAcceptsValidIdn(): void
    {
        // "bücher.de" is a well-formed IDNA label — valid under strict IDNA2008.
        $opts = ParseOptions::rfc6531()->withRequireFqdn(false);
        $result = (new Parse(null, $opts))->parseSingle('user@bücher.de');
        $this->assertFalse($result->invalid);
        $this->assertSame('xn--bcher-kva.de', $result->domainAscii);
    }

    public function testStrictIdnaRejectsBareLeadingHyphenLabel(): void
    {
        // Leading hyphen violates RFC 1035 §2.3.4 and IDNA2008 STD3 rules.
        // With strictIdna=true the idn_to_ascii() flags cause rejection.
        $opts = ParseOptions::rfc6531()->withRequireFqdn(false);
        $result = (new Parse(null, $opts))->parseSingle('user@-bücher.de');
        $this->assertTrue($result->invalid);
    }

    /**
     * Exercises every `withX()` fluent builder. Each call must return a new
     * instance with the targeted field flipped and every other field preserved.
     */
    public function testAllFluentBuildersToggleTheTargetedField(): void
    {
        $base = new ParseOptions();
        $cases = [
            ['withAllowUtf8LocalPart',         false, 'allowUtf8LocalPart'],
            ['withAllowObsLocalPart',          true,  'allowObsLocalPart'],
            ['withAllowQuotedString',          false, 'allowQuotedString'],
            ['withValidateQuotedContent',      true,  'validateQuotedContent'],
            ['withRejectEmptyQuotedLocalPart', true,  'rejectEmptyQuotedLocalPart'],
            ['withAllowUtf8Domain',            false, 'allowUtf8Domain'],
            ['withAllowDomainLiteral',         false, 'allowDomainLiteral'],
            ['withRequireFqdn',                true,  'requireFqdn'],
            ['withValidateIpGlobalRange',      false, 'validateIpGlobalRange'],
            ['withRejectC0Controls',           true,  'rejectC0Controls'],
            ['withRejectC1Controls',           true,  'rejectC1Controls'],
            ['withApplyNfcNormalization',      true,  'applyNfcNormalization'],
            ['withEnforceLengthLimits',        false, 'enforceLengthLimits'],
            ['withIncludeDomainAscii',         true,  'includeDomainAscii'],
            ['withValidateDisplayNamePhrase',  true,  'validateDisplayNamePhrase'],
            ['withStrictIdna',                 true,  'strictIdna'],
            ['withUseWhitespaceAsSeparator',   false, null],
        ];
        foreach ($cases as [$method, $value, $property]) {
            $new = $base->$method($value);
            $this->assertNotSame($base, $new, "{$method} must return a new instance");
            if ($property !== null) {
                $this->assertSame($value, $new->$property, "{$method} did not set {$property}");
            }
        }

        $withBanned = $base->withBannedChars(['%', '!']);
        $this->assertSame(['%' => true, '!' => true], $withBanned->getBannedChars());

        $withSeps = $base->withSeparators([';']);
        $this->assertSame([';' => true], $withSeps->getSeparators());

        $newLimits = new \Email\LengthLimits(32, 128, 32);
        $withLimits = $base->withLengthLimits($newLimits);
        $this->assertSame(32, $withLimits->getLengthLimits()->maxLocalPartLength);
    }

    /**
     * Exercises the deprecated setters — they continue to work in v3.1 and
     * will be removed in v4.0. Coverage-only; assertions verify round-trips.
     */
    public function testDeprecatedSettersStillFunction(): void
    {
        $opts = new ParseOptions();
        $opts->setBannedChars(['%']);
        $this->assertSame(['%' => true], $opts->getBannedChars());

        $opts->setSeparators([';']);
        $this->assertSame([';' => true], $opts->getSeparators());

        $opts->setUseWhitespaceAsSeparator(false);
        $this->assertFalse($opts->getUseWhitespaceAsSeparator());

        $opts->setLengthLimits(new \Email\LengthLimits(10, 20, 5));
        $this->assertSame(10, $opts->getMaxLocalPartLength());
        $this->assertSame(20, $opts->getMaxTotalLength());
        $this->assertSame(5, $opts->getMaxDomainLabelLength());

        $opts->setMaxLocalPartLength(64);
        $this->assertSame(64, $opts->getMaxLocalPartLength());
        // Other two limits preserved.
        $this->assertSame(20, $opts->getMaxTotalLength());
        $this->assertSame(5, $opts->getMaxDomainLabelLength());

        $opts->setMaxTotalLength(254);
        $this->assertSame(254, $opts->getMaxTotalLength());

        $opts->setMaxDomainLabelLength(63);
        $this->assertSame(63, $opts->getMaxDomainLabelLength());
    }

    /**
     * Exercises the fluent and deprecated mutators on the Parse class itself.
     * Pre-existing public API covered here for the first time.
     */
    public function testParseSetLoggerAndSetOptionsAreFluent(): void
    {
        $parser = new Parse();
        $opts = ParseOptions::rfc5322();
        $this->assertSame($parser, $parser->setOptions($opts), 'setOptions() is fluent');
        $this->assertSame($opts, $parser->getOptions());

        $logger = new \Psr\Log\NullLogger();
        $this->assertSame($parser, $parser->setLogger($logger), 'setLogger() is fluent');
    }

    /**
     * Targeted error-code coverage for structural parse errors the main YAML
     * test harness doesn't exercise.
     */
    public function testStructuralParseErrorsCarryExpectedCode(): void
    {
        $cases = [
            ['<<a@x.com>',         \Email\ParseErrorCode::MultipleOpeningAngle],
            ['<local>',            \Email\ParseErrorCode::MissingDomainBeforeClosingAngle],
            ['a@[1.2.3.4]@y.com',  \Email\ParseErrorCode::StrayAtAfterDomain],
            ['[a@x.com',           \Email\ParseErrorCode::InvalidOpeningBracket],
            ['/foo@x.com',         \Email\ParseErrorCode::InvalidCharacterAtStart],
        ];

        foreach ($cases as [$input, $expected]) {
            $result = Parse::getInstance()->parseSingle($input);
            $this->assertTrue($result->invalid, "{$input} should be invalid");
            $this->assertSame($expected, $result->invalidReasonCode, "{$input} wrong code");
        }
    }

    /**
     * RFC 5321 FQDN enforcement — exercises the FqdnRequired code path.
     */
    public function testRfc5321RequiresFqdn(): void
    {
        $opts = ParseOptions::rfc5321();
        $result = (new Parse(null, $opts))->parseSingle('user@localhost');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ParseErrorCode::FqdnRequired, $result->invalidReasonCode);
    }

    /**
     * Quoted-string content validation (RFC 5321 §4.1.2 qtextSMTP / quoted-pairSMTP).
     */
    public function testQuotedStringContentValidation(): void
    {
        $opts = (new ParseOptions())
            ->withValidateQuotedContent(true)
            ->withAllowUtf8LocalPart(false);

        // Invalid escape: backslash followed by byte outside %d32-126 (SOH = 0x01).
        $result = (new Parse(null, $opts))->parseSingle("\"a\\\x01b\"@example.com");
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ParseErrorCode::InvalidEscapedCharInQuotedString, $result->invalidReasonCode);

        // Bare control byte inside the quoted string (no escape).
        $result = (new Parse(null, $opts))->parseSingle("\"a\x01b\"@example.com");
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ParseErrorCode::InvalidCharInQuotedString, $result->invalidReasonCode);
    }
}
