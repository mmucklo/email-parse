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

    // ===== v3.0 Rule Properties =====
    // Defaults match legacy (v2.x) behavior so `new ParseOptions()` is backward-compatible.

    // --- Local-Part Rules ---

    /** Allow UTF-8 characters in local-part (RFC 6531 §3.3, 6532 §3.2). */
    public bool $allowUtf8LocalPart = true;

    /** Allow obsolete local-part syntax: leading/trailing/consecutive dots (RFC 5322 §4.4). */
    public bool $allowObsLocalPart = false;

    /** Allow quoted-string form in local-part (RFC 5322 §3.2.4, 5321 §4.1.2). */
    public bool $allowQuotedString = true;

    /** Validate content of quoted-strings against qtext/quoted-pair rules (RFC 5322 §3.2.4, 5321 §4.1.2). */
    public bool $validateQuotedContent = false;

    /** Reject empty quoted local-parts ""@domain (RFC 5321 errata 5414). */
    public bool $rejectEmptyQuotedLocalPart = false;

    // --- Domain Rules ---

    /** Allow UTF-8 (U-label) domain names (RFC 6531 §3.3, 5890/5891). */
    public bool $allowUtf8Domain = true;

    /** Allow domain-literal form [IP] in domain (RFC 5321 §4.1.3). */
    public bool $allowDomainLiteral = true;

    /** Require fully-qualified domain name — at least two labels (RFC 5321 §2.3.5). */
    public bool $requireFqdn = false;

    /** Validate that IP addresses in domain-literals are in global range. */
    public bool $validateIpGlobalRange = true;

    // --- Character Validation Rules ---

    /** Reject C0 control characters U+0000-U+001F in local-part (RFC 5321 §4.1.2). */
    public bool $rejectC0Controls = false;

    /** Reject C1 control characters U+0080-U+009F in local-part (RFC 6530 §10.1). */
    public bool $rejectC1Controls = false;

    /** Apply NFC Unicode normalization to local-part and domain (RFC 6532 §3.1). */
    public bool $applyNfcNormalization = false;

    // --- Length Limits ---

    /** Enforce RFC 5321 length limits: 64 local, 254 total, 63 domain label (RFC 5321 §4.5.3.1.1). */
    public bool $enforceLengthLimits = true;

    // --- Output Options ---

    /** Include ASCII (punycode) domain in output for internationalized domains. */
    public bool $includeDomainAscii = false;

    // ===== Constructor (v2.x signature — UNCHANGED) =====

    /**
     * @param array<string> $bannedChars
     * @param array<string> $separators
     * @param bool $useWhitespaceAsSeparator
     * @param LengthLimits|null $lengthLimits Email length limits. Uses RFC defaults if not provided.
     */
    public function __construct(
        array $bannedChars = [],
        array $separators = [','],
        bool $useWhitespaceAsSeparator = true,
        ?LengthLimits $lengthLimits = null,
    ) {
        if ($bannedChars) {
            $this->setBannedChars($bannedChars);
        }
        $this->setSeparators($separators);
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
        $this->lengthLimits = $lengthLimits ?? LengthLimits::createDefault();
    }

    // ===== RFC Preset Factory Methods =====

    /**
     * RFC 5321 Mailbox (STRICT ASCII).
     * Strictest ASCII-only validation matching what SMTP servers accept.
     */
    public static function rfc5321(): self
    {
        $opts = new self();
        $opts->allowUtf8LocalPart = false;
        $opts->allowObsLocalPart = false;
        $opts->allowQuotedString = true;
        $opts->validateQuotedContent = true;
        $opts->rejectEmptyQuotedLocalPart = true;
        $opts->allowUtf8Domain = false;
        $opts->allowDomainLiteral = true;
        $opts->requireFqdn = true;
        $opts->validateIpGlobalRange = false;
        $opts->rejectC0Controls = true;
        $opts->rejectC1Controls = false;
        $opts->applyNfcNormalization = false;
        $opts->enforceLengthLimits = true;
        $opts->includeDomainAscii = false;

        return $opts;
    }

    /**
     * RFC 6531/6532 (STRICT Internationalized).
     * Full internationalization with UTF-8, Unicode normalization, strict validation.
     */
    public static function rfc6531(): self
    {
        $opts = new self();
        $opts->allowUtf8LocalPart = true;
        $opts->allowObsLocalPart = false;
        $opts->allowQuotedString = true;
        $opts->validateQuotedContent = true;
        $opts->rejectEmptyQuotedLocalPart = true;
        $opts->allowUtf8Domain = true;
        $opts->allowDomainLiteral = true;
        $opts->requireFqdn = true;
        $opts->validateIpGlobalRange = false;
        $opts->rejectC0Controls = true;
        $opts->rejectC1Controls = true;
        $opts->applyNfcNormalization = true;
        $opts->enforceLengthLimits = true;
        $opts->includeDomainAscii = true;

        return $opts;
    }

    /**
     * RFC 5322 addr-spec with obsolete syntax (NORMAL).
     * Recommended default for v3.0. Accepts obs-local-part per RFC 5322 §4.
     */
    public static function rfc5322(): self
    {
        $opts = new self();
        $opts->allowUtf8LocalPart = false;
        $opts->allowObsLocalPart = true;
        $opts->allowQuotedString = true;
        $opts->validateQuotedContent = false;
        $opts->rejectEmptyQuotedLocalPart = false;
        $opts->allowUtf8Domain = false;
        $opts->allowDomainLiteral = true;
        $opts->requireFqdn = false;
        $opts->validateIpGlobalRange = true;
        $opts->rejectC0Controls = true;
        $opts->rejectC1Controls = false;
        $opts->applyNfcNormalization = false;
        $opts->enforceLengthLimits = true;
        $opts->includeDomainAscii = false;

        return $opts;
    }

    /**
     * RFC 2822 compatible (RELAXED).
     * Maximum compatibility with older systems.
     */
    public static function rfc2822(): self
    {
        $opts = new self();
        $opts->allowUtf8LocalPart = false;
        $opts->allowObsLocalPart = true;
        $opts->allowQuotedString = true;
        $opts->validateQuotedContent = false;
        $opts->rejectEmptyQuotedLocalPart = false;
        $opts->allowUtf8Domain = false;
        $opts->allowDomainLiteral = true;
        $opts->requireFqdn = false;
        $opts->validateIpGlobalRange = true;
        $opts->rejectC0Controls = false;
        $opts->rejectC1Controls = false;
        $opts->applyNfcNormalization = false;
        $opts->enforceLengthLimits = true;
        $opts->includeDomainAscii = false;

        return $opts;
    }

    // No legacy() factory needed — `new ParseOptions()` IS legacy behavior.

    // ===== Getters/Setters =====

    /**
     * @deprecated v3.0 — Use constructor param or factory method. Will be removed in v4.0.
     * @param array<string> $bannedChars
     */
    public function setBannedChars(array $bannedChars): void
    {
        $this->bannedChars = [];
        foreach ($bannedChars as $bannedChar) {
            $this->bannedChars[$bannedChar] = true;
        }
    }

    /** @return array<string, bool> */
    public function getBannedChars(): array
    {
        return $this->bannedChars;
    }

    /**
     * @deprecated v3.0 — Use constructor param or factory method. Will be removed in v4.0.
     * @param array<string> $separators
     */
    public function setSeparators(array $separators): void
    {
        $this->separators = [];
        foreach ($separators as $separator) {
            $this->separators[$separator] = true;
        }
    }

    /** @return array<string, bool> */
    public function getSeparators(): array
    {
        return $this->separators;
    }

    /**
     * @deprecated v3.0 — Use constructor param or factory method. Will be removed in v4.0.
     */
    public function setUseWhitespaceAsSeparator(bool $useWhitespaceAsSeparator): void
    {
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
    }

    public function getUseWhitespaceAsSeparator(): bool
    {
        return $this->useWhitespaceAsSeparator;
    }

    /**
     * @deprecated v3.0 — Pass LengthLimits to constructor. Will be removed in v4.0.
     */
    public function setLengthLimits(LengthLimits $lengthLimits): void
    {
        $this->lengthLimits = $lengthLimits;
    }

    public function getLengthLimits(): LengthLimits
    {
        return $this->lengthLimits;
    }

    /**
     * @deprecated v3.0 — Pass LengthLimits to constructor. Will be removed in v4.0.
     */
    public function setMaxLocalPartLength(int $maxLocalPartLength): void
    {
        $this->lengthLimits = new LengthLimits(
            $maxLocalPartLength,
            $this->lengthLimits->maxTotalLength,
            $this->lengthLimits->maxDomainLabelLength,
        );
    }

    public function getMaxLocalPartLength(): int
    {
        return $this->lengthLimits->maxLocalPartLength;
    }

    /**
     * @deprecated v3.0 — Pass LengthLimits to constructor. Will be removed in v4.0.
     */
    public function setMaxTotalLength(int $maxTotalLength): void
    {
        $this->lengthLimits = new LengthLimits(
            $this->lengthLimits->maxLocalPartLength,
            $maxTotalLength,
            $this->lengthLimits->maxDomainLabelLength,
        );
    }

    public function getMaxTotalLength(): int
    {
        return $this->lengthLimits->maxTotalLength;
    }

    /**
     * @deprecated v3.0 — Pass LengthLimits to constructor. Will be removed in v4.0.
     */
    public function setMaxDomainLabelLength(int $maxDomainLabelLength): void
    {
        $this->lengthLimits = new LengthLimits(
            $this->lengthLimits->maxLocalPartLength,
            $this->lengthLimits->maxTotalLength,
            $maxDomainLabelLength,
        );
    }

    public function getMaxDomainLabelLength(): int
    {
        return $this->lengthLimits->maxDomainLabelLength;
    }
}
