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
        } elseif (is_string($expected['invalid_reason_code'])) {
            $expected['invalid_reason_code'] = \Email\ParseErrorCode::from($expected['invalid_reason_code']);
        }

        // obs_route — same opt-in pattern: existing YAML entries omit it; new
        // tests can assert it by adding the key. When expected doesn't specify,
        // strip from actual so pre-obs-route YAML tests pass unchanged.
        if (!array_key_exists('obs_route', $expected)) {
            unset($actual['obs_route']);
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

    public function testValidAddressHasNullInvalidSeverity(): void
    {
        $result = Parse::getInstance()->parseSingle('user@example.com');
        $this->assertFalse($result->invalid);
        $this->assertNull($result->invalidSeverity());
    }

    public function testStructuralFailureIsCriticalSeverity(): void
    {
        // Missing '@' — structural failure, unparseable.
        $result = Parse::getInstance()->parseSingle('not-an-email');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ValidationSeverity::Critical, $result->invalidSeverity());
    }

    public function testPolicyFailureIsWarningSeverity(): void
    {
        // FQDN requirement: single-label domain is syntactically fine but policy-rejected.
        $opts = ParseOptions::rfc5321();
        $result = (new Parse(null, $opts))->parseSingle('user@localhost');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ValidationSeverity::Warning, $result->invalidSeverity());

        // Private-range IP literal is syntactically valid but rejected by the global-range rule.
        $result = Parse::getInstance()->parseSingle('user@[192.168.0.1]');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ValidationSeverity::Warning, $result->invalidSeverity());
    }

    public function testEveryErrorCodeHasASeverity(): void
    {
        // Defensive: ensure no new ParseErrorCode is added without mapping its severity.
        foreach (\Email\ParseErrorCode::cases() as $code) {
            $severity = $code->severity();
            $this->assertInstanceOf(\Email\ValidationSeverity::class, $severity);
        }
    }

    public function testParseStreamYieldsTypedObjects(): void
    {
        $parser = Parse::getInstance();
        $gen = $parser->parseStream(['a@a.com', 'b@b.com']);
        $this->assertInstanceOf(\Generator::class, $gen);
        $results = iterator_to_array($gen, false);
        $this->assertCount(2, $results);
        $this->assertInstanceOf(\Email\ParsedEmailAddress::class, $results[0]);
        $this->assertSame('a', $results[0]->localPart);
        $this->assertSame('b.com', $results[1]->domain);
    }

    public function testParseStreamSplitsMultiAddressItems(): void
    {
        // Each input item may itself contain several comma-separated addresses;
        // parseStream yields one ParsedEmailAddress per address regardless.
        $parser = Parse::getInstance();
        $results = iterator_to_array(
            $parser->parseStream(['a@a.com, b@b.com', 'c@c.com']),
            false,
        );
        $this->assertCount(3, $results);
        $this->assertSame(['a', 'b', 'c'], array_map(fn ($r) => $r->localPart, $results));
    }

    public function testParseStreamAcceptsGeneratorInput(): void
    {
        // A caller-supplied generator should be consumed lazily.
        $input = (function () {
            yield 'one@example.com';
            yield 'two@example.com';
        })();

        $results = iterator_to_array(Parse::getInstance()->parseStream($input), false);
        $this->assertCount(2, $results);
        $this->assertSame('one', $results[0]->localPart);
        $this->assertSame('two', $results[1]->localPart);
    }

    public function testParseStreamEmitsInvalidEntries(): void
    {
        // Invalid addresses still appear in the stream — callers filter by $addr->invalid.
        $results = iterator_to_array(
            Parse::getInstance()->parseStream(['valid@ok.com', 'not-an-email']),
            false,
        );
        $this->assertCount(2, $results);
        $this->assertFalse($results[0]->invalid);
        $this->assertTrue($results[1]->invalid);
    }

    public function testObsRouteIsAcceptedAndCapturedInRfc5322(): void
    {
        // RFC 5322 §4.4: obs-route prefix is recognized, captured, and discarded;
        // the real addr-spec (after the colon) becomes the parsed address.
        $result = (new Parse(null, ParseOptions::rfc5322()))
            ->parseSingle('<@hostA:user@hostB>');
        $this->assertFalse($result->invalid);
        $this->assertSame('user', $result->localPart);
        $this->assertSame('hostB', $result->domain);
        $this->assertSame('@hostA', $result->obsRoute);
    }

    public function testObsRouteSupportsMultipleHosts(): void
    {
        // Multiple routed hosts joined by comma per obs-domain-list.
        $result = (new Parse(null, ParseOptions::rfc5322()))
            ->parseSingle('<@hostA,@hostB:user@hostC>');
        $this->assertFalse($result->invalid);
        $this->assertSame('user', $result->localPart);
        $this->assertSame('hostC', $result->domain);
        $this->assertSame('@hostA,@hostB', $result->obsRoute);
    }

    public function testObsRoutePreservesDisplayName(): void
    {
        $result = (new Parse(null, ParseOptions::rfc5322()))
            ->parseSingle('John Doe <@route.com:jdoe@example.com>');
        $this->assertFalse($result->invalid);
        $this->assertSame('John Doe', $result->nameParsed);
        $this->assertSame('jdoe', $result->localPart);
        $this->assertSame('example.com', $result->domain);
        $this->assertSame('@route.com', $result->obsRoute);
    }

    public function testObsRouteInMultiAddressBatch(): void
    {
        // Each address in a batch parses its own obs-route independently.
        $result = (new Parse(null, ParseOptions::rfc5322()))
            ->parseMultiple('<@routeA:a@x.com>, <@routeB:b@y.com>');
        $this->assertTrue($result->success);
        $this->assertCount(2, $result->emailAddresses);
        $this->assertSame('@routeA', $result->emailAddresses[0]->obsRoute);
        $this->assertSame('@routeB', $result->emailAddresses[1]->obsRoute);
    }

    public function testObsRouteRejectedWhenFlagIsOff(): void
    {
        // Default constructor (legacy mode) has allowObsRoute=false — the colon
        // inside <...> is rejected as an invalid domain character.
        $result = (new Parse(null, new ParseOptions()))
            ->parseSingle('<@hostA:user@hostB>');
        $this->assertTrue($result->invalid);
        $this->assertNull($result->obsRoute);

        // rfc5321() also keeps obs-route off per SMTP Mailbox strictness.
        $result = (new Parse(null, ParseOptions::rfc5321()))
            ->parseSingle('<@hostA:user@hostB>');
        $this->assertTrue($result->invalid);
    }

    public function testObsRouteIncompleteWithoutColonIsInvalid(): void
    {
        // `<@host>` has no colon — incomplete obs-route.
        $result = (new Parse(null, ParseOptions::rfc5322()))
            ->parseSingle('<@host>');
        $this->assertTrue($result->invalid);
        $this->assertSame(\Email\ParseErrorCode::IncompleteAddress, $result->invalidReasonCode);
    }

    public function testObsRouteWithEmptyAddrSpecIsInvalid(): void
    {
        // `<@hostA:>` — empty addr-spec after the colon.
        $result = (new Parse(null, ParseOptions::rfc5322()))
            ->parseSingle('<@hostA:>');
        $this->assertTrue($result->invalid);
    }

    public function testValidAddressHasNullObsRoute(): void
    {
        // A normal address produces obsRoute=null (not empty string).
        $result = Parse::getInstance()->parseSingle('user@example.com');
        $this->assertNull($result->obsRoute);
    }

    /**
     * RFC 5322 §3.2.2 CFWS — folding whitespace is allowed around dot-atom
     * boundaries. Each case below is a structurally valid RFC 5322 addr-spec
     * that the v3.1 parser rejected as "Email address contains whitespace";
     * v3.2 absorbs the CFWS positionally via look-ahead in the WSP handler.
     */
    public function testCfwsTrailingLocalPart(): void
    {
        // "local @domain" — trailing CFWS on local-part dot-atom.
        $result = Parse::getInstance()->parseSingle('local @domain.com');
        $this->assertFalse($result->invalid);
        $this->assertSame('local', $result->localPart);
        $this->assertSame('domain.com', $result->domain);
    }

    public function testCfwsLeadingDomain(): void
    {
        // "local@ domain" — leading CFWS on domain dot-atom.
        $result = Parse::getInstance()->parseSingle('local@ domain.com');
        $this->assertFalse($result->invalid);
        $this->assertSame('local', $result->localPart);
        $this->assertSame('domain.com', $result->domain);
    }

    public function testCfwsAroundAtSymbol(): void
    {
        $result = Parse::getInstance()->parseSingle('local @ domain.com');
        $this->assertFalse($result->invalid);
        $this->assertSame('local', $result->localPart);
        $this->assertSame('domain.com', $result->domain);
    }

    public function testCfwsInsideAngleAddr(): void
    {
        // Whitespace inside <> flanking the addr-spec.
        $result = Parse::getInstance()->parseSingle('John Doe <  local@domain.com  >');
        $this->assertFalse($result->invalid);
        $this->assertSame('John Doe', $result->nameParsed);
        $this->assertSame('local', $result->localPart);
        $this->assertSame('domain.com', $result->domain);
    }

    public function testCfwsAroundAtInsideAngleAddr(): void
    {
        $result = Parse::getInstance()->parseSingle('<local @ domain.com>');
        $this->assertFalse($result->invalid);
        $this->assertSame('local', $result->localPart);
        $this->assertSame('domain.com', $result->domain);
    }

    public function testCfwsFoldingWhitespace(): void
    {
        // Folded whitespace (LF + WSP) is still whitespace per CFWS lookahead.
        $result = Parse::getInstance()->parseSingle("local\n\t@domain.com");
        $this->assertFalse($result->invalid);
        $this->assertSame('local', $result->localPart);
        $this->assertSame('domain.com', $result->domain);
    }

    public function testToArrayRoundTripsLegacyShape(): void
    {
        // Parse an address both ways; toArray() on the typed object must match
        // the legacy parse() output exactly (same keys, same order, same types).
        $parser = new Parse();
        $legacy = $parser->parse('"J Doe" <john@example.com> (nickname)', false);
        $typed = $parser->parseSingle('"J Doe" <john@example.com> (nickname)');

        $this->assertSame($legacy, $typed->toArray());
    }

    public function testToArrayPreservesErrorCode(): void
    {
        $typed = Parse::getInstance()->parseSingle('not-an-email');
        $arr = $typed->toArray();
        $this->assertTrue($arr['invalid']);
        $this->assertInstanceOf(\Email\ParseErrorCode::class, $arr['invalid_reason_code']);
    }

    public function testToJsonProducesParseableJson(): void
    {
        $typed = Parse::getInstance()->parseSingle('user@example.com');
        $decoded = json_decode($typed->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('user', $decoded['local_part']);
        $this->assertSame('example.com', $decoded['domain']);
    }

    public function testToJsonSerializesErrorCodeAsString(): void
    {
        // ParseErrorCode is a BackedEnum; json_encode emits its backing value.
        $typed = Parse::getInstance()->parseSingle('<<a@b.com>');
        $decoded = json_decode($typed->toJson(), true);
        $this->assertSame('multiple_opening_angle', $decoded['invalid_reason_code']);
    }

    public function testStringableReturnsSimpleAddressWhenValid(): void
    {
        $typed = Parse::getInstance()->parseSingle('"J Doe" <john@example.com>');
        $this->assertSame('john@example.com', (string) $typed);
    }

    public function testStringableReturnsEmptyStringWhenInvalid(): void
    {
        $typed = Parse::getInstance()->parseSingle('not-an-email');
        $this->assertSame('', (string) $typed);
    }

    public function testCanonicalAddrSpecWithoutName(): void
    {
        $typed = Parse::getInstance()->parseSingle('john@example.com');
        $this->assertSame('john@example.com', $typed->canonical());
    }

    public function testCanonicalAddrSpecWithSimpleName(): void
    {
        // Atext-only name needs no quotes.
        $typed = Parse::getInstance()->parseSingle('John Doe <john@example.com>');
        $this->assertSame('John Doe <john@example.com>', $typed->canonical());
    }

    public function testCanonicalStripsUnnecessaryNameQuotes(): void
    {
        // Input had quotes; canonical form drops them because the name is
        // pure atext+WSP and quoting is not required per RFC 5322 §3.2.5.
        $typed = Parse::getInstance()->parseSingle('"John Doe" <john@example.com>');
        $this->assertSame('John Doe <john@example.com>', $typed->canonical());
    }

    public function testCanonicalKeepsRequiredNameQuotes(): void
    {
        // Period in display name requires quoting (it's not atext).
        $typed = Parse::getInstance()->parseSingle('"John Q. Public" <john@example.com>');
        $this->assertSame('"John Q. Public" <john@example.com>', $typed->canonical());
    }

    public function testCanonicalQuotesLocalPartWhenRequired(): void
    {
        // Local-part with a space must be quoted per RFC 5322 §3.2.4.
        $typed = Parse::getInstance()->parseSingle('"with space"@example.com');
        $this->assertSame('"with space"@example.com', $typed->canonical());
    }

    public function testCanonicalReturnsEmptyForInvalidAddress(): void
    {
        $typed = Parse::getInstance()->parseSingle('not-an-email');
        $this->assertSame('', $typed->canonical());
    }

    public function testParseResultToArrayRoundTripsLegacyShape(): void
    {
        $parser = new Parse();
        $legacy = $parser->parse('a@a.com, b@b.com', true);
        $typed = $parser->parseMultiple('a@a.com, b@b.com');

        $this->assertSame($legacy, $typed->toArray());
    }

    public function testParseResultToJsonProducesParseableJson(): void
    {
        $typed = Parse::getInstance()->parseMultiple('a@a.com, b@b.com');
        $decoded = json_decode($typed->toJson(), true);
        $this->assertTrue($decoded['success']);
        $this->assertCount(2, $decoded['email_addresses']);
        $this->assertSame('a', $decoded['email_addresses'][0]['local_part']);
    }

    public function testLocalPartNormalizerRewritesLocalPart(): void
    {
        // Gmail-style: strip dots and +tags from the local-part for gmail.com.
        $gmailNormalizer = function (string $local, string $domain): string {
            if ($domain !== 'gmail.com') {
                return $local;
            }
            $local = str_replace('.', '', $local);
            $plus = strpos($local, '+');

            return $plus === false ? $local : substr($local, 0, $plus);
        };

        $opts = ParseOptions::rfc5322()->withLocalPartNormalizer($gmailNormalizer);
        $result = (new Parse(null, $opts))->parseSingle('john.doe+spam@gmail.com');

        $this->assertFalse($result->invalid);
        $this->assertSame('johndoe', $result->localPartParsed);
        $this->assertSame('johndoe@gmail.com', $result->simpleAddress);
        // original_address retains the verbatim input for audit.
        $this->assertSame('john.doe+spam@gmail.com', $result->originalAddress);
    }

    public function testLocalPartNormalizerSkipsOtherDomains(): void
    {
        // The normalizer is gmail-specific; other domains pass through.
        $normalizer = fn (string $local, string $domain) => $domain === 'gmail.com'
            ? str_replace('.', '', $local)
            : $local;

        $opts = ParseOptions::rfc5322()->withLocalPartNormalizer($normalizer);
        $result = (new Parse(null, $opts))->parseSingle('j.doe@example.com');

        $this->assertSame('j.doe', $result->localPartParsed);
    }

    public function testLocalPartNormalizerNotInvokedOnInvalidAddress(): void
    {
        // Invalid address short-circuits validateLocalPart; the normalizer
        // must not run on unvalidated input.
        $invocations = 0;
        $normalizer = function (string $local, string $domain) use (&$invocations): string {
            ++$invocations;

            return $local;
        };

        $opts = ParseOptions::rfc5322()->withLocalPartNormalizer($normalizer);
        (new Parse(null, $opts))->parseSingle('not-an-email');

        $this->assertSame(0, $invocations);
    }

    public function testLocalPartNormalizerCanBeClearedByPassingNull(): void
    {
        $normalizer = fn (string $l) => strtolower($l);
        $a = ParseOptions::rfc5322()->withLocalPartNormalizer($normalizer);
        $b = $a->withLocalPartNormalizer(null);

        $this->assertNotNull($a->localPartNormalizer);
        $this->assertNull($b->localPartNormalizer);
    }
}
