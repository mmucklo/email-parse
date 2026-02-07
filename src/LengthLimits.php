<?php

namespace Email;

/**
 * Email address length limits configuration
 *
 * Contains the maximum length constraints for email addresses
 * as defined by RFC 5321, RFC 1035, and RFC erratum 1690
 */
class LengthLimits
{
    private int $maxLocalPartLength;
    private int $maxTotalLength;
    private int $maxDomainLabelLength;

    /**
     * @param int $maxLocalPartLength Maximum length for local part (before @) in octets. Default: 64 per RFC 5321
     * @param int $maxTotalLength Maximum total email length in octets. Default: 254 per RFC erratum 1690
     * @param int $maxDomainLabelLength Maximum length for domain labels in characters. Default: 63 per RFC 1035
     */
    public function __construct(
        int $maxLocalPartLength = 64,
        int $maxTotalLength = 254,
        int $maxDomainLabelLength = 63
    ) {
        $this->maxLocalPartLength = $maxLocalPartLength;
        $this->maxTotalLength = $maxTotalLength;
        $this->maxDomainLabelLength = $maxDomainLabelLength;
    }

    public function getMaxLocalPartLength(): int
    {
        return $this->maxLocalPartLength;
    }

    public function setMaxLocalPartLength(int $maxLocalPartLength): void
    {
        $this->maxLocalPartLength = $maxLocalPartLength;
    }

    public function getMaxTotalLength(): int
    {
        return $this->maxTotalLength;
    }

    public function setMaxTotalLength(int $maxTotalLength): void
    {
        $this->maxTotalLength = $maxTotalLength;
    }

    public function getMaxDomainLabelLength(): int
    {
        return $this->maxDomainLabelLength;
    }

    public function setMaxDomainLabelLength(int $maxDomainLabelLength): void
    {
        $this->maxDomainLabelLength = $maxDomainLabelLength;
    }

    /**
     * Create LengthLimits with RFC-compliant defaults
     */
    public static function createDefault(): self
    {
        return new self();
    }

    /**
     * Create LengthLimits with relaxed constraints for legacy systems
     */
    public static function createRelaxed(): self
    {
        return new self(128, 512, 128);
    }
}
