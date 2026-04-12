# Roadmap

Future plans by version. Items here are intent, not commitment — priority and scope may shift.

## Deprecation Timeline

### v3.0 — shipped
- [x] `LengthLimits` switched to readonly constructor promotion (getters/setters removed; see [UPGRADE.md](UPGRADE.md) for migration).
- [x] `ParseOptions` setters marked `@deprecated v3.0` (`setBannedChars`, `setSeparators`, `setUseWhitespaceAsSeparator`, `setLengthLimits`, `setMaxLocalPartLength`, `setMaxTotalLength`, `setMaxDomainLabelLength`) — still functional.
- [x] `RfcMode` class never released (existed only on a feature branch).

### v4.0 — planned
- [ ] Remove all `@deprecated` `ParseOptions` setters above.
- [ ] Make remaining private fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`) public readonly via constructor promotion.

## v3.1 — Immutable Config, Error Codes, Typed Output

**Immutable `ParseOptions` with fluent builders:**
- [ ] Make all 15 boolean rule properties `readonly` (PHP 8.1) to prevent accidental mutation of shared instances (e.g. via DI container).
- [ ] Add fluent builder methods that return new instances:
  ```php
  ParseOptions::rfc5322()->withBannedChars([...])->withSeparators([...]);
  ```
- Existing deprecated setters continue to work for backward compatibility.

**Structured error codes:**
- [ ] Add a `ParseErrorCode` backed enum (e.g. `InvalidLocalPart`, `InvalidDomain`, `MissingDomain`, `Utf8NotAllowed`, `LengthExceeded`).
- [ ] Return `invalid_reason_code: ?ParseErrorCode` alongside the existing `invalid_reason` string — enables programmatic error handling without breaking existing consumers.

**Typed output value objects (non-breaking):**
- [ ] `ParsedEmailAddress` — readonly properties for all per-address fields (`address`, `localPart`, `localPartParsed`, `domain`, `domainAscii`, `ip`, `domainPart`, `invalid`, `invalidReason`, `invalidReasonCode`, `comments`, etc.).
- [ ] `ParseResult` — readonly `success`, `reason`, `emailAddresses` (array of `ParsedEmailAddress`).
- [ ] New methods: `parseSingle(string): ParsedEmailAddress`, `parseMultiple(string): ParseResult`.
- Existing `parse()` stays for backward compatibility.

**Additional validation rules:**
- [ ] `validateDisplayNamePhrase: bool` — enforce RFC 5322 §3.4 phrase syntax for display names.
- [ ] Stricter IDNA U-label validation for the `rfc6531()` preset (CONTEXTJ/CONTEXTO checks, Bidi rule per RFC 5891 §4 / RFC 5893). UTS#46 punycode conversion already done in v3.0.
- [ ] Extended test coverage (currently 224 assertions; target 250+).

## v3.2 — Streaming, Severity Levels, Obsolete Syntax

**Batch streaming:**
- [ ] `parseStream(iterable): Generator` — yield `ParsedEmailAddress` one at a time for large email lists, reducing memory footprint.

**Validation severity levels:**
- [ ] Add a `ValidationSeverity` enum (`Critical`, `Warning`, `Info`) attached to each parsed address — allows callers to accept "soft" failures while rejecting hard ones.

**Obsolete syntax extensions (RFC 5322 §4):**

> Note: `obs-local-part` is already supported via `allowObsLocalPart` in v3.0. The items below cover the remaining obsolete forms.

- [ ] `obs-route` handling for the `rfc5322()` preset.
- [ ] CFWS (comments / folding whitespace) improvements.
- [ ] `obs-angle-addr` support.
- [ ] `obs-domain-list` syntax for the `rfc2822()` preset.

## v4.0 — Breaking Modernization

**API cleanup:**
- [ ] Remove deprecated `ParseOptions` setters (see Deprecation Timeline above).
- [ ] Remove `parse()` in favor of `parseSingle()` / `parseMultiple()` with typed returns — eliminates the polymorphic `$multiple` boolean parameter.
- [ ] Deprecate or remove the `getInstance()` singleton (recommend explicit instantiation).
- [ ] Constructor promotion on `ParseOptions` with named arguments.

**New capabilities:**
- [ ] Optional DNS/MX validation via callback interface (`DnsValidator`).
- [ ] Group syntax support (RFC 6854: `Group Name: addr1, addr2;`).
- [ ] `canonicalize(ParsedEmailAddress): string` — standard display form.
- [ ] Optional local-part normalizer callback for domain-specific rules (e.g. Gmail dot-insensitivity, plus-addressing).
