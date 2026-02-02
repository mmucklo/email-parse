<?php

namespace Email;

class ParseOptions
{
    /**
     * @var array
     */
    private $bannedChars = [];

    public function __construct(array $bannedChars = [])
    {
        if ($bannedChars) {
            $this->setBannedChars($bannedChars);
        }
    }

    /**
     * @param array $bannedChars
     * @return void
     */
    public function setBannedChars(array $bannedChars)
    {
        $this->bannedChars = [];
        foreach ($bannedChars as $bannedChar) {
            $this->bannedChars[$bannedChar] = true;
        }
    }

    /**
     * @return array
     */
    public function getBannedChars()
    {
        return $this->bannedChars;
    }
}
