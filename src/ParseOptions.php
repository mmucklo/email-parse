<?php

namespace Email;

class ParseOptions
{
    private array $bannedChars = [];
    private array $separators = [','];
    private bool $useWhitespaceAsSeparator = true;

    public function __construct(array $bannedChars = [], array $separators = [','], bool $useWhitespaceAsSeparator = true)
    {
        if ($bannedChars) {
            $this->setBannedChars($bannedChars);
        }
        $this->setSeparators($separators);
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
    }

    public function setBannedChars(array $bannedChars): void
    {
        $this->bannedChars = [];
        foreach ($bannedChars as $bannedChar) {
            $this->bannedChars[$bannedChar] = true;
        }
    }

    /**
     * @return array
     */
    public function getBannedChars(): array
    {
        return $this->bannedChars;
    }

    public function setSeparators(array $separators): void
    {
        $this->separators = [];
        foreach ($separators as $separator) {
            $this->separators[$separator] = true;
        }
    }

    /**
     * @return array
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
}
