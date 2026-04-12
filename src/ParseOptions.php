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

    /** Allow obs-local-part syntax (RFC 5322 §4.4): permits leading, trailing, and consecutive dots. */
    public bool $allowObsLocalPart = false;

    /** Allow quoted-string form in local-part (RFC 5322 §3.2.4, 5321 §4.1.2). */
    public bool $allowQuotedString = true;

    /** Validate content of quoted-strings against qtext/quoted-pair rules (RFC 5322 §3.2.4, 5321 §4.1.2). */
    public bool $validateQuotedContent = false;

    /** Reject empty quoted local-parts like ""@domain per RFC 5321 EID 5414 (non-empty Quoted-string required). */
    public bool $rejectEmptyQuotedLocalPart = false;

    // --- Domain Rules ---

    /** Allow UTF-8 (U-label) domain names (RFC 6531 §3.3, 5890/5891). */
    public bool $allowUtf8Domain = true;

    /** Allow domain-literal form [IP] in domain (RFC 5321 §4.1.3). */
    public bool $allowDomainLiteral = true;

    /** Require fully-qualified domain name — at least two dot-separated labels (RFC 5321 §2.3.5). */
    public bool $requireFqdn = false;

    /** Validate that IP addresses in domain-literals are in global range. */
    public bool $validateIpGlobalRange = true;

    // --- Character Validation Rules ---

    /** Reject C0 control characters U+0000-U+001F in local-part (RFC 5321 §4.1.2). */
    public bool $rejectC0Controls = false;

    /** Reject C1 control characters U+0080-U+009F in local-part (RFC 6530 §10.1, RFC 6532 §3.2). */
    public bool $rejectC1Controls = false;

    /** Apply NFC Unicode normalization to local-part and domain (RFC 6532 §3.1). */
    public bool $applyNfcNormalization = false;

    // --- Length Limits ---

    /** Enforce RFC 5321 §4.5.3.1 length limits (in octets): 64 local-part (§4.5.3.1.1), 254 total (RFC 3696 EID 1690), 63 domain label (RFC 1035 §2.3.4). */
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
        foreach ($bannedChars as $char) {
            $this->bannedChars[$char] = true;
        }
        foreach ($separators as $sep) {
            $this->separators[$sep] = true;
        }
        $this->useWhitespaceAsSeparator = $useWhitespaceAsSeparator;
        $this->lengthLimits = $lengthLimits ?? LengthLimits::createDefault();
    }

    // ===== RFC Preset Factory Methods =====

    /**
     * RFC 5321 Mailbox — strict ASCII-only, matching what SMTP servers must accept.
     *
     * Follows RFC 5321 §4.1.2 (Local-part / Dot-string / Quoted-string), §4.1.3
     * (domain literals), §4.5.3.1 (length limits), and §2.3.5 (FQDN requirement).
     * No obs-local-part, no UTF-8, no C0 controls.
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
        $opts->validateIpGlobalRange = true;
        $opts->rejectC0Controls = true;
        $opts->rejectC1Controls = false;
        $opts->applyNfcNormalization = false;
        $opts->enforceLengthLimits = true;
        $opts->includeDomainAscii = false;

        return $opts;
    }

    /**
     * RFC 6531/6532 — full internationalized email (EAI), strictest validation.
     *
     * Extends RFC 5321 Mailbox syntax per RFC 6531 §3.3 (SMTPUTF8 extension) and
     * RFC 6532 §3 (UTF-8 in headers/addr-spec). Adds NFC normalization per
     * RFC 6532 §3.1, C1-control rejection per RFC 6530 §10.1, and punycode
     * (A-label) output for internationalized domains.
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
        $opts->validateIpGlobalRange = true;
        $opts->rejectC0Controls = true;
        $opts->rejectC1Controls = true;
        $opts->applyNfcNormalization = true;
        $opts->enforceLengthLimits = true;
        $opts->includeDomainAscii = true;

        return $opts;
    }

    /**
     * RFC 5322 addr-spec — recommended default for new code.
     *
     * Follows RFC 5322 §3.4.1 (addr-spec) including the obs-local-part form
     * from RFC 5322 §4.4, which allows leading/trailing/consecutive dots.
     * Generators MUST NOT produce obs-local-part (RFC 5322 §4 intro), but
     * parsers MUST accept it.  ASCII only; no UTF-8 in local-part or domain.
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
     * RFC 2822 — maximum compatibility with older software and legacy addresses.
     *
     * Like rfc5322() but also permits C0 control characters, which were not
     * explicitly prohibited by RFC 2822.  Use this preset only when you must
     * accept addresses from very old or non-conforming systems.
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
