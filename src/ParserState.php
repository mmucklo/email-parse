<?php

namespace Email;

/**
 * States of the {@see Parse} character-by-character state machine.
 *
 * A backed int enum replacing the former `Parse::STATE_*` class constants:
 * it gives `ParseContext::$state` / `$subState` a real type instead of a bare
 * int, so the parser can never hold an out-of-range state. Values match the
 * historical constants and are stable.
 *
 * @internal Implementation detail of {@see Parse}.
 */
enum ParserState: int
{
    case TRIM = 0;
    case QUOTE = 1;
    case ADDRESS = 2;
    case COMMENT = 3;
    case NAME = 4;
    case LOCAL_PART = 5;
    case DOMAIN = 6;
    case AFTER_DOMAIN = 7;
    case SQUARE_BRACKET = 8;
    case SKIP_AHEAD = 9;
    case END_ADDRESS = 10;
    case START = 11;

    /**
     * Absorbs the obsolete source-route prefix inside angle-addr
     * (RFC 5322 §4.4 obs-route: `"<" obs-domain-list ":" addr-spec ">"`).
     * Consumes characters from the leading `@` up to the `:` terminator,
     * then resumes normal addr-spec parsing.
     */
    case OBS_ROUTE = 12;
}
