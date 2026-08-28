<?php

namespace Email;

use Email\ParseErrorCode as Err;
use Psr\Log\LoggerInterface;

/**
 * Class Parse.
 */
class Parse
{
    // The state-machine states are the {@see ParserState} enum (formerly
    // Parse::STATE_* constants).

    /** The full set of whitespace characters, as a lookup map (RFC 5234 WSP + CR/LF). */
    private const WHITESPACE = [' ' => true, "\t" => true, "\r" => true, "\n" => true];

    /**
     * @var ?Parse
     */
    protected static ?Parse $instance = null;

    /**
     * @var ?LoggerInterface
     */
    protected ?LoggerInterface $logger = null;

    /**
     * @var ParseOptions
     */
    protected ParseOptions $options;

    /** Lazily-created intl Spoofchecker, reused across a batch (see detectConfusableDomain). */
    private ?\Spoofchecker $spoofchecker = null;

    /**
     * Return a shared singleton instance configured with default options.
     *
     * @deprecated 4.0 Prefer explicit instantiation — `new Parse($logger, $options)`.
     *             The singleton carries process-global state and cannot take custom
     *             options; it will be removed in 5.0.
     *
     * @return Parse The instance
     */
    public static function getInstance(): Parse
    {
        if (!self::$instance) {
            return self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @param LoggerInterface|null $logger  PSR-3 compliant logger
     * @param ParseOptions|null    $options Parser configuration options
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?ParseOptions $options = null
    ) {
        $this->logger = $logger;
        $this->options = $options ?: new ParseOptions(['%', '!']);
    }

    /**
     * Allows for post-construct injection of a logger.
     *
     * @param LoggerInterface $logger PSR-3 compliant logger
     */
    public function setLogger(LoggerInterface $logger): Parse
    {
        $this->logger = $logger;

        return $this;
    }

    public function setOptions(ParseOptions $options): Parse
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return ParseOptions
     */
    public function getOptions(): ParseOptions
    {
        return $this->options;
    }

    /**
     * Abstraction to prevent logging when there's no logger.
     *
     * @param mixed  $level
     * @param string $message
     */
    protected function log(mixed $level, string $message): void
    {
        $this->logger?->log($level, $message);
    }

    /**
     * Validates IP address with global range check.
     *
     * For PHP 8.2+, uses FILTER_FLAG_GLOBAL_RANGE constant.
     * For PHP 8.1, manually checks if IP is in global range.
     *
     * @param string $ip The IP address to validate
     * @param int $ipType FILTER_FLAG_IPV4 or FILTER_FLAG_IPV6
     * @return bool True if IP is valid and in global range, false otherwise
     */
    private function validateIpGlobalRange(string $ip, int $ipType): bool
    {
        // PHP 8.2+ exposes FILTER_FLAG_GLOBAL_RANGE. Look it up via constant() so
        // static analyzers running against a PHP 8.1 baseline do not flag it as
        // an undefined reference.
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            /** @var int $globalRangeFlag */
            $globalRangeFlag = constant('FILTER_FLAG_GLOBAL_RANGE');

            return filter_var($ip, FILTER_VALIDATE_IP, $ipType | $globalRangeFlag) !== false;
        }

        // PHP 8.1: Manually check for private/reserved ranges
        if (preg_match("/^::ffff:(\d+\.\d+.\d+.\d+)$/i", $ip, $matches)) {
            $ip = $matches[1];
            // FILTER_FLAG_NO_RES_RANGE does not cover all IETF-assigned special-purpose ranges.
            // Explicitly reject IETF Protocol Assignments (RFC 5736: 192.0.0.0/24) and
            // documentation TEST-NET ranges (RFC 5737: 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
            if (str_starts_with($ip, "192.0.0.") || str_starts_with($ip, "192.0.2.") || str_starts_with($ip, "198.51.100.") || str_starts_with($ip, "203.0.113.")) {
                return false;
            }
            $ipType = FILTER_FLAG_IPV4;
        }

        // Check if it's NOT in private or reserved ranges
        return filter_var($ip, FILTER_VALIDATE_IP, $ipType | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Parses a list of 1 to n email addresses separated by space or comma.
     *
     * @deprecated 4.0 Use {@see parseSingle()} for one address or
     *             {@see parseMultiple()} for a list — both return typed value
     *             objects (or {@see parseStream()} for large batches). This
     *             polymorphic array-returning method is retained for backward
     *             compatibility and will be removed in 5.0.
     *
     * Compliance level is controlled by the ParseOptions passed to the constructor:
     *   - ParseOptions::rfc5321()  — RFC 5321 Mailbox (strict ASCII, SMTP-compatible)
     *   - ParseOptions::rfc6531()  — RFC 6531/6532 (full UTF-8, NFC normalization)
     *   - ParseOptions::rfc5322()  — RFC 5322 addr-spec with obs-local-part (recommended default)
     *   - ParseOptions::rfc2822()  — RFC 2822 maximum compatibility
     *   - new ParseOptions()       — Legacy v2.x behavior
     *
     * Quoted strings in the middle of an address (e.g. test"test"test@xyz.com)
     * are tolerated by the parser but obs-local-part is only accepted when
     * ParseOptions::$allowObsLocalPart is true (RFC 5322 §4.4).
     * Backslash-escaping outside of quotes (test\@test@xyz.com) is not supported;
     * write it as "test\@test"@xyz.com instead (RFC 5322 §3.2.4).
     *
     * Here are a few other examples:
     *
     *  "John Q. Public" <johnpublic@xyz.com>
     *  this.is.an.address@xyz.com
     *  how-about-an-ip@[8.8.8.8]
     *  how-about-comments(this is a comment!!)@xyz.com
     *
     * @param string $emails   List of email addresses separated by comma or space if multiple
     * @param bool   $multiple (optional, default: true) Whether to parse for multiple email addresses or not
     * @param string $encoding (optional, default: 'UTF-8') Character encoding of the $emails input string
     *
     * @return array if ($multiple):
     *               array('success' => boolean, // true only if all addresses are valid
     *               'reason' => string|null, // failure summary if any address is invalid, null otherwise
     *               'email_addresses' =>
     *               array('address' => string, // canonical address, comments stripped
     *               'original_address' => string, // raw address as given, comments included
     *               'simple_address' => string, // local-part@domain-part (e.g. someone@somewhere.com)
     *               'name' => string, // display name including quotes (e.g.: "John Q. Public")
     *               'name_parsed' => string, // display name without quotes (e.g.: John Q. Public)
     *               'local_part' => string, // local-part including quotes if quoted (e.g. "john")
     *               'local_part_parsed' => string, // local-part without quotes (e.g. john)
     *               'domain' => string, // domain after '@' (may be Unicode/U-label)
     *               'domain_ascii' => string|null, // punycode A-label domain when includeDomainAscii=true
     *               'ip' => string, // IP address if domain-literal used (e.g. 8.8.8.8)
     *               'domain_part' => string, // domain or [IP] as it appears after '@'
     *               'invalid' => boolean, // true if the address failed validation
     *               'invalid_reason' => string|null, // reason for failure, null if valid
     *               'invalid_reason_code' => ParseErrorCode|null, // structured error code, null if valid
     *               'comments' => array), // extracted RFC 5322 comments (e.g. ['note'])
     *               array( .... ) // the next email address matched
     *               )
     *               else:
     *               array('address' => string, 'original_address' => string,
     *               'simple_address' => string, 'name' => string, 'name_parsed' => string,
     *               'local_part' => string, 'local_part_parsed' => string,
     *               'domain' => string, 'domain_ascii' => string|null,
     *               'ip' => string, 'domain_part' => string,
     *               'invalid' => boolean, 'invalid_reason' => string|null,
     *               'invalid_reason_code' => ParseErrorCode|null, 'comments' => array)
     *               endif;
     *
     * @psalm-suppress PossiblyUnusedMethod Deprecated public API — the typed
     *   methods use parseInternal() directly, so nothing internal calls this,
     *   but external code still does until its 5.0 removal.
     */
    public function parse(string $emails, bool $multiple = true, string $encoding = 'UTF-8'): array
    {
        return $this->parseInternal($emails, $multiple, $encoding);
    }

    /**
     * Parse a single email address and return a typed {@see ParsedEmailAddress}
     * value object with IDE autocomplete and static-analysis-friendly fields.
     */
    public function parseSingle(string $email, string $encoding = 'UTF-8'): ParsedEmailAddress
    {
        return ParsedEmailAddress::fromArray($this->parseInternal($email, false, $encoding));
    }

    /**
     * Parse a list of email addresses and return a typed {@see ParseResult}.
     *
     * Separator handling and per-address rules are configured via
     * {@see ParseOptions}.
     */
    public function parseMultiple(string $emails, string $encoding = 'UTF-8'): ParseResult
    {
        /** @var array{success: bool, reason: ?string, email_addresses: array<int, array<string, mixed>>} $raw */
        $raw = $this->parseInternal($emails, true, $encoding);

        return ParseResult::fromArray($raw);
    }

    /**
     * Lazily parse a batch of email address strings, yielding one
     * {@see ParsedEmailAddress} per matched address.
     *
     * Use this when processing large batches (e.g. a CSV of mailing-list
     * addresses) where holding every parsed result in memory is undesirable.
     * Each item in `$input` is parsed with multi-address separator handling,
     * so a single item may contain several comma- or whitespace-separated
     * addresses.
     *
     *   foreach ($parser->parseStream($csvRows) as $addr) {
     *       if ($addr->invalid) continue;
     *       $repo->upsert($addr->simpleAddress);
     *   }
     *
     * @param  iterable<string> $input    Each item is an address string (optionally multi-address).
     * @param  string           $encoding Character encoding of the input strings.
     * @return \Generator<ParsedEmailAddress>
     */
    public function parseStream(iterable $input, string $encoding = 'UTF-8'): \Generator
    {
        foreach ($input as $emails) {
            $result = $this->parseInternal((string) $emails, true, $encoding);
            foreach ($result['email_addresses'] as $address) {
                yield ParsedEmailAddress::fromArray($address);
            }
        }
    }

    /**
     * Core parser shared by parseSingle()/parseMultiple()/parseStream() and the
     * deprecated {@see parse()} shim. Returns the raw array documented on parse():
     * the multi-address envelope when $multiple is true, or a single-address hash
     * otherwise.
     *
     * @return array<string, mixed>
     */
    private function parseInternal(string $emails, bool $multiple, string $encoding): array
    {
        $emailAddresses = [];
        $success = true;
        $reason = null;

        // Split once into an array of characters rather than calling
        // mb_substr($emails, $i, 1) on every iteration. For multi-byte encodings
        // each mb_substr rescans from the start of the string (O(n) per call, so
        // O(n^2) over the loop); mb_str_split does it in a single O(n) pass.
        $chars = mb_str_split($emails, 1, $encoding);
        $len = count($chars);
        if (0 == $len) {
            $success = false;
            $reason = 'No emails passed in';
        }
        // Whitespace treated as insignificant (folding/separators; trimmable). In
        // single-address mode CR and LF are excluded — a lone addr-spec has no line
        // endings — unless trimSingleAddressWhitespace opts back into liberal trimming.
        $allowedWhitespace = $this->options->getAllowedWhitespace();
        if (!$multiple && !$this->options->trimSingleAddressWhitespace) {
            unset($allowedWhitespace["\r"], $allowedWhitespace["\n"]);
        }

        // Per-parse accumulator. A fresh instance (never an instance property)
        // keeps parse() reentrant across a localPartNormalizer callback. The
        // constructor takes the initial state (STATE_TRIM) and sub-state
        // (STATE_START) plus the immutable input snapshot + hoisted config, which
        // it exposes as readonly properties — so no handler can mutate config, and
        // the context is fully initialized before first use. $chars/$len are also
        // kept as locals below for the tight loop counter.
        $ctx = new ParseContext(
            ParserState::TRIM,
            ParserState::START,
            $chars,
            $len,
            $multiple,
            $emails,
            $this->options->getSeparators(),
            $this->options->getBannedChars(),
            $this->options->getUseWhitespaceAsSeparator(),
            $allowedWhitespace,
        );

        $curChar = null;
        for ($i = 0; $i < $len; ++$i) {
            $prevChar = $curChar; // Previous Character
            $curChar = $chars[$i]; // Current Character
            switch ($ctx->state) {
                case ParserState::SKIP_AHEAD:
                    $this->handleStateSkipAhead($ctx, $curChar);

                    break;
                    /* @noinspection PhpMissingBreakStatementInspection — STATE_TRIM falls through to STATE_ADDRESS */
                case ParserState::TRIM:
                    if (!$this->handleStateTrim($ctx, $curChar)) {
                        break;
                    }
                    // no break — a plain character falls through to STATE_ADDRESS
                case ParserState::ADDRESS:
                    $this->handleStateAddress($ctx, $curChar, $prevChar, $i);

                    break;
                case ParserState::SQUARE_BRACKET:
                    $this->handleStateSquareBracket($ctx, $curChar);

                    break;
                case ParserState::OBS_ROUTE:
                    $this->handleStateObsRoute($ctx, $curChar);

                    break;
                case ParserState::QUOTE:
                    $this->handleStateQuote($ctx, $curChar, $i);

                    break;
                case ParserState::COMMENT:
                    $this->handleStateComment($ctx, $curChar);

                    break;
                default:
                    // Shouldn't ever get here - what is $ctx->state?
                    $ctx->originalAddress .= $curChar;
                    $ctx->invalid = true;
                    $ctx->invalidReason = 'Error during parsing';
                    $ctx->invalidReasonCode = Err::ParseError;
                    $this->log('error', "Email\\Parse->parse - error during parsing - \$state: {$ctx->state->name}\n\$subState: {$ctx->subState->name}\n\$i: {$i}\n\$curChar: {$curChar}");

                    break;
            }

            // if there's a $ctx->originalAddress and the state is set to STATE_END_ADDRESS
            if (ParserState::END_ADDRESS == $ctx->state && strlen($ctx->originalAddress) > 0) {
                $invalid = $this->addAddress(
                    $emailAddresses,
                    $ctx,
                    $i
                );

                if ($invalid) {
                    if (!$success) {
                        $reason = 'Invalid email addresses';
                    } else {
                        $reason = 'Invalid email address';
                        $success = false;
                    }
                }

                // Reset all per-address state before the next address in the batch.
                $ctx->resetAddress(ParserState::TRIM, ParserState::START);
            }

            // Fire once, on the transition into invalid: STATE_SKIP_AHEAD does not clear
            // the flag, so without the state guard this block would re-run every remaining
            // character — and interpolating the full $emails / original_address each time
            // (even under a NullLogger, the argument is still built) makes malformed input
            // O(n^2). See the DoS regression benchmark.
            if ($ctx->invalid && ParserState::SKIP_AHEAD !== $ctx->state) {
                $this->log('debug', "Email\\Parse->parse - invalid - {$ctx->invalidReason}\n\$ctx->originalAddress {$ctx->originalAddress}\n\$emails: {$emails}");
                $ctx->state = ParserState::SKIP_AHEAD;
            }
        }

        // End-of-input reached still inside a delimiter (quote, comment, domain
        // literal, or obs-route) — the construct was never closed. Keyed on the
        // parser state rather than quote_temp, since bracket/comment content is
        // buffered elsewhere (a closed delimiter always returns to STATE_ADDRESS).
        if (!$ctx->invalid && in_array($ctx->state, [ParserState::QUOTE, ParserState::COMMENT, ParserState::SQUARE_BRACKET, ParserState::OBS_ROUTE], true)) {
            $ctx->invalid = true;
            [$ctx->invalidReason, $ctx->invalidReasonCode] = match ($ctx->state) {
                ParserState::QUOTE => ['No ending quote: \'"\'', Err::UnterminatedQuote],
                ParserState::COMMENT => ['No closing parenthesis: \')\'', Err::UnterminatedComment],
                ParserState::SQUARE_BRACKET => ['No closing square bracket: \']\'', Err::UnterminatedSquareBracket],
                ParserState::OBS_ROUTE => ['Incomplete obs-route: missing colon before end of input', Err::IncompleteAddress],
            };
        }
        if (!$ctx->invalid && ($ctx->addressTemp || $ctx->quoteTemp)) {
            $this->log('error', "Email\\Parse->parse - corruption during parsing - leftovers:\n\$i: {$i}\n\$ctx->addressTemp: {$ctx->addressTemp}\n\$ctx->quoteTemp: {$ctx->quoteTemp}\nEmails: {$emails}");
            $ctx->invalid = true;
            $ctx->invalidReason = 'Incomplete address';
            $ctx->invalidReasonCode = Err::IncompleteAddress;
            if (!$success) {
                $reason = 'Invalid email addresses';
            } else {
                $reason = 'Invalid email address';
                $success = false;
            }
        }

        // Did we find no email addresses at all? An empty local-part only counts as
        // "no address" when it is unquoted; `""@domain` is a legitimately-empty quoted
        // local-part whose acceptance is decided later by rejectEmptyQuotedLocalPart.
        if (!$ctx->invalid && !count($emailAddresses) && (!$ctx->originalAddress || (!$ctx->localPartParsed && !$ctx->localPartQuoted))) {
            $success = false;
            $reason = 'No email addresses found';
            if (!$multiple) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'No email address found';
                $ctx->invalidReasonCode = Err::IncompleteAddress;
                $this->addAddress(
                    $emailAddresses,
                    $ctx,
                    $i
                );
            }
        } elseif ($ctx->originalAddress) {
            $invalid = $this->addAddress(
                $emailAddresses,
                $ctx,
                $i
            );
            if ($invalid) {
                if (!$success) {
                    $reason = 'Invalid email addresses';
                } else {
                    $reason = 'Invalid email address';
                    $success = false;
                }
            }
        }
        if ($multiple) {
            return ['success' => $success, 'reason' => $reason, 'email_addresses' => $emailAddresses];
        } else {
            return $emailAddresses[0];
        }
    }

    /**
     * STATE_SKIP_AHEAD: a bad address was seen; discard characters until the next
     * separator, then let the main loop transition to STATE_END_ADDRESS.
     */
    private function handleStateSkipAhead(ParseContext $ctx, string $curChar): void
    {
        $isWhitespaceSeparator = $ctx->useWhitespaceAsSeparator && isset($ctx->allowedWhitespace[$curChar]);

        if ($ctx->multiple && ($isWhitespaceSeparator || isset($ctx->separators[$curChar]))) {
            $ctx->state = ParserState::END_ADDRESS;
        } else {
            $ctx->originalAddress .= $curChar;
        }
    }

    /**
     * STATE_TRIM: skip leading whitespace and detect a leading quote/comment.
     *
     * @return bool true when the character is ordinary and parsing should fall
     *              through to STATE_ADDRESS; false when it was consumed here
     */
    private function handleStateTrim(ParseContext $ctx, string $curChar): bool
    {
        if (isset($ctx->allowedWhitespace[$curChar])) {
            return false;
        }
        $ctx->state = ParserState::ADDRESS;
        if ('"' == $curChar) {
            $ctx->originalAddress .= $curChar;
            $ctx->state = ParserState::QUOTE;

            return false;
        }
        if ('(' == $curChar) {
            $ctx->originalAddress .= $curChar;
            $ctx->state = ParserState::COMMENT;
            // A leading comment opens at nest level 1 (matches the
            // STATE_ADDRESS entry); without this an unbalanced nested
            // comment like "((x)" would appear closed after one ")".
            $ctx->commentNestLevel = 1;

            return false;
        }

        // Non-whitespace, non-special char: fall through to STATE_ADDRESS processing.
        return true;
    }

    /**
     * STATE_ADDRESS: the main dispatch on the current character. Small structural
     * branches are handled inline; the heavier ones (CFWS, '@', '.', atext and
     * non-atext runs) delegate to dedicated helpers below.
     */
    private function handleStateAddress(ParseContext $ctx, string $curChar, ?string $prevChar, int $i): void
    {
        if (!isset($ctx->separators[$curChar]) || !$ctx->multiple) {
            $ctx->originalAddress .= $curChar;
        }

        if ($ctx->afterClosingQuote) {
            $ctx->afterClosingQuote = false;
            // RFC 5322 §3.2.4: a quoted-string is a whole word. Only a dot
            // (obs word.word), '@', angle brackets, CFWS, or a separator may
            // follow it — atext or a second quote directly abutting it is invalid.
            if ('"' === $curChar || $curChar > "\x7f" || preg_match('/[A-Za-z0-9_\-!#$%&\'*+\/=?^`{|}~]/', $curChar)) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'A quoted string in the local part must be followed by a dot, "@", or the end — text or a second quote cannot immediately follow it';
                $ctx->invalidReasonCode = Err::AtextAfterQuotedString;
            }
        }

        if ($ctx->commentAfterLocalAtext) {
            $ctx->commentAfterLocalAtext = false;
            // atext or a second quoted-string resuming the word after a comment.
            // Defer the verdict: it is only an error if this turns out to be an
            // addr-spec local part (resolved at '@'); in a display-name phrase
            // "word CFWS word" is legal and is cleared at '<'.
            if ('"' === $curChar || $curChar > "\x7f" || preg_match('/[A-Za-z0-9_\-!#$%&\'*+\/=?^`{|}~]/', $curChar)) {
                $ctx->localAtomSplitByComment = true;
            }
        }

        if ('(' == $curChar) {
            // Handle comment
            $ctx->state = ParserState::COMMENT;
            $ctx->commentNestLevel = 1;

            return;
        } elseif (isset($ctx->separators[$curChar])) {
            // Handle separator (comma, semicolon, etc.)
            if ($ctx->multiple && (ParserState::DOMAIN == $ctx->subState || ParserState::AFTER_DOMAIN == $ctx->subState)) {
                // If we're already in the domain part, this should be the end of the address
                $ctx->state = ParserState::END_ADDRESS;

                return;
            } else {
                $ctx->invalid = true;
                if ($ctx->multiple || ($i + 5) >= $ctx->len) {
                    $ctx->invalidReason = 'Misplaced separator or missing "@" symbol';
                    $ctx->invalidReasonCode = Err::MisplacedSeparator;
                } else {
                    $ctx->invalidReason = 'Separator not permitted - only one email address allowed';
                    $ctx->invalidReasonCode = Err::SeparatorNotPermitted;
                }
            }
        } elseif (isset($ctx->allowedWhitespace[$curChar])) {
            if ($this->handleAddressWhitespace($ctx, $curChar, $i)) {
                return;
            }
        } elseif ('<' == $curChar) {
            // Start of the local part
            if (ParserState::LOCAL_PART == $ctx->subState || ParserState::DOMAIN == $ctx->subState) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Email address contains multiple opening "<" (either a typo or multiple emails that need to be separated by a comma or space)';
                $ctx->invalidReasonCode = Err::MultipleOpeningAngle;
            } else {
                // Here should be the start of the local part for sure everything else then is part of the name
                $ctx->subState = ParserState::LOCAL_PART;
                $ctx->specialCharInSubstate = null;
                $ctx->inAngleAddr = true;
                // Any quote before `<` was the display name, not the local part;
                // clear the quoted flag the closing-quote handler set so the real
                // local-part inside the angle-addr starts unquoted. Likewise any
                // comment before `<` sat in the display-name phrase (legal there),
                // not an addr-spec local part — clear the deferred split marker.
                $ctx->localPartQuoted = false;
                $ctx->localAtomSplitByComment = false;
                $this->handleQuote($ctx);
            }
        } elseif ('>' == $curChar) {
            // Should be the end of the domain part. Accept STATE_DOMAIN
            // (normal dot-atom domain) and also STATE_AFTER_DOMAIN, which a
            // domain-literal (`<user@[1.2.3.4]>`, `]` transitions to AFTER_DOMAIN)
            // or trailing CFWS reaches — but only when a domain or IP is actually
            // present, so `<user@ >` / `<user@[]>` still fail.
            if (ParserState::DOMAIN == $ctx->subState
                || (ParserState::AFTER_DOMAIN == $ctx->subState
                    && ('' !== $ctx->domain || '' !== $ctx->ip))) {
                $ctx->subState = ParserState::AFTER_DOMAIN;
                $ctx->inAngleAddr = false;
            } else {
                $ctx->invalid = true;
                $ctx->invalidReason = "Did not find domain name before a closing '>'";
                $ctx->invalidReasonCode = Err::MissingDomainBeforeClosingAngle;
            }
        } elseif ('"' == $curChar) {
            // If we hit a quote - change to the quote state, unless it's in the domain, in which case it's error
            if (ParserState::DOMAIN == $ctx->subState || ParserState::AFTER_DOMAIN == $ctx->subState) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Quote \'"\' found where it shouldn\'t be';
                $ctx->invalidReasonCode = Err::MisplacedQuote;
            } else {
                $ctx->state = ParserState::QUOTE;
            }
        } elseif ('@' == $curChar) {
            $this->handleAddressAt($ctx);
        } elseif ('[' == $curChar) {
            // A domain literal ("[...]") is the entire domain (RFC 5322 §3.4.1),
            // so '[' is only valid at the start of the domain — not in the local
            // part, and not after domain characters or a first literal. Accepting
            // it mid-domain used to set both domain and ip and surface as an
            // internal "parser confusion" error.
            if (ParserState::DOMAIN != $ctx->subState) {
                $ctx->invalid = true;
                $ctx->invalidReason = "Invalid character '[' in email address";
                $ctx->invalidReasonCode = Err::InvalidOpeningBracket;
            } elseif ('' !== $ctx->domain || '' !== $ctx->ip) {
                $ctx->invalid = true;
                $ctx->invalidReason = "A domain literal '[...]' must be the entire domain, not combined with other domain characters";
                $ctx->invalidReasonCode = Err::InvalidOpeningBracket;
            } else {
                $ctx->state = ParserState::SQUARE_BRACKET;
            }
        } elseif ('.' == $curChar) {
            // Period placement (RFC 5322 §3.4) — inlined as it is per-character hot.
            if ('.' == $prevChar && !$this->options->allowObsLocalPart) {
                // Consecutive dots only allowed when obs-local-part is enabled
                $ctx->invalid = true;
                $ctx->invalidReason = "Email address should not contain two dots '.' in a row";
                $ctx->invalidReasonCode = Err::ConsecutiveDots;
            } elseif (ParserState::LOCAL_PART == $ctx->subState) {
                if (!$ctx->localPartParsed && !$this->options->allowObsLocalPart) {
                    // Leading dots only allowed when obs-local-part is enabled
                    $ctx->invalid = true;
                    $ctx->invalidReason = "Email address can not start with '.'";
                    $ctx->invalidReasonCode = Err::LeadingDot;
                } else {
                    $ctx->localPartParsed .= $curChar;
                }
            } elseif (ParserState::DOMAIN == $ctx->subState) {
                $ctx->domain .= $curChar;
            } elseif (ParserState::AFTER_DOMAIN == $ctx->subState) {
                $ctx->invalid = true;
                $ctx->invalidReason = "Stray period '.' found after domain of email address";
                $ctx->invalidReasonCode = Err::StrayPeriodAfterDomain;
            } elseif (ParserState::START == $ctx->subState) {
                if ($ctx->quoteTemp) {
                    $ctx->addressTemp .= $ctx->quoteTemp;
                    $ctx->addressTempQuoted = true;
                    $ctx->quoteTemp = '';
                }
                $ctx->addressTemp .= $curChar;
                ++$ctx->addressTempPeriod;
            } else {
                // RFC 5322 §3.4: a period is not an atext character and is not
                // valid in an unquoted display name or at the start of an address.
                $ctx->invalid = true;
                $ctx->invalidReason = 'Stray period found in email address.  If the period is part of a person\'s name, it must appear in double quotes - e.g. "John Q. Public". Otherwise, an email address shouldn\'t begin with a period.';
                $ctx->invalidReasonCode = Err::StrayPeriod;
            }
        } elseif (preg_match('/[A-Za-z0-9_\-!#$%&\'*+\/=?^`{|}~]/', $curChar)) {
            // atext (RFC 5322 §3.2.3) — the per-character hot path; inlined to keep
            // one call per character. Appends to the local-part, display name,
            // domain or pending word per the sub-state.
            if (isset($ctx->bannedChars[$curChar])) {
                $ctx->invalid = true;
                $ctx->invalidReason = "This character is not allowed in email addresses submitted (please put in quotes if needed): '{$curChar}'";
                $ctx->invalidReasonCode = Err::CharacterNotAllowed;
            } elseif (('/' == $curChar || '|' == $curChar) &&
            !$ctx->localPartParsed && !$ctx->addressTemp && !$ctx->quoteTemp && !$ctx->nameParsed) {
                $ctx->invalid = true;
                $ctx->invalidReason = "This character is not allowed at the beginning of an email address (please put in quotes if needed): '{$curChar}'";
                $ctx->invalidReasonCode = Err::InvalidCharacterAtStart;
            } elseif (ParserState::LOCAL_PART == $ctx->subState) {
                // Legitimate character - Determine where to append based on the current 'substate'

                if ($ctx->quoteTemp) {
                    $ctx->localPartParsed .= $ctx->quoteTemp;
                    $ctx->quoteTemp = '';
                    $ctx->localPartQuoted = true;
                }
                $ctx->localPartParsed .= $curChar;
            } elseif (ParserState::NAME == $ctx->subState) {
                if ($ctx->quoteTemp) {
                    $ctx->nameParsed .= $ctx->quoteTemp;
                    $ctx->quoteTemp = '';
                    $ctx->nameQuoted = true;
                }
                $ctx->nameParsed .= $curChar;
            } elseif (ParserState::DOMAIN == $ctx->subState) {
                $ctx->domain .= $curChar;
            } else {
                if ($ctx->quoteTemp) {
                    $ctx->addressTemp .= $ctx->quoteTemp;
                    $ctx->addressTempQuoted = true;
                    $ctx->quoteTemp = '';
                }
                $ctx->addressTemp .= $curChar;
            }
        } else {
            $this->handleAddressNonAtext($ctx, $curChar);
        }
    }

    /**
     * STATE_ADDRESS whitespace (RFC 5322 §3.2.2 CFWS). Looks ahead past the WSP
     * run to classify the fold and decide whether it is absorbed, ends the
     * address, or is an error.
     *
     * @return bool true when the address is complete and the caller should stop
     *              processing this character (STATE_END_ADDRESS was set)
     */
    private function handleAddressWhitespace(ParseContext $ctx, string $curChar, int $i): bool
    {
        // Look ahead past the WSP run to find the next significant character; that
        // character determines which kind of CFWS this is and whether it can be
        // silently absorbed or if it marks an end-of-address / error.
        $foundComment = false;
        $lookAheadChar = null;
        for ($j = ($i + 1); $j < $ctx->len; ++$j) {
            $c = $ctx->chars[$j];
            if ('(' === $c) {
                $foundComment = true;

                break;
            }
            if (' ' !== $c && "\t" !== $c && "\r" !== $c && "\n" !== $c) {
                $lookAheadChar = $c;

                break;
            }
        }

        // CFWS absorption: whitespace is legal per RFC 5322 §3.2.3 at
        // dot-atom boundaries ("[CFWS] dot-atom-text [CFWS]") and per
        // §4.4 obs-angle-addr around the angle brackets. Detect the
        // position from subState + lookahead rather than emitting a
        // WhitespaceInAddress error. In multi-address mode with
        // strictMultiWhitespace, this obsolete internal folding is instead
        // rejected per-address (whitespace still separates addresses).
        $cfwsAbsorbed = false;
        if (!$foundComment && $lookAheadChar !== null && !($ctx->multiple && $this->options->strictMultiWhitespace)) {
            if (ParserState::LOCAL_PART === $ctx->subState) {
                if ('@' === $lookAheadChar) {
                    // Trailing CFWS of the local-part dot-atom: "local @domain".
                    $cfwsAbsorbed = true;
                } elseif (
                    $ctx->inAngleAddr
                    && $ctx->localPartParsed === ''
                    && $ctx->addressTemp === ''
                    && $ctx->quoteTemp === ''
                ) {
                    // Leading CFWS inside angle-addr: "<  local@domain>".
                    $cfwsAbsorbed = true;
                }
            } elseif (ParserState::DOMAIN === $ctx->subState) {
                if ($ctx->domain === '' && $ctx->ip === '') {
                    // Leading CFWS of the domain dot-atom: "local@ domain".
                    $cfwsAbsorbed = true;
                }
            } elseif (
                ParserState::START === $ctx->subState
                && '@' === $lookAheadChar
                && $ctx->addressTemp !== ''
            ) {
                // Top-level addr-spec with no angle-addr: "local @domain".
                // The accumulated address_temp IS the local-part; absorb the
                // whitespace as trailing CFWS before the `@`.
                $cfwsAbsorbed = true;
            }
        }

        if ($cfwsAbsorbed) {
            // Silently skip the whitespace character; state unchanged.
        } elseif ($foundComment) {
            if (ParserState::DOMAIN == $ctx->subState) {
                $ctx->subState = ParserState::AFTER_DOMAIN;
            } elseif (ParserState::LOCAL_PART == $ctx->subState) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Email address contains whitespace';
                $ctx->invalidReasonCode = Err::WhitespaceInAddress;
            }
        } elseif (
            $ctx->inAngleAddr
            && ParserState::DOMAIN == $ctx->subState
            && $lookAheadChar === '>'
        ) {
            // Trailing CFWS inside angle-addr before `>`: "<local@domain >".
            // Absorb and transition as if we saw `>` next.
            $ctx->subState = ParserState::AFTER_DOMAIN;
        } elseif (
            $ctx->multiple
            && $lookAheadChar !== null
            && isset($ctx->separators[$lookAheadChar])
            && (ParserState::DOMAIN == $ctx->subState || ParserState::AFTER_DOMAIN == $ctx->subState)
        ) {
            // Whitespace between the domain and a following separator
            // ("a@b.com , c@d.com"): absorb it and let the separator terminate
            // the address, rather than ending here and leaving the separator to
            // open an empty next address (a "misplaced separator" error).
            $ctx->subState = ParserState::AFTER_DOMAIN;
        } elseif ($ctx->useWhitespaceAsSeparator &&
                  (ParserState::DOMAIN == $ctx->subState || ParserState::AFTER_DOMAIN == $ctx->subState)) {
            // Already past `@` and whitespace-as-separator: end address.
            // Single mode has no next address to separate; if the trailing
            // whitespace run contains a whitespace char excluded from the
            // effective set (e.g. CR/LF in strict single mode), that is
            // invalid trailing content — a dangling fold — not a terminator.
            if (!$ctx->multiple) {
                for ($k = $i; $k < $ctx->len && isset(self::WHITESPACE[$ctx->chars[$k]]); ++$k) {
                    if (!isset($ctx->allowedWhitespace[$ctx->chars[$k]])) {
                        $ctx->invalid = true;
                        $ctx->invalidReason = 'Disallowed whitespace after address';
                        $ctx->invalidReasonCode = Err::WhitespaceInAddress;

                        break;
                    }
                }
            }
            $ctx->state = ParserState::END_ADDRESS;

            return true;
        } else {
            if (ParserState::LOCAL_PART == $ctx->subState) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Email address contains whitespace';
                $ctx->invalidReasonCode = Err::WhitespaceInAddress;
            } else {
                // Display-name phrase: absorb into name_parsed.
                $this->handleQuote($ctx);
                $ctx->nameParsed .= $curChar;
            }
        }

        return false;
    }

    /**
     * STATE_ADDRESS '@' handling: reject a misplaced '@', start an obs-route, or
     * flush the accumulated word(s) into the local-part and enter the domain.
     */
    private function handleAddressAt(ParseContext $ctx): void
    {
        if (ParserState::DOMAIN == $ctx->subState) {
            $ctx->invalid = true;
            $ctx->invalidReason = "Multiple at '@' symbols in email address";
            $ctx->invalidReasonCode = Err::MultipleAtSymbols;
        } elseif (ParserState::AFTER_DOMAIN == $ctx->subState) {
            $ctx->invalid = true;
            $ctx->invalidReason = "Stray at '@' symbol found after domain name";
            $ctx->invalidReasonCode = Err::StrayAtAfterDomain;
        } elseif (null !== $ctx->specialCharInSubstate) {
            $ctx->invalid = true;
            $ctx->invalidReason = "Invalid character found in email address local part: '{$ctx->specialCharInSubstate}'";
            $ctx->invalidReasonCode = Err::InvalidCharacterInLocalPart;
        } elseif ($ctx->localAtomSplitByComment) {
            // The `@` confirms this was an addr-spec local part, so the comment
            // that split its atext (RFC 5322 §3.2.3) is invalid here.
            $ctx->invalid = true;
            $ctx->invalidReason = 'A comment cannot appear between characters of an unquoted local part; separate with a dot or quote the local part';
            $ctx->invalidReasonCode = Err::AtextAfterComment;
        } elseif (
            $this->options->allowObsRoute
            && $ctx->inAngleAddr
            && $ctx->obsRoute === ''
            && $ctx->localPartParsed === ''
            && $ctx->quoteTemp === ''
            && $ctx->addressTemp === ''
            // An empty *quoted* local part (`<""@host>`) is a real local
            // part, not the "no local part" that starts an obs-route.
            && !$ctx->localPartQuoted
        ) {
            // RFC 5322 §4.4 obs-route: first `@` seen inside `<...>` with no
            // preceding local-part starts the source-route prefix. Consume
            // the remainder until `:` via STATE_OBS_ROUTE, then resume
            // addr-spec parsing with local-part reset.
            $ctx->state = ParserState::OBS_ROUTE;
            $ctx->obsRoute = '@';
        } else {
            $ctx->subState = ParserState::DOMAIN;
            // A trailing quoted word after earlier words ("x"."y", x."y")
            // is the final word of an obs-local-part (RFC 5322 §3.4.1:
            // word *("." word), word = atom / quoted-string). Flush it onto
            // the accumulated local part, exactly as the dot handler flushes
            // earlier words — not a parser error.
            if ($ctx->addressTemp && $ctx->quoteTemp) {
                $ctx->addressTemp .= $ctx->quoteTemp;
                $ctx->addressTempQuoted = true;
                $ctx->quoteTemp = '';
            }
            if ($ctx->quoteTemp) {
                $ctx->localPartParsed = $ctx->quoteTemp;
                $ctx->quoteTemp = '';
                $ctx->localPartQuoted = true;
            } elseif ($ctx->addressTemp) {
                $ctx->localPartParsed = $ctx->addressTemp;
                $ctx->addressTemp = '';
                $ctx->localPartQuoted = $ctx->addressTempQuoted;
                $ctx->addressTempQuoted = false;
                $ctx->addressTempPeriod = 0;
            }
        }
    }

    /**
     * STATE_ADDRESS non-atext handling — UTF-8 domain/local-part characters
     * (punycode-tested for the domain) plus rejection of other stray bytes.
     */
    private function handleAddressNonAtext(ParseContext $ctx, string $curChar): void
    {
        if (ParserState::DOMAIN == $ctx->subState) {
            if ($this->isUtf8Char($curChar)) {
                $ctx->domain .= $curChar;
            } else {
                try {
                    // Test by trying to encode the current character into Punycode
                    // Punycode should match the traditional domain name subset of characters
                    $punycoded = idn_to_ascii($curChar);
                    if ($punycoded !== false && preg_match('/[a-z0-9\-]/', $punycoded)) {
                        $ctx->domain .= $curChar;
                    } else {
                        $ctx->invalid = true;
                    }
                } catch (\Exception $e) {
                    $this->log('warning', "Email\\Parse->parse - exception trying to convert character '{$curChar}' to punycode\n\$ctx->originalAddress: {$ctx->originalAddress}\n\$emails: {$ctx->emails}");
                    $ctx->invalid = true;
                }
                if ($ctx->invalid) {
                    $ctx->invalidReason = "Invalid character found in domain of email address (please put in quotes if needed): '{$curChar}'";
                    $ctx->invalidReasonCode = Err::InvalidCharacterInDomain;
                }
            }
        } elseif (ParserState::START === $ctx->subState || ParserState::LOCAL_PART === $ctx->subState) {
            // Handle non-atext characters in both STATE_START and STATE_LOCAL_PART consistently
            if ($ctx->subState === ParserState::START && $ctx->quoteTemp) {
                $ctx->addressTemp .= $ctx->quoteTemp;
                $ctx->addressTempQuoted = true;
                $ctx->quoteTemp = '';
            } elseif ($ctx->subState === ParserState::LOCAL_PART && $ctx->quoteTemp) {
                $ctx->localPartParsed .= $ctx->quoteTemp;
                $ctx->quoteTemp = '';
                $ctx->localPartQuoted = true;
            }

            $isUtf8 = $this->isUtf8Char($curChar);

            if ($isUtf8 && $this->options->allowUtf8LocalPart) {
                // UTF-8 character allowed
                if ($ctx->subState === ParserState::START) {
                    $ctx->addressTemp .= $curChar;
                } else {
                    $ctx->localPartParsed .= $curChar;
                }
            } elseif ($isUtf8) {
                // UTF-8 present but not allowed by rules — collect and reject in validateLocalPart()
                if ($ctx->subState === ParserState::START) {
                    $ctx->addressTemp .= $curChar;
                    // ??= preserves the first invalid character seen; later chars must not overwrite it
                    $ctx->specialCharInSubstate ??= $curChar;
                } else {
                    $ctx->invalid = true;
                    $ctx->invalidReason = "Invalid character found in email address local part: '{$curChar}'";
                    $ctx->invalidReasonCode = Err::InvalidCharacterInLocalPart;
                }
            } else {
                // Non-UTF-8, non-atext character
                if ($ctx->subState === ParserState::START) {
                    // ??= preserves the first invalid character seen; later chars must not overwrite it
                    $ctx->specialCharInSubstate ??= $curChar;
                    $ctx->addressTemp .= $curChar;
                } else {
                    $ctx->invalid = true;
                    $ctx->invalidReason = "Invalid character found in email address local part: '{$curChar}'";
                    $ctx->invalidReasonCode = Err::InvalidCharacterInLocalPart;
                }
            }
        } elseif (ParserState::NAME === $ctx->subState) {
            if ($ctx->quoteTemp) {
                $ctx->nameParsed .= $ctx->quoteTemp;
                $ctx->quoteTemp = '';
                $ctx->nameQuoted = true;
            }
            $ctx->specialCharInSubstate = $curChar;
            $ctx->nameParsed .= $curChar;
        } else {
            $ctx->invalid = true;
            $ctx->invalidReason = "Invalid character found in email address (please put in quotes if needed): '{$curChar}'";
            $ctx->invalidReasonCode = Err::InvalidCharacterInAddress;
        }
    }

    /**
     * STATE_SQUARE_BRACKET: accumulate a domain-literal IP until the closing ']'.
     */
    private function handleStateSquareBracket(ParseContext $ctx, string $curChar): void
    {
        $ctx->originalAddress .= $curChar;
        if (']' == $curChar) {
            $ctx->subState = ParserState::AFTER_DOMAIN;
            $ctx->state = ParserState::ADDRESS;
        } else {
            $ctx->ip .= $curChar;
        }
    }

    /**
     * STATE_OBS_ROUTE (RFC 5322 §4.4): consume the `@host1,@host2:` source-route
     * prefix inside angle-addr. On `:` resume addr-spec parsing; an unterminated
     * route (`>` or end of input before `:`) is invalid.
     */
    private function handleStateObsRoute(ParseContext $ctx, string $curChar): void
    {
        $ctx->originalAddress .= $curChar;
        if (':' == $curChar) {
            $ctx->state = ParserState::ADDRESS;
            $ctx->subState = ParserState::LOCAL_PART;
        } elseif ('>' == $curChar) {
            // `<@host>` without a colon — incomplete obs-route.
            $ctx->invalid = true;
            $ctx->invalidReason = 'Incomplete obs-route: missing colon before closing angle-bracket';
            $ctx->invalidReasonCode = Err::IncompleteAddress;
            $ctx->inAngleAddr = false;
            $ctx->state = ParserState::ADDRESS;
            $ctx->subState = ParserState::AFTER_DOMAIN;
        } else {
            $ctx->obsRoute .= $curChar;
        }
    }

    /**
     * STATE_QUOTE: accumulate a quoted-string, honouring backslash escapes and
     * rejecting bare C0 controls, until the real closing quote returns to
     * STATE_ADDRESS.
     */
    private function handleStateQuote(ParseContext $ctx, string $curChar, int $i): void
    {
        $ctx->originalAddress .= $curChar;
        if ('"' == $curChar) {
            // RFC 5322 §3.2.4 / RFC 5321 §4.1.2: detect escaped quote by counting
            // consecutive backslashes immediately before this position. An odd count
            // means the quote is escaped (e.g. \" or \\\"); even count (incl. zero)
            // means it is the real closing delimiter.
            $backslashCount = 0;
            for ($j = $i - 1; $j >= 0; --$j) {
                if ('\\' == $ctx->chars[$j]) {
                    ++$backslashCount;
                } else {
                    break;
                }
            }
            if ($backslashCount && 1 == $backslashCount % 2) {
                // Odd number of backslashes = this quote is escaped
                $ctx->quoteTemp .= $curChar;
            } else {
                // Even backslashes (or zero) = this is the real closing quote.
                // Record that a quote was seen so an *empty* quoted local-part
                // (`""@domain`) is still recognised as quoted — quote_temp is
                // empty in that case, so the '@' handler below can't tell. A
                // display-name quote self-corrects: the real local-part resets
                // this flag from address_temp_quoted when '@' is reached.
                $ctx->state = ParserState::ADDRESS;
                $ctx->localPartQuoted = true;
                $ctx->afterClosingQuote = true;
            }
        } elseif ($this->options->rejectC0Controls && 1 === strlen($curChar) && "\t" !== $curChar && (ord($curChar) < 32 || "\x7f" === $curChar)) {
            // qtext (RFC 5322 §3.2.4) excludes C0 controls; a bare CR or LF
            // inside a quoted-string is not valid (only a CRLF fold with WSP is).
            $ctx->invalid = true;
            $ctx->invalidReason = 'Control character in quoted string';
            $ctx->invalidReasonCode = Err::InvalidCharInQuotedString;
        } else {
            $ctx->quoteTemp .= $curChar;
        }
    }

    /**
     * STATE_COMMENT (RFC 5322 §3.2.2): accumulate comment text, tracking nesting
     * and quoted-pairs, and on close flag a comment that split a local-part atom.
     */
    private function handleStateComment(ParseContext $ctx, string $curChar): void
    {
        $ctx->originalAddress .= $curChar;
        if ($ctx->commentEscaped) {
            // Target of a quoted-pair — literal, never structural.
            $ctx->commentEscaped = false;
            $ctx->commentTemp .= $curChar;
        } elseif ('\\' == $curChar) {
            // RFC 5322 §3.2.1: backslash starts a quoted-pair; the next
            // character is escaped (so "\)" does not close the comment).
            $ctx->commentEscaped = true;
        } elseif (')' == $curChar) {
            --$ctx->commentNestLevel;
            if ($ctx->commentNestLevel <= 0) {
                // End of comment - save it
                if ($ctx->commentTemp) {
                    $ctx->comments[] = $ctx->commentTemp;
                    $ctx->commentTemp = '';
                }
                $ctx->state = ParserState::ADDRESS;
                // Flag a comment that closed mid-word in the local part (before
                // `@`), so a token resuming the word can be rejected. Covers a
                // preceding atext run (address_temp/local_part_parsed) or a
                // preceding quoted-string (local_part_quoted) — "x"(c)y is as
                // invalid as x(c)y. Domain and display-name comments are excluded.
                if ((ParserState::LOCAL_PART === $ctx->subState || ParserState::START === $ctx->subState)
                    && ('' !== $ctx->addressTemp || '' !== $ctx->localPartParsed || $ctx->localPartQuoted)) {
                    $ctx->commentAfterLocalAtext = true;
                }
            } else {
                // Nested comment closing parenthesis
                $ctx->commentTemp .= $curChar;
            }
        } elseif ('(' == $curChar) {
            ++$ctx->commentNestLevel;
            if ($ctx->commentNestLevel > 1) {
                // Nested comment opening parenthesis
                $ctx->commentTemp .= $curChar;
            }
        } elseif ($this->options->rejectC0Controls && 1 === strlen($curChar) && "\t" !== $curChar && (ord($curChar) < 32 || "\x7f" === $curChar)) {
            // ctext (RFC 5322 §3.2.3) excludes C0 controls; a bare CR or LF
            // inside a comment is not part of valid folding.
            $ctx->invalid = true;
            $ctx->invalidReason = 'Control character in comment';
            $ctx->invalidReasonCode = Err::ControlCharInComment;
        } elseif ($this->options->rejectC1Controls && preg_match('/[\x{0080}-\x{009F}]/u', $curChar)) {
            // RFC 6532 §3.1: C1 controls (2-byte UTF-8) are prohibited in
            // internationalized content, comments included.
            $ctx->invalid = true;
            $ctx->invalidReason = 'Control character in comment';
            $ctx->invalidReasonCode = Err::ControlCharInComment;
        } else {
            // Regular comment character
            $ctx->commentTemp .= $curChar;
        }
    }

    /**
     * Resolves a pending quoted or temp buffer into the display name.
     *
     * Called when a display name is followed by an angle-addr (<local@domain>).
     * Periods in an unquoted name are invalid per RFC 5322 §3.4 — the display
     * name must be a phrase, and a period is not an atext character.
     */
    private function handleQuote(ParseContext $ctx): void
    {
        if ($ctx->quoteTemp) {
            $ctx->nameParsed .= $ctx->quoteTemp;
            $ctx->nameQuoted = true;
            $ctx->quoteTemp = '';
        } elseif ($ctx->addressTemp) {
            $ctx->nameParsed .= $ctx->addressTemp;
            $ctx->nameQuoted = $ctx->addressTempQuoted;
            $ctx->addressTempQuoted = false;
            $ctx->addressTemp = '';
            if ($ctx->addressTempPeriod > 0) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Periods within the display name of an email address must appear in quotes, such as "John Q. Public" <john@qpublic.com> according to RFC 5322';
                $ctx->invalidReasonCode = Err::UnquotedPeriodInDisplayName;
            }
        }
    }

    /**
     * Validates the accumulated email address parts and appends the result to $emailAddresses.
     *
     * Runs post-parse validation: IP address range checks, domain punycode conversion,
     * domain name format validation (RFC 5321 §4.1.2, RFC 1035 §2.3.4), local-part
     * content validation, FQDN requirement, and length limits (RFC 5321 §4.5.3.1).
     *
     * @param array<int, array<string, mixed>> $emailAddresses Result list the parsed address is appended to
     *
     * @return bool True if the address was invalid, false if it was valid
     */
    private function addAddress(
        array &$emailAddresses,
        ParseContext $ctx,
        int $i
    ): bool {
        if (!$ctx->invalid) {
            if (filter_var($ctx->domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ||
                str_starts_with($ctx->domain, 'IPv6:') ||
                preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ctx->domain)) {
                $ctx->ip = $ctx->domain;
                $ctx->domain = '';
            }
            if ($ctx->addressTemp || $ctx->quoteTemp) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Incomplete address';
                $ctx->invalidReasonCode = Err::IncompleteAddress;
                $this->log('error', "Email\\Parse->addAddress - corruption during parsing - leftovers:\n\$i: {$i}\n\$ctx->addressTemp : {$ctx->addressTemp}\n\$ctx->quoteTemp: {$ctx->quoteTemp}\n");
            } elseif ($ctx->ip && $ctx->domain) {
                // Error - this should never occur
                $ctx->invalid = true;
                $ctx->invalidReason = 'Confusion during parsing';
                $ctx->invalidReasonCode = Err::ParserConfusion;
                $this->log('error', "Email\\Parse->addAddress - both an IP address '{$ctx->ip}' and a domain '{$ctx->domain}' found for the email address '{$ctx->originalAddress}'\n");
            } elseif ($ctx->ip) {
                if (filter_var($ctx->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    if ($this->options->validateIpGlobalRange && !$this->validateIpGlobalRange($ctx->ip, FILTER_FLAG_IPV4)) {
                        $ctx->invalid = true;
                        $ctx->invalidReason = 'IP address invalid: \'' . $ctx->ip . '\' does not appear to be a valid IP address in the global range';
                        $ctx->invalidReasonCode = Err::IpNotInGlobalRange;
                    }
                } elseif (str_starts_with($ctx->ip, 'IPv6:')) {
                    $tempIp = str_replace('IPv6:', '', $ctx->ip);
                    if (filter_var($tempIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        if ($this->options->validateIpGlobalRange && !$this->validateIpGlobalRange($tempIp, FILTER_FLAG_IPV6)) {
                            $ctx->invalid = true;
                            $ctx->invalidReason = 'IP address invalid: \'' . $ctx->ip . '\' does not appear to be a valid IPv6 address in the global range';
                            $ctx->invalidReasonCode = Err::Ipv6NotInGlobalRange;
                        }
                    } else {
                        $ctx->invalid = true;
                        $ctx->invalidReason = 'IP address invalid: \'' . $ctx->ip . '\' does not appear to be a valid IP address';
                        $ctx->invalidReasonCode = Err::InvalidIpAddress;
                    }
                } else {
                    $ctx->invalid = true;
                    $ctx->invalidReason = 'IP address invalid: \'' . $ctx->ip . '\' does not appear to be a valid IP address';
                    $ctx->invalidReasonCode = Err::InvalidIpAddress;
                }
            } elseif ($ctx->domain) {
                // Optional FQDN root-label dot (RFC 5321 §2.3.5 allows "example.com.").
                // Accepted and stripped by default; rejected when rejectTrailingDot is set.
                if (str_ends_with($ctx->domain, '.')) {
                    if ($this->options->rejectTrailingDot) {
                        $ctx->invalid = true;
                        $ctx->invalidReason = 'Domain must not end with a trailing dot';
                        $ctx->invalidReasonCode = Err::TrailingDotNotAllowed;
                    } else {
                        $ctx->domain = substr($ctx->domain, 0, -1);
                    }
                }
            }
            if (!$ctx->invalid && $ctx->domain) {
                // NFC-normalize internationalized domain before punycode conversion
                // RFC 6531 §3.3 / RFC 5891 §5.2: U-labels must be in NFC before IDNA processing
                if ($this->options->applyNfcNormalization) {
                    $nfc = $this->normalizeUtf8($ctx->domain);
                    if ($nfc !== false) {
                        $ctx->domain = $nfc;
                    }
                }

                $domainAscii = $this->normalizeDomainAscii($ctx->domain);
                if ($domainAscii === null) {
                    $ctx->invalid = true;
                    $ctx->invalidReason = "Can't convert domain {$ctx->domain} to punycode";
                    $ctx->invalidReasonCode = Err::PunycodeConversionFailed;
                } else {
                    if ($domainAscii !== $ctx->domain) {
                        $ctx->domainAscii = $domainAscii;
                    }
                    $result = $this->validateDomainName($domainAscii);
                    if (!$result['valid']) {
                        $ctx->invalid = true;
                        $ctx->invalidReason = isset($result['reason']) ? 'Domain invalid: '.$result['reason'] : 'Domain invalid for some unknown reason';
                        $ctx->invalidReasonCode = $result['code'] ?? Err::DomainInvalid;
                    }
                }
            }
        }

        // Prepare some of the fields needed
        $ctx->nameParsed = rtrim($ctx->nameParsed);
        $ctx->originalAddress = rtrim($ctx->originalAddress);
        $name = $ctx->nameQuoted ? "\"{$ctx->nameParsed}\"" : $ctx->nameParsed;
        $localPart = $ctx->localPartQuoted ? "\"{$ctx->localPartParsed}\"" : $ctx->localPartParsed;
        $domainPart = $ctx->ip ? '['.$ctx->ip.']' : $ctx->domain;

        if (!$ctx->invalid) {
            if (0 == strlen($domainPart)) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Email address needs a domain after the \'@\'';
                $ctx->invalidReasonCode = Err::MissingDomain;
            }
        }

        // RFC 5322 §3.2.5 phrase validation for unquoted display names.
        // A phrase is 1*word where each word is an atom (atext + CFWS) or quoted-string.
        // Quoted display names are already phrase-valid; an unquoted name must contain
        // only atext characters and whitespace. The parser's state machine already
        // catches unquoted periods (UnquotedPeriodInDisplayName); this check adds
        // rejection of non-atext bytes such as stray UTF-8 in an unquoted name.
        if (!$ctx->invalid
            && $this->options->validateDisplayNamePhrase
            && !$ctx->nameQuoted
            && $ctx->nameParsed !== ''
            && !preg_match('#^[A-Za-z0-9!\#$%&\'*+\-/=?^_`{|}~ \t]+$#', $ctx->nameParsed)
        ) {
            $ctx->invalid = true;
            $ctx->invalidReason = "Display name '{$ctx->nameParsed}' must be a quoted-string or atext-only phrase per RFC 5322 §3.2.5";
            $ctx->invalidReasonCode = Err::InvalidDisplayNamePhrase;
        }

        // Unified local-part validation.
        if (!$ctx->invalid) {
            $result = $this->validateLocalPart($ctx);
            if (!$result['valid']) {
                $ctx->invalid = true;
                $ctx->invalidReason = $result['reason'];
                $ctx->invalidReasonCode = $result['code'] ?? null;
            } elseif ($result['normalized'] !== null) {
                // Apply NFC normalization result to the parsed local-part and re-derive display form
                $ctx->localPartParsed = $result['normalized'];
                $localPart = $ctx->localPartQuoted
                    ? "\"{$ctx->localPartParsed}\""
                    : $ctx->localPartParsed;
            }

            // Optional caller-supplied local-part normalizer — invoked after structural
            // validation so the callback only sees addresses that already conform to
            // the configured ParseOptions rules. Typical uses: Gmail dot-insensitivity
            // (`john.doe` → `johndoe`), plus-addressing (`user+tag` → `user`), or any
            // domain-specific canonicalization. The returned string replaces
            // local_part_parsed and the display form is re-derived; `original_address`
            // still preserves the verbatim input.
            if (!$ctx->invalid && $this->options->localPartNormalizer !== null) {
                $normalizer = $this->options->localPartNormalizer;
                $normalized = $normalizer($ctx->localPartParsed, $ctx->domain);
                if ($normalized !== $ctx->localPartParsed) {
                    $ctx->localPartParsed = $normalized;
                    $localPart = $ctx->localPartQuoted
                        ? "\"{$ctx->localPartParsed}\""
                        : $ctx->localPartParsed;
                }
            }
        }

        // FQDN check
        if (!$ctx->invalid && $this->options->requireFqdn && $ctx->domain) {
            $dotPos = strpos($ctx->domain, '.');
            if ($dotPos === false || $dotPos === 0 || $dotPos === strlen($ctx->domain) - 1) {
                $ctx->invalid = true;
                $ctx->invalidReason = 'Domain must be a fully-qualified domain name';
                $ctx->invalidReasonCode = Err::FqdnRequired;
            }
        }

        // RFC 5321 §4.5.3.1: all limits are in octets (bytes), not characters.
        // For quoted local-parts the wire form adds 2 DQUOTE bytes to the length.
        if (!$ctx->invalid && $this->options->enforceLengthLimits) {
            $limits = $this->options->getLengthLimits();
            // RFC 5321 §4.5.3.1.1: local-part max 64 octets (wire form includes DQUOTE for quoted strings)
            $localPartWireLen = $ctx->localPartQuoted
                ? strlen($ctx->localPartParsed) + 2
                : strlen($ctx->localPartParsed);

            if ($localPartWireLen > $limits->maxLocalPartLength) {
                $ctx->invalid = true;
                $ctx->invalidReason = "Email address before the '@' can not be greater than {$limits->maxLocalPartLength} octets per RFC 5321";
                $ctx->invalidReasonCode = Err::LocalPartTooLong;
            } elseif (($localPartWireLen + 1 + strlen($domainPart)) > $limits->maxTotalLength) {
                $ctx->invalid = true;
                $ctx->invalidReason = "Email addresses can not be greater than {$limits->maxTotalLength} octets per RFC 3696 EID 1690";
                $ctx->invalidReasonCode = Err::TotalLengthExceeded;
            }
        }

        // Build the email address hash
        $emailAddrDef = ['address' => '',
                        'simple_address' => '',
                        'original_address' => rtrim($ctx->originalAddress),
                        'name' => $name,
                        'name_parsed' => $ctx->nameParsed,
                        'local_part' => $localPart,
                        'local_part_parsed' => $ctx->localPartParsed,
                        'domain_part' => $domainPart,
                        'domain' => $ctx->domain,
                        'domain_ascii' => $this->options->includeDomainAscii ? ($ctx->domainAscii ?? null) : null,
                        'ip' => $ctx->ip,
                        'invalid' => $ctx->invalid,
                        'invalid_reason' => $ctx->invalidReason,
                        'invalid_reason_code' => $ctx->invalidReasonCode,
                        'comments' => $ctx->comments,
                        'obs_route' => $ctx->obsRoute !== '' ? $ctx->obsRoute : null,
                        'domain_is_suspicious' => $this->isDomainConfusable($ctx->domain), ];

        // Build the proper address by hand (has comments stripped out and should have quotes in the proper places)
        if (!$emailAddrDef['invalid']) {
            $emailAddrDef['simple_address'] = "{$emailAddrDef['local_part']}@{$emailAddrDef['domain_part']}";
            $properAddress = $emailAddrDef['name'] ? "{$emailAddrDef['name']} <{$emailAddrDef['local_part']}@{$emailAddrDef['domain_part']}>" : $emailAddrDef['simple_address'];
            $emailAddrDef['address'] = $properAddress;
        }

        $emailAddresses[] = $emailAddrDef;

        return $emailAddrDef['invalid'];
    }

    /**
     * Returns true if the character is a non-ASCII byte (multi-byte UTF-8 code point).
     * The first byte of any multi-byte UTF-8 sequence is always >= 0x80.
     */
    protected function isUtf8Char(string $char): bool
    {
        return ord($char[0]) > 127;
    }

    /**
     * Whether a domain looks like a mixed-script / confusable (homograph) spoof —
     * e.g. `аpple.com` with a Cyrillic `а`. Uses the intl Spoofchecker's default
     * checks (which flag mixed-script and confusable strings) on the Unicode
     * (U-label) domain. Off unless ParseOptions::$detectConfusableDomain is set;
     * a legitimate single-script international domain (e.g. `почта.рф`) is not
     * flagged. This is a security-policy signal, not an RFC validity check.
     */
    private function isDomainConfusable(string $domain): bool
    {
        if ($domain === '' || !$this->options->detectConfusableDomain || !class_exists('Spoofchecker')) {
            return false;
        }

        if ($this->spoofchecker === null) {
            $this->spoofchecker = new \Spoofchecker();
        }

        return $this->spoofchecker->isSuspicious($domain);
    }

    /**
     * Unified local-part validation based on ParseOptions rule properties.
     *
     * @return array{valid: bool, reason: ?string, code: ?ParseErrorCode, normalized: ?string}
     */
    private function validateLocalPart(ParseContext $ctx): array
    {
        $opts = $this->options;
        $localPart = $ctx->localPartParsed;
        $quoted = $ctx->localPartQuoted;

        // RFC 6531 §3.3 / RFC 6532 §3.2: gate UTF-8 presence before other checks
        // (allowUtf8LocalPart is false in rfc5321() and rfc5322() presets)
        $hasUtf8 = (bool) preg_match('/[^\x00-\x7F]/', $localPart);
        if ($hasUtf8 && !$opts->allowUtf8LocalPart) {
            return ['valid' => false, 'reason' => 'UTF-8 characters not allowed in local part', 'code' => Err::Utf8NotAllowedInLocalPart, 'normalized' => null];
        }

        // Quoted-string content validation (RFC 5321 §4.1.2 qtextSMTP, RFC 5322 §3.2.4 qtext)
        if ($quoted) {
            if ($opts->rejectEmptyQuotedLocalPart && $localPart === '') {
                return ['valid' => false, 'reason' => 'Empty quoted local part not allowed', 'code' => Err::EmptyQuotedLocalPart, 'normalized' => null];
            }

            if ($opts->validateQuotedContent) {
                $len = strlen($localPart);
                for ($i = 0; $i < $len; $i++) {
                    $byte = ord($localPart[$i]);

                    if ($localPart[$i] === '\\') {
                        // quoted-pair: must be followed by a valid character
                        if ($i + 1 >= $len) {
                            return ['valid' => false, 'reason' => 'Trailing backslash in quoted string', 'code' => Err::TrailingBackslashInQuotedString, 'normalized' => null];
                        }
                        $nextByte = ord($localPart[$i + 1]);
                        // RFC 5321 §4.1.2 quoted-pairSMTP: backslash followed by %d32-126
                        if ($nextByte < 32 || $nextByte > 126) {
                            return ['valid' => false, 'reason' => 'Invalid escaped character in quoted string', 'code' => Err::InvalidEscapedCharInQuotedString, 'normalized' => null];
                        }
                        // Skip both the backslash and its escape target; they form one
                        // quoted-pair and must not be re-checked against qtextSMTP below
                        // (which would otherwise reject the backslash as byte 92).
                        ++$i;

                        continue;
                    }

                    // UTF-8 multibyte in quoted string (internationalized)
                    if ($opts->allowUtf8LocalPart && $byte > 127) {
                        continue;
                    }

                    // qtextSMTP: %d32-33 / %d35-91 / %d93-126
                    // Reject: NUL, C0 controls, DQUOTE(%d34), backslash(%d92), DEL(%d127+)
                    if ($byte <= 31 || $byte == 34 || $byte == 92 || $byte >= 127) {
                        return ['valid' => false, 'reason' => 'Invalid character in quoted string: byte ' . $byte, 'code' => Err::InvalidCharInQuotedString, 'normalized' => null];
                    }
                }

                // C1 control check for internationalized quoted content
                if ($opts->rejectC1Controls && preg_match('/[\x{0080}-\x{009F}]/u', $localPart)) {
                    return ['valid' => false, 'reason' => 'C1 control character in quoted string', 'code' => Err::C1ControlInQuotedString, 'normalized' => null];
                }
            }

            return ['valid' => true, 'reason' => null, 'code' => null, 'normalized' => null];
        }

        // Unquoted local part validation

        // RFC 5321 §4.1.2: atext and qtextSMTP both exclude C0 control characters.
        // RFC 6530 §10.1: C1 control characters (U+0080-U+009F) are also prohibited
        // in internationalized email addresses (they are valid UTF-8 but meaningless).
        if ($opts->rejectC0Controls && preg_match('/[\x00-\x1F]/', $localPart)) {
            return ['valid' => false, 'reason' => 'C0 control character in local part', 'code' => Err::C0ControlInLocalPart, 'normalized' => null];
        }
        if ($opts->rejectC1Controls && preg_match('/[\x{0080}-\x{009F}]/u', $localPart)) {
            return ['valid' => false, 'reason' => 'C1 control character in local part', 'code' => Err::C1ControlInLocalPart, 'normalized' => null];
        }

        // NFC normalization: apply and return normalized form for caller to store
        $normalizedLocalPart = null;
        if ($opts->applyNfcNormalization) {
            $nfc = $this->normalizeUtf8($localPart);
            if ($nfc === false) {
                return ['valid' => false, 'reason' => 'Local part cannot be NFC normalized', 'code' => Err::LocalPartCannotBeNormalized, 'normalized' => null];
            }
            if ($nfc !== $localPart) {
                $normalizedLocalPart = $nfc;
                $localPart = $nfc;
            }
        }

        // UTF-8 encoding validation
        if ($hasUtf8 && !mb_check_encoding($localPart, 'UTF-8')) {
            return ['valid' => false, 'reason' => 'Invalid UTF-8 encoding in local part', 'code' => Err::InvalidUtf8Encoding, 'normalized' => null];
        }

        // Build the validation pattern for unquoted local-parts.
        // atext (RFC 5322 §3.2.3): A-Z a-z 0-9 ! # $ % & ' * + - / = ? ^ _ ` { | } ~
        // RFC 6531 §3.3 extends atext with Unicode letters and digits (\p{L}\p{N}).
        if ($opts->allowUtf8LocalPart) {
            $dotAtomPattern = "/^[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~\\p{L}\\p{N}]+(?:\\.[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~\\p{L}\\p{N}]+)*$/u";
        } else {
            $dotAtomPattern = "/^[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~]+(?:\\.[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~]+)*$/";
        }

        if ($opts->allowObsLocalPart) {
            // obs-local-part (RFC 5322 §4.4): dots permitted anywhere — leading, trailing, consecutive
            $pattern = $opts->allowUtf8LocalPart
                ? "/^[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~.\\p{L}\\p{N}]+$/u"
                : "/^[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~.]+$/";
        } elseif ($opts->rejectC0Controls) {
            // dot-atom-text (RFC 5322 §3.2.3): 1*atext *("." 1*atext) — no leading, trailing, or consecutive dots
            $pattern = $dotAtomPattern;
        } else {
            // Legacy/non-strict: the state machine already rejects leading/consecutive dots;
            // trailing dots are permitted here for backward compatibility with v2.x.
            if ($opts->allowUtf8LocalPart) {
                $pattern = "/^[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~\\p{L}\\p{N}]+(?:\\.[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~\\p{L}\\p{N}]+)*\\.?$/u";
            } else {
                $pattern = "/^[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~]+(?:\\.[A-Za-z0-9!#$%&'*+\\-\\/=?^_`{|}~]+)*\\.?$/";
            }
        }

        if (!preg_match($pattern, $localPart)) {
            return ['valid' => false, 'reason' => 'Local part contains invalid characters', 'code' => Err::LocalPartContainsInvalidChars, 'normalized' => null];
        }

        return ['valid' => true, 'reason' => null, 'code' => null, 'normalized' => $normalizedLocalPart];
    }

    /**
     * Normalize a UTF-8 string using NFC normalization form.
     * RFC 6532 §3.1 recommends NFC normalization for internationalized email addresses.
     *
     * @param string $str The string to normalize
     * @return string|false The normalized string, or false on failure
     */
    protected function normalizeUtf8(string $str): string|false
    {
        if (!function_exists('normalizer_normalize')) {
            // Intl extension not available, return as-is
            return $str;
        }

        $normalized = \Normalizer::normalize($str, \Normalizer::NFC);

        return $normalized === false ? false : $normalized;
    }

    /**
     * Convert domain to ASCII (punycode/A-label) form via IDNA UTS#46 (RFC 5891/5892).
     *
     * Returns the domain unchanged if it is already pure ASCII. Returns null if
     * conversion fails (caller should reject the address).
     */
    protected function normalizeDomainAscii(string $domain): ?string
    {
        if ($domain === '' || !preg_match('/[^\x00-\x7F]/', $domain)) {
            return $domain;
        }

        // When `strictIdna` is enabled, apply full IDNA2008 conformance:
        //   - USE_STD3_RULES: reject labels containing characters outside LDH (RFC 5891 §4.4).
        //   - CHECK_BIDI: enforce the Bidi rule for labels with RTL characters (RFC 5893).
        //   - CHECK_CONTEXTJ: enforce CONTEXTJ rules for U+200C / U+200D (RFC 5892 Appendix A).
        //   - NONTRANSITIONAL_TO_ASCII: treat IDNA2008 deviations (ß, ς, etc.) literally
        //     instead of the IDNA2003 mapping — required for full RFC 5891 compliance.
        // Without strictIdna we retain the permissive UTS#46 default for backward compatibility.
        $flags = $this->options->strictIdna
            ? IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII
            : IDNA_DEFAULT;

        $idnaInfo = [];
        $ascii = idn_to_ascii($domain, $flags, INTL_IDNA_VARIANT_UTS46, $idnaInfo);

        if ($ascii === false) {
            return null;
        }

        // Under strictIdna, idn_to_ascii() may still return a string while reporting
        // errors in $idnaInfo['errors']. Treat any reported error as a conversion failure.
        if ($this->options->strictIdna && ($idnaInfo['errors'] ?? 0) !== 0) {
            return null;
        }

        return $ascii;
    }

    /**
     * Validates the ASCII (punycode) form of a domain name.
     *
     * Enforces RFC 5321 §4.1.2 + RFC 1035 §2.3.4 domain label rules:
     *   - Max 255 octets total (RFC 5321 §4.5.3.1.2)
     *   - Each label at most maxDomainLabelLength octets (RFC 1035 §2.3.4: 63)
     *   - Labels contain only [A-Za-z0-9-] (letters, digits, hyphen)
     *   - Labels may not start or end with a hyphen (RFC 1035 §2.3.4)
     *   - RFC 1123 §2.1 relaxed the original restriction that allowed labels starting
     *     with a letter only, permitting labels that start with a digit.
     *
     * @param string $domain The ASCII domain name to validate (after punycode conversion)
     *
     * @return array{valid: bool, reason?: string, code?: ParseErrorCode}
     */
    private function validateDomainName(string $domain): array
    {
        // RFC 5321 §4.5.3.1.2: total domain length limit is in octets
        if (strlen($domain) > 255) {
            return ['valid' => false, 'reason' => 'Domain name too long', 'code' => Err::DomainTooLong];
        } else {
            // $domain is always ASCII here (post-punycode, via normalizeDomainAscii),
            // so a plain explode on the label separator is sufficient. This avoids
            // mb_regex_encoding(), deprecated since PHP 8.6 (the underlying oniguruma
            // library is no longer maintained). See GitHub issue #57.
            // Labels are guaranteed non-empty: the state machine rejects consecutive
            // and edge dots (ConsecutiveDots) before the domain validator runs.
            $parts = explode('.', $domain);
            $maxLabelLen = $this->options->getLengthLimits()->maxDomainLabelLength;
            foreach ($parts as $part) {
                if (strlen($part) > $maxLabelLen) {
                    return ['valid' => false, 'reason' => "Domain name part '{$part}' must be less than {$maxLabelLen} octets", 'code' => Err::DomainLabelTooLong];
                }
                if (!preg_match('/^[a-zA-Z0-9\-]+$/', $part)) {
                    return ['valid' => false, 'reason' => "Domain name '{$domain}' can only contain letters a through z, numbers 0 through 9 and hyphen.  The part '{$part}' contains characters outside of that range.", 'code' => Err::DomainContainsInvalidChars];
                }
                if ('-' == substr($part, 0, 1) || '-' == substr($part, -1)) {
                    return ['valid' => false, 'reason' => "Parts of the domain name '{$domain}' can not start or end with '-'.  This part does: {$part}", 'code' => Err::DomainLabelStartsOrEndsWithHyphen];
                }
            }
        }

        return ['valid' => true];
    }
}
