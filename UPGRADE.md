# Upgrade Guide

## v2.x → v3.0

v3.0 introduces configurable RFC compliance presets, immutable length limits, and stricter validation rules. The default behavior of `new ParseOptions()` is preserved for backward compatibility, but a few public APIs have been removed or renamed. This guide lists every observable change.

### Breaking Changes

#### 1. `LengthLimits` getters and setters removed

The six getter/setter methods on `LengthLimits` were removed when the class was refactored to an immutable value object using PHP 8.1 readonly constructor promotion.

**Removed methods:**
- `LengthLimits::getMaxLocalPartLength(): int`
- `LengthLimits::setMaxLocalPartLength(int): void`
- `LengthLimits::getMaxTotalLength(): int`
- `LengthLimits::setMaxTotalLength(int): void`
- `LengthLimits::getMaxDomainLabelLength(): int`
- `LengthLimits::setMaxDomainLabelLength(int): void`

**Migration — reading values:** Use direct readonly property access.

```php
// v2.x
$max = $limits->getMaxLocalPartLength();

// v3.0
$max = $limits->maxLocalPartLength;
```

**Migration — changing values:** `LengthLimits` is now immutable. Construct a new instance instead of mutating.

```php
// v2.x
$limits->setMaxLocalPartLength(100);

// v3.0
$limits = new LengthLimits(
    maxLocalPartLength: 100,
    maxTotalLength: $limits->maxTotalLength,
    maxDomainLabelLength: $limits->maxDomainLabelLength,
);
$options->setLengthLimits($limits); // deprecated but still works
// or pass to ParseOptions constructor:
$options = new ParseOptions([], [','], true, $limits);
```

The equivalent `setMaxLocalPartLength()` / `setMaxTotalLength()` / `setMaxDomainLabelLength()` methods on **`ParseOptions`** are still present but now `@deprecated`. They construct a new `LengthLimits` internally each call. They will be removed in v4.0.

#### 2. Error message wording changes

If your code matches against the exact text of `invalid_reason` strings (not recommended, but possible), the following messages changed:

| v2.x | v3.0 |
|------|------|
| `... per RFC erratum 1690` | `... per RFC 3696 EID 1690` |
| `Email Address contains whitespace` | `Email address contains whitespace` |
| `... in the beginning of an email addresses ...` | `... at the beginning of an email address ...` |

Recommended: match on the `invalid` boolean, not error text. A typed `ParseErrorCode` enum is planned for v3.1 (see DESIGN.md roadmap).

### Additive Changes (Non-Breaking)

#### New output field: `domain_ascii`

Each parsed address now includes a `domain_ascii` field. It is `null` unless `ParseOptions::$includeDomainAscii` is `true` (the default in the `rfc6531()` preset only). Existing code that reads other fields is unaffected.

```php
$result = $parser->parse('user@bücher.de', false);
$result['domain'];       // 'bücher.de'
$result['domain_ascii']; // 'xn--bcher-kva.de' (when includeDomainAscii=true), else null
```

#### New factory presets on `ParseOptions`

```php
ParseOptions::rfc5321()  // strict ASCII SMTP Mailbox
ParseOptions::rfc6531()  // full UTF-8 with NFC normalization
ParseOptions::rfc5322()  // recommended: addr-spec + obs-local-part
ParseOptions::rfc2822()  // maximum compatibility
new ParseOptions()       // legacy v2.x behavior (default)
```

The default constructor `new ParseOptions()` preserves v2.x parser behavior — UTF-8 in local-part, no obs-local-part rejection, no C0 control rejection, IP global-range validation enabled, RFC 5321 length limits enforced. **Existing callers do not need to change anything.**

#### New configurable rule properties on `ParseOptions`

15 public boolean properties expose previously hard-coded behavior. See README.md "Available Rule Properties" for the full table. Setting these on a default-constructed `ParseOptions` opts into stricter behavior incrementally without adopting a full preset.

### Deprecated (Still Functional)

The following `ParseOptions` methods are now `@deprecated v3.0` and will be removed in v4.0. They remain fully functional in v3.x:

- `setBannedChars(array)` — pass to constructor instead
- `setSeparators(array)` — pass to constructor instead
- `setUseWhitespaceAsSeparator(bool)` — pass to constructor instead
- `setLengthLimits(LengthLimits)` — pass to constructor instead
- `setMaxLocalPartLength(int)` — construct a new `LengthLimits`
- `setMaxTotalLength(int)` — construct a new `LengthLimits`
- `setMaxDomainLabelLength(int)` — construct a new `LengthLimits`

Suppress deprecation notices by migrating to constructor parameters or factory presets.

### Bug Fixes Visible to Callers

Several latent bugs from v2.x were fixed in v3.0. These may change validation outcomes for previously-misclassified inputs:

| Input | v2.x result | v3.0 result | Reason |
|-------|-------------|-------------|--------|
| Backslash-escaped quotes (`"a\"b"@x.com`) | invalid | valid | Backslash count loop fixed |
| `é.cloître@x.com` (multiple non-ASCII) | reports `î` (last) | reports `é` (first) | First invalid char now preserved |
| Empty domain after `@` triggering punycode path | crash (`ValueError`) | clean rejection | Empty-string guard added |
| IP literal with `domain` field | `null` (type mismatch) | `''` | Type consistency |

If your code depended on any of these old behaviors, audit accordingly.

### Minimum Requirements (Unchanged)

- PHP `^8.1`
- `ext-mbstring`
- `ext-intl` (for NFC normalization and IDN domain conversion)
