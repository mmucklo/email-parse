<?php

namespace Email;

/**
 * Per-parse mutable state for {@see Parse::parse()}.
 *
 * Holds the input snapshot and hoisted config, the state-machine control
 * variables, and the ~24-field address accumulator that the state handlers
 * read and mutate as they walk the input character by character.
 *
 * A fresh instance is created for every parse() call and is never stored on the
 * Parse instance, so the parser stays reentrant: a caller-supplied
 * localPartNormalizer closure may call back into parse() mid-parse without
 * clobbering the outer parse's state.
 *
 * The accumulator uses camelCase property names; the public snake_case output
 * array shape is built separately in {@see Parse::addAddress()} and is
 * unaffected by this object.
 *
 * @internal Implementation detail of {@see Parse}. The field shape is not a
 *           stable API and may change between minor versions.
 */
final class ParseContext
{
    // The per-parse input snapshot and hoisted config (chars, len, multiple,
    // emails, separators, bannedChars, useWhitespaceAsSeparator,
    // allowedWhitespace) are immutable for the whole parse — they are declared as
    // `public readonly` constructor-promoted properties (see __construct below).

    // --- Loop control state (state/subState reset per address by parse()). ---

    /** Current parser state (one of Parse::STATE_*). */
    public int $state = 0;

    /**
     * Current parser sub-state within an addr-spec (one of Parse::STATE_*).
     * Initialized by the constructor / resetAddress(); the literal 0 default is
     * STATE_TRIM, not a valid starting sub-state (which is STATE_START), so it
     * must never be relied on un-initialized.
     */
    public int $subState = 0;

    /** Current comment nesting depth. */
    public int $commentNestLevel = 0;

    // --- Accumulator fields (reset per address via resetAddress()). ---

    /** Raw address as given, comments included. */
    public string $originalAddress = '';

    /** Display name without quotes. */
    public string $nameParsed = '';

    /** Local-part without quotes. */
    public string $localPartParsed = '';

    /** Domain after '@' (may be Unicode/U-label). */
    public string $domain = '';

    /** Punycode A-label domain, populated when it differs from $domain. */
    public ?string $domainAscii = null;

    /** IP address if a domain-literal was used. */
    public string $ip = '';

    public bool $invalid = false;

    public ?string $invalidReason = null;

    public ?ParseErrorCode $invalidReasonCode = null;

    public bool $localPartQuoted = false;

    public bool $nameQuoted = false;

    public bool $addressTempQuoted = false;

    /**
     * True for exactly the character after a closing quote, so atext / a second
     * quote directly abutting a quoted-string can be rejected.
     */
    public bool $afterClosingQuote = false;

    public string $quoteTemp = '';

    public string $addressTemp = '';

    public int $addressTempPeriod = 0;

    public ?string $specialCharInSubstate = null;

    public string $commentTemp = '';

    /**
     * True for the character following an unescaped backslash inside a comment
     * (RFC 5322 §3.2.1 quoted-pair: "\)" and "\(" are literal, not structural).
     */
    public bool $commentEscaped = false;

    /**
     * True just after a comment closes mid-atom in the local part (atext already
     * accumulated), so the very next character can be inspected.
     */
    public bool $commentAfterLocalAtext = false;

    /**
     * Set when atext resumes the atom after such a comment. Whether that is an
     * error depends on what the token turns out to be: the local part of an
     * addr-spec (resolved at '@' → reject, RFC 5322 §3.2.3) or a display-name
     * phrase where "word CFWS word" is legal (resolved at '<' → clear).
     */
    public bool $localAtomSplitByComment = false;

    /** @var array<int, string> Extracted RFC 5322 comments. */
    public array $comments = [];

    /**
     * True while the parser is inside angle-addr (between `<` and `>`).
     * Used to gate obs-route detection per RFC 5322 §4.4.
     */
    public bool $inAngleAddr = false;

    /**
     * Accumulates the obs-route prefix (everything between `<` and the
     * terminating `:`) when ParseOptions::$allowObsRoute is true.
     * Empty string when no obs-route was seen.
     */
    public string $obsRoute = '';

    /**
     * @param int                 $state                    Initial parser state (a Parse::STATE_* value).
     * @param int                 $subState                 Initial addr-spec sub-state (a Parse::STATE_* value).
     * @param array<int, string>  $chars                    Input split into characters.
     * @param int                 $len                      Number of characters in $chars.
     * @param bool                $multiple                 Whether multiple addresses are being parsed.
     * @param string              $emails                   Original input string (retained for diagnostic logging).
     * @param array<string, bool> $separators               Separator characters, as a lookup map.
     * @param array<string, bool> $bannedChars              Banned characters, as a lookup map.
     * @param bool                $useWhitespaceAsSeparator Whether whitespace acts as an address separator.
     * @param array<string, bool> $allowedWhitespace        Insignificant (foldable/trimmable) whitespace, as a lookup map.
     */
    public function __construct(
        int $state,
        int $subState,
        public readonly array $chars,
        public readonly int $len,
        public readonly bool $multiple,
        public readonly string $emails,
        public readonly array $separators,
        public readonly array $bannedChars,
        public readonly bool $useWhitespaceAsSeparator,
        public readonly array $allowedWhitespace,
    ) {
        // Requiring the initial states + immutable snapshot makes an
        // un-initialized context unrepresentable: config cannot be mutated by a
        // handler, and every instance is reset before its first use.
        $this->resetAddress($state, $subState);
    }

    /**
     * Resets every accumulator field to its initial value, reusing the instance
     * for the next address in a multi-address parse (matches the historical
     * "rebuild the $emailAddress array" behaviour).
     *
     * @param int $state    Parser state to start the next address in (Parse::STATE_*).
     * @param int $subState Addr-spec sub-state to start it in (Parse::STATE_*).
     */
    public function resetAddress(int $state, int $subState): void
    {
        // Loop-control state, reset here so every per-address field has a single
        // source of truth. commentNestLevel in particular has no other reset:
        // leaving it out would let an unterminated comment leak into the next
        // address in a batch, self-healing only because '(' reassigns it to 1.
        $this->state = $state;
        $this->subState = $subState;
        $this->commentNestLevel = 0;

        $this->originalAddress = '';
        $this->nameParsed = '';
        $this->localPartParsed = '';
        $this->domain = '';
        $this->domainAscii = null;
        $this->ip = '';
        $this->invalid = false;
        $this->invalidReason = null;
        $this->invalidReasonCode = null;
        $this->localPartQuoted = false;
        $this->nameQuoted = false;
        $this->addressTempQuoted = false;
        $this->afterClosingQuote = false;
        $this->quoteTemp = '';
        $this->addressTemp = '';
        $this->addressTempPeriod = 0;
        $this->specialCharInSubstate = null;
        $this->commentTemp = '';
        $this->commentEscaped = false;
        $this->commentAfterLocalAtext = false;
        $this->localAtomSplitByComment = false;
        $this->comments = [];
        $this->inAngleAddr = false;
        $this->obsRoute = '';
    }
}
