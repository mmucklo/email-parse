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

## v3.2 — Streaming, Severity Levels, Obsolete Syntax — shipped

**Batch streaming:**
- [x] `Parse::parseStream(iterable, string): Generator<ParsedEmailAddress>` — yields one typed address at a time; each input item may itself contain multiple separator-delimited addresses.

**Validation severity levels:**
- [x] `ValidationSeverity` enum with `Critical`, `Warning`, `Info` cases.
- [x] `ParseErrorCode::severity()` method classifying every code (13 Warning, rest Critical).
- [x] `ParsedEmailAddress::invalidSeverity()` accessor returning the derived severity (or `null` when valid).

**Obsolete syntax extensions (RFC 5322 §4):**

> Note: `obs-local-part` was already supported via `allowObsLocalPart` in v3.0.

- [x] `obs-route` handling — `ParseOptions::$allowObsRoute` gates acceptance of `<@host1,@host2:user@host3>` source-route prefixes; the route is captured on `ParsedEmailAddress::$obsRoute`. Enabled by default in `rfc5322()` and `rfc2822()`.
- [x] `obs-angle-addr` — implied by obs-route support (it is the outer `[CFWS] "<" obs-route addr-spec ">" [CFWS]` form).
- [x] `obs-domain-list` — the `*("," [CFWS] ["@" domain])` shape is consumed inside `STATE_OBS_ROUTE`.
- [x] CFWS (comments / folding whitespace) improvements — look-ahead in the whitespace handler now absorbs CFWS at dot-atom boundaries (`local @domain`, `local@ domain`, `local @ domain`) and around angle-addr delimiters (`<  local@domain  >`, `<local @ domain>`), including folded whitespace (LF + WSP). Comments in these positions were already supported in v3.0.

## v3.3 — Polish, Ergonomics — shipped

Non-breaking follow-on to v3.2.

**Serialization ergonomics:**
- [x] `ParsedEmailAddress::toArray(): array<string, mixed>` — round-trips to the legacy array shape for callers mixing typed and array-based code.
- [x] `ParsedEmailAddress::toJson(int $flags = 0): string` — convenience wrapper over `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- [x] `implements \Stringable` on `ParsedEmailAddress` — returns `simpleAddress` for valid addresses; empty string otherwise. Drops directly into string contexts.
- [x] `ParseResult::toArray()` and `toJson()` counterparts.

**Canonicalization (pulled forward from v4.0):**
- [x] `ParsedEmailAddress::canonical(): string` — minimal-quoting RFC 5322 display form per §3.2.4 (local-part) and §3.2.5 (phrase).
- [x] Optional local-part normalizer callback on `ParseOptions` for domain-specific rules (Gmail dot-insensitivity, `+tag` plus-addressing). Attached via `withLocalPartNormalizer(?callable)`.

**Ecosystem bridges:** *(deferred — out of scope for v3.3 per user direction)*
- [ ] `mmucklo/email-parse-symfony` — Symfony `Constraint` + `ConstraintValidator` attribute. Wraps existing `ParseOptions` presets.
- [ ] `mmucklo/email-parse-laravel` — Laravel validation rule, service provider for DI.
- [ ] PSR-14 event dispatcher integration — emit a `ParsedAddressEvent` per result for observability.

## Quality and Infrastructure (ongoing)

Not tied to a specific release; picked up as time allows.

**Testing depth:**
- [~] Mutation testing with Infection — wired in via `composer infect` with thresholds `minMsi=80`, `minCoveredMsi=85` (current baseline, up from 74/79). Target remains ≥85% overall MSI; raise threshold as more error-path tests land.
- [x] Property-based testing — `tests/PropertyTest.php` with 10 invariants across 200 random iterations each: no-crash on arbitrary bytes, determinism, reason+code consistency, severity classification, Stringable contract, toArray ↔ parse() round-trip, valid-address round-trip, and all-presets-never-crash. No extra dependency (native PHPUnit + `mt_rand`; deterministic via `SEED` envvar).
- [~] Parse.php line coverage — now 87.98% (up from 86.69%). Overall project line coverage 91.15% (up from 89.61%). Remaining gaps are obscure error branches, the "shouldn't ever get here" default case, and code paths reachable only via internal state corruption. Target ≥95% aspirational.
- [ ] CI matrix: add PHP 8.5 once released.

**Static analysis:**
- [x] PHPStan level 6 → 8 — tighter generics and inference; required four small nullable-return guards (`idn_to_ascii`, `mb_split`, `file_get_contents`) and one local docblock shape on `parseMultiple()`.
- [x] Psalm alongside PHPStan — level 3 with baseline (66 entries, all false positives or duplicates of PHPStan findings). Found no genuinely new bugs vs PHPStan level 8; serves as a cross-check for future regressions. `composer psalm`.

**Performance:**
- [x] PhpBench suite — `benchmarks/ParseBench.php` covers single ASCII, name-addr, UTF-8 local-part, IDN, obs-route, 10-address comma batch, 100-address `parseStream` batch, invalid inputs, and comment extraction. Run with `composer bench`.
- [ ] Profile the state machine under mailing-list-sized inputs. Likely hot path: `mb_substr` in the main loop — investigate byte iteration for pure-ASCII inputs.

**Community / documentation:**
- [x] `CONTRIBUTING.md` — dev setup, all `composer` scripts, test-case guidance, code-style rules, RFC citation expectations.
- [ ] GitHub issue + pull-request templates.
- [ ] `CODE_OF_CONDUCT.md`.
- [ ] Examples directory or GitHub Pages cookbook (UTF-8 addresses, obs-route in practice, custom normalizers once they ship, Symfony/Laravel integration snippets).
- [ ] README cleanup — split the large reference tables into `docs/` sub-pages if the top-level README grows further.

## v4.0 — Breaking Modernization

**API cleanup:**
- [ ] Remove deprecated `ParseOptions` setters (see Deprecation Timeline above).
- [ ] Remove `parse()` in favor of `parseSingle()` / `parseMultiple()` with typed returns — eliminates the polymorphic `$multiple` boolean parameter.
- [ ] Deprecate or remove the `getInstance()` singleton (recommend explicit instantiation).
- [ ] Constructor promotion on `ParseOptions` with named arguments.

**New capabilities (genuinely breaking or late-binding):**
- [ ] Optional DNS/MX validation via callback interface (`DnsValidator`). Breaking because the Parse constructor signature grows, and because synchronous DNS lookups change performance characteristics meaningfully.
- [ ] Group syntax support (RFC 6854: `Group Name: addr1, addr2;`). Breaking because it introduces a new output-container shape for grouped results.

*Note: `canonicalize()` and the local-part normalizer callback were moved to v3.3 as additive (non-breaking) features.*
