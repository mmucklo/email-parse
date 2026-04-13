<?php

namespace Email\Benchmarks;

use Email\Parse;
use Email\ParseOptions;

/**
 * Baseline performance benchmarks for Email\Parse.
 *
 * Run with:
 *   composer bench
 *
 * Numbers are reported as time-per-iteration (see PhpBench `Mo` reports).
 * Use these to catch regressions across releases; absolute throughput
 * depends on the host PHP version and hardware.
 *
 * @BeforeMethods({"setUp"})
 * @Revs(1000)
 * @Iterations(3)
 * @Warmup(1)
 */
class ParseBench
{
    private Parse $legacy;
    private Parse $rfc5322;
    private Parse $rfc6531;

    /** @var array<string> */
    private array $batch1000;

    public function setUp(): void
    {
        $this->legacy = new Parse();
        $this->rfc5322 = new Parse(null, ParseOptions::rfc5322());
        $this->rfc6531 = new Parse(null, ParseOptions::rfc6531()->withRequireFqdn(false));

        // Realistic mailing-list batch: 1000 addresses with a mix of forms.
        $this->batch1000 = [];
        for ($i = 0; $i < 1000; ++$i) {
            $this->batch1000[] = "user{$i}@example.com";
        }
    }

    /** @Subject */
    public function benchSimpleAsciiAddress(): void
    {
        $this->legacy->parseSingle('user@example.com');
    }

    /** @Subject */
    public function benchSimpleAsciiAddressArrayApi(): void
    {
        $this->legacy->parse('user@example.com', false);
    }

    /** @Subject */
    public function benchNameAddr(): void
    {
        $this->legacy->parseSingle('"John Q. Public" <john@example.com>');
    }

    /** @Subject */
    public function benchUtf8LocalPart(): void
    {
        $this->rfc6531->parseSingle('müller@example.com');
    }

    /** @Subject */
    public function benchIdnDomain(): void
    {
        $this->rfc6531->parseSingle('user@münchen.de');
    }

    /** @Subject */
    public function benchObsRoute(): void
    {
        $this->rfc5322->parseSingle('<@hostA,@hostB:user@hostC>');
    }

    /** @Subject */
    public function benchBatch10Comma(): void
    {
        $this->legacy->parseMultiple('a@a.com, b@b.com, c@c.com, d@d.com, e@e.com, f@f.com, g@g.com, h@h.com, i@i.com, j@j.com');
    }

    /** @Subject */
    public function benchBatch100StreamCount(): void
    {
        $batch = array_slice($this->batch1000, 0, 100);
        $n = 0;
        foreach ($this->legacy->parseStream($batch) as $_) {
            ++$n;
        }
    }

    /** @Subject */
    public function benchInvalidAddress(): void
    {
        $this->legacy->parseSingle('not-an-email');
    }

    /** @Subject */
    public function benchCommentExtraction(): void
    {
        $this->legacy->parseSingle('user (the main account)@example.com (home)');
    }
}
