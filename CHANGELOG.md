# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.1.0]

Immutable `ParseOptions`, typed value-object output, structured error codes, and two new validation rules. All additions are non-breaking for v3.0 callers; readonly rule properties are a hard cutover for code that was mutating them directly (the factory methods and deprecated setters continue to work).

### Added
- `ParseErrorCode` backed enum exposing 46 distinct failure codes grouped by category (structural, character-class, dot placement, local-part content, quoted-string, domain, IP literal, length, display-name). Stable string backing values.
- `invalid_reason_code: ?ParseErrorCode` field on every parsed-address entry, populated at every `invalid_reason` emission site alongside the existing string.
- `ParsedEmailAddress` value object — immutable, readonly properties for all per-address fields (`address`, `originalAddress`, `simpleAddress`, `name`, `nameParsed`, `localPart`, `localPartParsed`, `domain`, `domainAscii`, `ip`, `domainPart`, `invalid`, `invalidReason`, `invalidReasonCode`, `comments`). `fromArray()` factory for conversion from the legacy array shape.
- `ParseResult` value object — immutable container for multi-address results (`success`, `reason`, `emailAddresses: list<ParsedEmailAddress>`).
- `Parse::parseSingle(string, string): ParsedEmailAddress` — typed single-address entry point.
- `Parse::parseMultiple(string, string): ParseResult` — typed multi-address entry point.
- `ParseOptions::withX()` fluent builders returning new instances: `withBannedChars`, `withSeparators`, `withUseWhitespaceAsSeparator`, `withLengthLimits`, plus one per rule property (19 builders in total).
- `validateDisplayNamePhrase: bool` rule — enforce RFC 5322 §3.2.5 phrase syntax (atext + WSP only) for unquoted display names. Adds `ParseErrorCode::InvalidDisplayNamePhrase`.
- `strictIdna: bool` rule — apply full IDNA2008 conformance on U-label domains (`IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII`) per RFC 5891/5892/5893. Enabled by default in `rfc6531()`.

### Changed
- `ParseOptions`: the 15 boolean rule properties are now `readonly` and set via constructor named arguments or the factory presets. Direct assignment such as `$options->requireFqdn = false` now throws `Error` (use `withRequireFqdn(false)` instead).
- `ParseOptions::rfc6531()` preset now includes `strictIdna: true`.
- Existing `parse()` method unchanged — returns the same array shape plus the new `invalid_reason_code` key.

### Fixed
- None — no behavior regressions; only additions.

## [3.0.0]

Configurable RFC compliance presets, immutable length limits, stricter validation, and substantial documentation. See [UPGRADE.md](UPGRADE.md) for migration steps.

### Added
- `ParseOptions::rfc5321()` — RFC 5321 Mailbox preset (strict ASCII SMTP).
- `ParseOptions::rfc6531()` — RFC 6531/6532 preset (full UTF-8 + NFC normalization).
- `ParseOptions::rfc5322()` — RFC 5322 addr-spec preset with `obs-local-part` (recommended default).
- `ParseOptions::rfc2822()` — RFC 2822 maximum-compatibility preset.
- 15 public boolean rule properties on `ParseOptions` exposing previously hard-coded behavior: `allowUtf8LocalPart`, `allowObsLocalPart`, `allowQuotedString`, `validateQuotedContent`, `rejectEmptyQuotedLocalPart`, `allowUtf8Domain`, `allowDomainLiteral`, `requireFqdn`, `validateIpGlobalRange`, `rejectC0Controls`, `rejectC1Controls`, `applyNfcNormalization`, `enforceLengthLimits`, `includeDomainAscii`.
- `domain_ascii` field in parsed-address output (populated when `includeDomainAscii = true`, default in `rfc6531()`).
- NFC Unicode normalization for local-part and domain (RFC 6532 §3.1) when `applyNfcNormalization = true`.
- IDNA UTS#46 punycode (A-label) conversion via `idn_to_ascii()` (RFC 5891/5892).
- C0 control character rejection (RFC 5321 §4.1.2) when `rejectC0Controls = true`.
- C1 control character rejection (RFC 6530 §10.1, RFC 6532 §3.2) when `rejectC1Controls = true`.
- Quoted-string content validation against `qtextSMTP` / `quoted-pairSMTP` (RFC 5321 §4.1.2) when `validateQuotedContent = true`.
- Empty-quoted-local-part rejection (RFC 5321 EID 5414) when `rejectEmptyQuotedLocalPart = true`.
- FQDN requirement (RFC 5321 §2.3.5) when `requireFqdn = true`.
- IP global-range validation for domain-literal addresses (rejects loopback, private, RFC 5736/5737 ranges).
- Trailing root-label dot stripping (RFC 5321 §2.3.5: `example.com.` accepted).
- Documentation: [DESIGN.md](DESIGN.md) (RFC reference), [ROADMAP.md](ROADMAP.md), [UPGRADE.md](UPGRADE.md).

