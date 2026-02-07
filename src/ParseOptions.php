<?php

namespace Email;

class ParseOptions
{
    /** @var array<string, bool> */
    private array $bannedChars = [];
    /** @var array<string, bool> */
    private array $separators = [];
    private bool $useWhitespaceAsSeparator = true;
    private int $maxLocalPartLength = 64;
    private int $maxTotalLength = 254;
    private int $maxDomainLabelLength = 63;

    /**
     * @param array<string> $bannedChars
     * @param array<string> $separators
     * @param bool $useWhitespaceAsSeparator
     * @param int|null $maxLocalPartLength Maximum length for local part (before @) in octets. Default: 64 per RFC 5321
     * @param int|null $maxTotalLength Maximum total email length in octets. Default: 254 per RFC erratum 1690
     * @param int|null $maxDomainLabelLength Maximum length for domain labels in characters. Default: 63 per RFC 1035
     */
    public function __construct(
        array $bannedChars = [],
        array $separators = [','],
        bool $useWhitespaceAsSeparator = true,
        ?int $maxLocalPartLength = null,
        ?int $maxTotalLength = null,
        ?int $maxDomainLabelLength = null
    ) {
        if ($bannedChars) {
            $this->setBannedChars($bannedChars);
        }
        $this->setSeparators($separators);
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
        
        if ($maxLocalPartLength !== null) {
            $this->maxLocalPartLength = $maxLocalPartLength;
        }
        if ($maxTotalLength !== null) {
            $this->maxTotalLength = $maxTotalLength;
        }
        if ($maxDomainLabelLength !== null) {
            $this->maxDomainLabelLength = $maxDomainLabelLength;
        }
    }

    /**
     * @param array<string> $bannedChars
     */
    public function setBannedChars(array $bannedChars): void
    {
        $this->bannedChars = [];
        foreach ($bannedChars as $bannedChar) {
            $this->bannedChars[$bannedChar] = true;
        }
    }

    /**
     * @return array<string, bool>
     */
    public function getBannedChars(): array
    {
        return $this->bannedChars;
    }

    /**
     * @param array<string> $separators
     */
    public function setSeparators(array $separators): void
    {
        $this->separators = [];
        foreach ($separators as $separator) {
            $this->separators[$separator] = true;
        }
    }

    /**
     * @return array<string, bool>
     */
    public function getSeparators(): array
    {
        return $this->separators;
    }

    public function setUseWhitespaceAsSeparator(bool $useWhitespaceAsSeparator): void
    {
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
    }

    public function getUseWhitespaceAsSeparator(): bool
    {
        return $this->useWhitespaceAsSeparator;
    }

    public function setMaxLocalPartLength(int $maxLocalPartLength): void
    {
        $this->maxLocalPartLength = $maxLocalPartLength;
    }

    public function getMaxLocalPartLength(): int
    {
        return $this->maxLocalPartLength;
    }

    public function setMaxTotalLength(int $maxTotalLength): void
    {
        $this->maxTotalLength = $maxTotalLength;
    }

    public function getMaxTotalLength(): int
    {
        return $this->maxTotalLength;
    }

    public function setMaxDomainLabelLength(int $maxDomainLabelLength): void
    {
        $this->maxDomainLabelLength = $maxDomainLabelLength;
    }

    public function getMaxDomainLabelLength(): int
    {
        return $this->maxDomainLabelLength;
    }
}
