<?php

namespace Email;

class ParseOptions
{
    /** @var array<string, bool> */
    private array $bannedChars = [];
    /** @var array<string, bool> */
    private array $separators = [];
    private bool $useWhitespaceAsSeparator = true;
    private LengthLimits $lengthLimits;
    private string $rfcMode = RfcMode::LEGACY;
    private bool $allowSmtpUtf8 = true;

    /**
     * @param array<string> $bannedChars
     * @param array<string> $separators
     * @param bool $useWhitespaceAsSeparator
     * @param LengthLimits|null $lengthLimits Email length limits. Uses RFC defaults if not provided
     * @param string $rfcMode RFC compliance mode (STRICT, NORMAL, RELAXED, LEGACY)
     * @param bool $allowSmtpUtf8 Allow UTF-8 local parts (RFC 6531)
     */
    public function __construct(
        array $bannedChars = [],
        array $separators = [','],
        bool $useWhitespaceAsSeparator = true,
        ?LengthLimits $lengthLimits = null,
        string $rfcMode = RfcMode::LEGACY,
        bool $allowSmtpUtf8 = true
    ) {
        if ($bannedChars) {
            $this->setBannedChars($bannedChars);
        }
        $this->setSeparators($separators);
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
        $this->lengthLimits = $lengthLimits ?? LengthLimits::createDefault();
        $this->setRfcMode($rfcMode);
        $this->allowSmtpUtf8 = $allowSmtpUtf8;
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

    public function setLengthLimits(LengthLimits $lengthLimits): void
    {
        $this->lengthLimits = $lengthLimits;
    }

    public function getLengthLimits(): LengthLimits
    {
        return $this->lengthLimits;
    }

    public function setRfcMode(string $rfcMode): void
    {
        if (!RfcMode::isValid($rfcMode)) {
            throw new \InvalidArgumentException("Invalid RFC mode: {$rfcMode}");
        }

        $this->rfcMode = $rfcMode;
    }

    public function getRfcMode(): string
    {
        return $this->rfcMode;
    }

    public function setAllowSmtpUtf8(bool $allowSmtpUtf8): void
    {
        $this->allowSmtpUtf8 = $allowSmtpUtf8;
    }

    public function getAllowSmtpUtf8(): bool
    {
        return $this->allowSmtpUtf8;
    }

    // Convenience methods for backward compatibility
    public function setMaxLocalPartLength(int $maxLocalPartLength): void
    {
        $this->lengthLimits->setMaxLocalPartLength($maxLocalPartLength);
    }

    public function getMaxLocalPartLength(): int
    {
        return $this->lengthLimits->getMaxLocalPartLength();
    }

    public function setMaxTotalLength(int $maxTotalLength): void
    {
        $this->lengthLimits->setMaxTotalLength($maxTotalLength);
    }

    public function getMaxTotalLength(): int
    {
        return $this->lengthLimits->getMaxTotalLength();
    }

    public function setMaxDomainLabelLength(int $maxDomainLabelLength): void
    {
        $this->lengthLimits->setMaxDomainLabelLength($maxDomainLabelLength);
    }

    public function getMaxDomainLabelLength(): int
    {
        return $this->lengthLimits->getMaxDomainLabelLength();
    }
}
