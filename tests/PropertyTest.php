<?php

namespace Email\Tests;

use Email\Parse;
use Email\ParsedEmailAddress;
use Email\ParseOptions;
use Email\ValidationSeverity;

/**
 * Property-based tests — randomized inputs verifying structural invariants.
 *
 * Each test generates N random inputs (strings or synthesized addresses) and
 * asserts a single property that must hold for all of them. Failures point to
 * edge cases missed by the hand-written unit tests.
 *
 * Deterministic via SEED envvar; defaults to time-based. Re-run a failure:
 *   SEED=<value> composer test
 */
class PropertyTest extends \PHPUnit\Framework\TestCase
{
    private const ITERATIONS = 200;

    private int $seed;

    protected function setUp(): void
    {
        $this->seed = (int) (getenv('SEED') ?: (microtime(true) * 1000000) % PHP_INT_MAX);
        mt_srand($this->seed);
    }

    protected function tearDown(): void
    {
        // Emit the seed in case a failure needs reproduction.
        fwrite(STDERR, "  [seed={$this->seed}]");
    }

    /** Generate a random byte string of length 0–$maxLen. */
    private function randomString(int $maxLen = 80): string
    {
        $len = mt_rand(0, $maxLen);
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= chr(mt_rand(0, 255));
        }

        return $s;
    }

    /** Generate a plausible (but not guaranteed valid) email-like string. */
    private function randomEmailLike(): string
    {
        $atext = 'abcdefghijklmnopqrstuvwxyz0123456789!#$%&*+-/=?^_`{|}~';
        $localLen = mt_rand(1, 15);
        $local = '';
        for ($i = 0; $i < $localLen; $i++) {
            $local .= $atext[mt_rand(0, strlen($atext) - 1)];
        }

        $domainLen = mt_rand(1, 10);
        $domain = '';
        for ($i = 0; $i < $domainLen; $i++) {
            $domain .= chr(mt_rand(ord('a'), ord('z')));
        }

        $tld = '';
        $tldLen = mt_rand(2, 5);
        for ($i = 0; $i < $tldLen; $i++) {
            $tld .= chr(mt_rand(ord('a'), ord('z')));
        }

        return "{$local}@{$domain}.{$tld}";
    }

    /**
     * parseSingle never throws, regardless of input. Every byte string yields
     * a ParsedEmailAddress (valid or invalid), never an unhandled exception.
     */
    public function testParseSingleNeverThrows(): void
    {
        $parser = Parse::getInstance();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $input = $this->randomString();
            $result = $parser->parseSingle($input);
            $this->assertInstanceOf(ParsedEmailAddress::class, $result);
        }
    }

    /**
     * parseMultiple never throws on arbitrary input.
     */
    public function testParseMultipleNeverThrows(): void
    {
        $parser = Parse::getInstance();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $result = $parser->parseMultiple($this->randomString());
            $this->assertIsBool($result->success);
        }
    }

    /**
     * Determinism: same input always produces same output.
     */
    public function testParseIsDeterministic(): void
    {
        $parser = new Parse();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $s = $this->randomString();
            $a = $parser->parseSingle($s);
            $b = $parser->parseSingle($s);
            $this->assertSame($a->toArray(), $b->toArray(), "Non-deterministic on: " . bin2hex($s));
        }
    }

    /**
     * When invalid, both invalidReason and invalidReasonCode must be set.
     * When valid, both must be null. No half-and-half states allowed.
     */
    public function testInvalidImpliesBothReasonAndCode(): void
    {
        $parser = Parse::getInstance();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $s = $this->randomString();
            $r = $parser->parseSingle($s);
            if ($r->invalid) {
                $this->assertNotNull($r->invalidReason, "missing reason for [" . bin2hex($s) . "]");
                $this->assertNotNull($r->invalidReasonCode, "missing code for [" . bin2hex($s) . "]");
            } else {
                $this->assertNull($r->invalidReason);
                $this->assertNull($r->invalidReasonCode);
            }
        }
    }

    /**
     * Every invalid address has a severity derived from its error code.
     */
    public function testInvalidAlwaysHasSeverity(): void
    {
        $parser = Parse::getInstance();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $r = $parser->parseSingle($this->randomString());
            if ($r->invalid) {
                $this->assertInstanceOf(ValidationSeverity::class, $r->invalidSeverity());
            } else {
                $this->assertNull($r->invalidSeverity());
            }
        }
    }

    /**
     * Stringable: (string) $parsed === simpleAddress when valid, '' when invalid.
     */
    public function testStringableContract(): void
    {
        $parser = Parse::getInstance();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $r = $parser->parseSingle($this->randomString());
            $expected = $r->invalid ? '' : $r->simpleAddress;
            $this->assertSame($expected, (string) $r);
        }
    }

    /**
     * toArray() always produces the same shape as the legacy parse(…, false).
     */
    public function testToArrayMatchesLegacyParse(): void
    {
        $parser = new Parse();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $s = $this->randomString();
            $legacy = $parser->parse($s, false);
            $typed = $parser->parseSingle($s);
            $this->assertSame($legacy, $typed->toArray(), "toArray drift for [" . bin2hex($s) . "]");
        }
    }

    /**
     * Synthesized valid addresses round-trip: parseSingle($addr)->simpleAddress
     * re-parses to the same simpleAddress.
     */
    public function testValidAddressRoundTrip(): void
    {
        $parser = new Parse();
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $addr = $this->randomEmailLike();
            $first = $parser->parseSingle($addr);

            if ($first->invalid) {
                continue;
            }

            $second = $parser->parseSingle($first->simpleAddress);
            $this->assertFalse(
                $second->invalid,
                "Round-trip failed: {$addr} → {$first->simpleAddress} → invalid ({$second->invalidReason})",
            );
            $this->assertSame($first->simpleAddress, $second->simpleAddress);
        }
    }

    /**
     * All four factory presets never crash on random byte input.
     */
    public function testAllPresetsNeverCrash(): void
    {
        $presets = [
            ParseOptions::rfc5321(),
            ParseOptions::rfc6531(),
            ParseOptions::rfc5322(),
            ParseOptions::rfc2822(),
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $input = $this->randomString();
            foreach ($presets as $opts) {
                $r = (new Parse(null, $opts))->parseSingle($input);
                $this->assertInstanceOf(ParsedEmailAddress::class, $r);
            }
        }
    }
}
