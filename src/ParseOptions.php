<?php

namespace Email;

class ParseOptions
{
    private array $bannedChars = [];

    public function __construct(array $bannedChars = [])
    {
        if ($bannedChars) {
            $this->setBannedChars($bannedChars);
        }
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
}