### Changed
- `LengthLimits` is now an immutable value object using PHP 8.1 readonly constructor promotion.
- Default behavior of `new ParseOptions()` preserved — existing v2.x callers do not need to change anything.

### Fixed
- Backslash-escaped quotes (`"a\"b"@x.com`) — pre-existing bug where the backslash-count loop started at the wrong index and never recognized escaped quotes.
- First invalid character in `STATE_START` now reported (was overwritten on each subsequent invalid char).
- `normalizeDomainAscii()` no longer crashes on empty domain (`max(array_keys(count_chars('', 1)))` raised `ValueError`).
- `domain` field is now `''` (not `null`) when an IP literal is detected, matching the initialised type.
- NFC normalization result was previously discarded; `applyNfcNormalization = true` now has effect.
- `validateIpGlobalRange = true` in `rfc5321()` and `rfc6531()` (was inverted; strict presets allowed loopback/private IPs).
- Internationalized domains are NFC-normalized before punycode conversion (RFC 5891 §5.2).
- C1 regex uses Unicode notation `'/[\x{0080}-\x{009F}]/u'` (was raw bytes in `/u` mode, didn't match).
- Quoted-string content is now validated per `qtextSMTP` (was accepted unconditionally in all modes).
- Length check uses unquoted local-part length plus 2 for wire form (was counting enclosing DQUOTEs against the 64-octet limit).
- Error message wording: `per RFC erratum 1690` → `per RFC 3696 EID 1690`; `Email Address contains whitespace` → `Email address contains whitespace`; `in the beginning of an email addresses` → `at the beginning of an email address`.

### Deprecated
The following `ParseOptions` methods are scheduled for removal in v4.0:
- `setBannedChars()`, `setSeparators()`, `setUseWhitespaceAsSeparator()` — pass to constructor instead.
- `setLengthLimits()` — pass to constructor instead.
- `setMaxLocalPartLength()`, `setMaxTotalLength()`, `setMaxDomainLabelLength()` — construct a new `LengthLimits` instance.

### Removed
- `LengthLimits::getMaxLocalPartLength()` / `setMaxLocalPartLength()` — use the readonly `$maxLocalPartLength` property.
- `LengthLimits::getMaxTotalLength()` / `setMaxTotalLength()` — use the readonly `$maxTotalLength` property.
- `LengthLimits::getMaxDomainLabelLength()` / `setMaxDomainLabelLength()` — use the readonly `$maxDomainLabelLength` property.

## [2.8.0] - 2026-02-07
### Added
- RFC 5322 comment extraction: parentheses-delimited comments are captured into a new `comments` array on each parsed address ([#47](https://github.com/mmucklo/email-parse/pull/47)).
- Nested comments are supported and concatenated into a single string.
- README: complete documentation rewrite covering separators, length limits, and IDN ([#48](https://github.com/mmucklo/email-parse/pull/48)).

## [2.7.1] - 2026-02-07
### Changed
- Length-limit error messages now include RFC references and the active limit value, replacing hard-coded numbers ([#46](https://github.com/mmucklo/email-parse/pull/46)).

## [2.7.0] - 2026-02-07
### Added
- `LengthLimits` value object configuring the three RFC 5321 length limits: 64-octet local-part, 254-octet total, 63-octet domain label ([#45](https://github.com/mmucklo/email-parse/pull/45)).
- `LengthLimits::createDefault()` and `LengthLimits::createRelaxed()` (128/512/128) factory methods.
- `ParseOptions::setLengthLimits()` / `getLengthLimits()` plus per-limit convenience accessors.

### Changed
- Length checks switched from `mb_strlen()` (character count) to `strlen()` (octet count) per RFC 5321.
- Error messages reflect the configured limit instead of hard-coded `63` / `254` characters.

## [2.6.1] - 2026-02-07
### Changed
- Separator handling enhancements ([#44](https://github.com/mmucklo/email-parse/pull/44)).

## [2.6.0] - 2026-02-04
### Added
- Configurable separators via `ParseOptions` — supports comma, semicolon, and other custom delimiters; whitespace remains a default separator unless explicitly disabled ([#41](https://github.com/mmucklo/email-parse/pull/41)).

## [2.5.0] - 2026-02-01
### Added
- PHP 8.1 support.
- GitHub Actions CI workflow.
- `php-cs-fixer` integration.

### Fixed
- Edge cases in parser tests; removed redundant tests, added new edge-case tests ([#30](https://github.com/mmucklo/email-parse/pull/30), [#39](https://github.com/mmucklo/email-parse/pull/39)).

### Changed
- Updated Scrutinizer configuration.

## [2.4.1] - 2026-01-26
### Fixed
- `|` and `/` characters now handled correctly in email addresses ([#37](https://github.com/mmucklo/email-parse/pull/37)).

## [2.4.0] - 2026-01-25
### Added
- IPv6 domain-literal support: parses `user@[IPv6:::1]` and validates the address with `FILTER_VALIDATE_IP | FILTER_FLAG_IPV6` and global-range checks.
- Additional test cases for IPv6 addresses ([#36](https://github.com/mmucklo/email-parse/pull/36)).

### Removed
- Dependency on `laminas-validator`; validation logic moved into `Parse` ([#36](https://github.com/mmucklo/email-parse/pull/36)).

## [2.3.0] - 2025-11-07
### Added
- PHP 8 support.
- Type declarations across the public API ([#34](https://github.com/mmucklo/email-parse/pull/34)).

### Changed
- Bumped `psr/http-message` to support 2.x ([#34](https://github.com/mmucklo/email-parse/pull/34)).
- Resolved deprecation notices.

## [2.2.1] - 2023-01-03
### Removed
- Remaining punycode references after migration to Symfony polyfill ([#31](https://github.com/mmucklo/email-parse/pull/31)).

## [2.2.0] - 2023-01-03
### Added
- `ParseOptions` class for configuring parser behavior, originally to support overriding banned characters ([#22](https://github.com/mmucklo/email-parse/issues/22), [#23](https://github.com/mmucklo/email-parse/pull/23)).

### Changed
- Punycode handling replaced by `symfony/polyfill-intl-idn` ([#28](https://github.com/mmucklo/email-parse/pull/28)).
- Code style cleanup ([#29](https://github.com/mmucklo/email-parse/pull/29)).

### Fixed
- String interpolation issue ([#25](https://github.com/mmucklo/email-parse/pull/25)).

## [2.1.0] - 2021-09-16
### Added
- Internationalization support: accepts accented characters in display names without escaping ([#16](https://github.com/mmucklo/email-parse/issues/16)).
- New tests for issues [#10](https://github.com/mmucklo/email-parse/issues/10) (underscore in local-part) and [#12](https://github.com/mmucklo/email-parse/issues/12).
- `Parse::setOptions()` to swap parser options post-construction.

### Changed
- Migrated `zend-validator` → `laminas-validator` ([#21](https://github.com/mmucklo/email-parse/pull/21)).
- Bumped minimum PHP requirement to 7.1.

### Removed
- `laminas-validator` dependency for the IP path ([#20](https://github.com/mmucklo/email-parse/issues/20)).

## [2.0.0] - 2017-04-14
### Changed
- Dropped support for end-of-life PHP versions; targets PHP 7.1+.
- PSR-2 code-style cleanup, internal refactoring (`DRY` improvements).
- Added `composer.lock` and code-coverage tooling.
- Enabled Scrutinizer CI.

## [1.0.0] - 2015-09-15
First stable release. Includes the `0.4.x` PSR-4 autoloading and Travis CI fixes.

## [0.4.3] - 2015-09-15
### Changed
- Travis CI: switched to container-based builds.

## [0.4.2] - 2015-09-15
### Fixed
- Test namespace.
- PSR-4 autoloading configuration.

## [0.4.1] - 2015-09-15
### Fixed
- `composer.json` metadata.

## [0.4.0] - 2015-09-15
### Changed
- PSR-4 autoloading.
- Refactoring preparation.

## [0.3.2] - 2015-07-07
### Removed
- Old references in code and docs.

## [0.3.1] - 2015-07-07
### Changed
- Updated `composer.json` and README.

## [0.3.0] - 2015-07-07
### Added
- YAML-driven test fixtures (`tests/testspec.yml`) for easier maintenance.
- PSR-3 logger integration via `psr/log ~1.0`.

### Changed
- PHP CS cleanup.

## [0.2.0] - 2015-07-07
Initial public release on Packagist. Provides batch parsing of one or more comma-/whitespace-separated email addresses with display name, local-part, domain, and IP literal extraction.
