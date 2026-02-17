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

        // Start from the matching factory preset, then override as needed
        switch ($rfcMode) {
            case 'strict_intl':
                $options = ParseOptions::rfc6531();
                // rfc6531() has requireFqdn=true, but old STRICT_INTL didn't enforce FQDN
                $options->requireFqdn = false;
                // rfc6531() has validateQuotedContent=true, but old code didn't validate quoted content
                $options->validateQuotedContent = false;
                $options->rejectEmptyQuotedLocalPart = false;

                break;

            case 'strict_ascii':
            case 'strict':
                $options = ParseOptions::rfc5321();
                // rfc5321() has requireFqdn=true, but old STRICT_ASCII didn't enforce FQDN
                $options->requireFqdn = false;
                // rfc5321() has validateQuotedContent=true, but old code didn't validate quoted content
                $options->validateQuotedContent = false;
                $options->rejectEmptyQuotedLocalPart = false;
                // Old STRICT mode: allowSmtpUtf8 controlled whether UTF-8 was accepted
                $options->allowUtf8LocalPart = $allowSmtpUtf8;
                $options->allowUtf8Domain = $allowSmtpUtf8;
                // Old strict mode skipped IP global range check (bug #4)
                $options->validateIpGlobalRange = false;

                break;

            case 'normal':
                $options = ParseOptions::rfc5322();
                // rfc5322() has allowUtf8LocalPart=false, but old NORMAL mode
                // deferred UTF-8 validation (let it through parser, checked by SMTPUTF8 gate)
                // For backward compat with old tests that had allow_smtputf8=false,
                // we set allowUtf8LocalPart based on the test's allow_smtputf8 flag
                $options->allowUtf8LocalPart = $allowSmtpUtf8;
                $options->allowUtf8Domain = $allowSmtpUtf8;

                break;

            case 'relaxed':
                $options = ParseOptions::rfc2822();
                $options->allowUtf8LocalPart = $allowSmtpUtf8;
                $options->allowUtf8Domain = $allowSmtpUtf8;

                break;

            case 'legacy':
            default:
                $options = new ParseOptions(
                    ['%', '!'],
                    $separators,
                    $useWhitespaceAsSeparator,
                    $lengthLimits,
                );
                // Legacy defaults are already set by default constructor.
                // Override UTF-8 settings based on allow_smtputf8
                if (!$allowSmtpUtf8) {
                    $options->allowUtf8LocalPart = false;
                    $options->allowUtf8Domain = false;
                }
                $options->includeDomainAscii = $includeDomainAscii;

                return $options;
        }

        // For non-legacy modes, set banned chars, separators, etc.
        $options->setBannedChars(['%', '!']);
        $options->setSeparators($separators);
        $options->setUseWhitespaceAsSeparator($useWhitespaceAsSeparator);
        if ($lengthLimits !== null) {
            $options->setLengthLimits($lengthLimits);
        }
        $options->includeDomainAscii = $includeDomainAscii;

        return $options;
    }

    public function testParseEmailAddresses()
    {
        $tests = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/testspec.yml'));

        foreach ($tests as $testIndex => $test) {
            $emails = $test['emails'];
            $multiple = $test['multiple'];
            $result = $test['result'];

            $options = $this->buildOptions($test);
            $parser = new Parse(null, $options);

            $this->assertSame(
                $result,
                $parser->parse($emails, $multiple),
                "Test case #{$testIndex}: {$emails}"
            );
        }
    }
}
