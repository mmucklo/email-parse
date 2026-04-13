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

## v3.1 — Immutable Config, Error Codes, Typed Output — shipped

**Immutable `ParseOptions` with fluent builders:**
- [x] All 15 boolean rule properties are now `readonly` (PHP 8.1). The 4 state fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`) remain mutable via deprecated setters until v4.0.
- [x] Fluent builder methods that return new instances:
  ```php
  ParseOptions::rfc5322()->withBannedChars([...])->withSeparators([...])->withRequireFqdn(true);
  ```
- Deprecated setters continue to work for backward compatibility.

**Structured error codes:**
- [x] `ParseErrorCode` backed enum — 46 cases grouped by category (structural, character, dot placement, local-part content, quoted-string, domain, IP literal, length, display-name).
- [x] `invalid_reason_code: ?ParseErrorCode` on every parsed-address entry, populated alongside the existing `invalid_reason` string.

**Typed output value objects (non-breaking):**
- [x] `ParsedEmailAddress` — readonly properties for every per-address field with named-arg constructor and `fromArray()` factory.
- [x] `ParseResult` — readonly `success`, `reason`, `emailAddresses` (array of `ParsedEmailAddress`).
- [x] New methods: `Parse::parseSingle(string): ParsedEmailAddress`, `Parse::parseMultiple(string): ParseResult`.
- Existing `parse()` stays unchanged for backward compatibility.

**Additional validation rules:**
- [x] `validateDisplayNamePhrase: bool` — enforce RFC 5322 §3.2.5 phrase syntax (atext + WSP only) for unquoted display names.
- [x] `strictIdna: bool` — apply full IDNA2008 conformance (`IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII`) per RFC 5891/5892/5893. Enabled by default in `rfc6531()`.
- [x] Extended test coverage: 265 assertions (target: 250+).

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
