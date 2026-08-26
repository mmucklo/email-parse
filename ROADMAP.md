# Roadmap

Intent, not commitment — priorities and scope may shift. Shipped work is kept
below as a record; planned work follows.

## Released

### v3.1 — Immutable config, error codes, typed output

- Immutable `ParseOptions`: all 15 boolean rule properties are `readonly` (PHP 8.1), with fluent `withX()` builders that return new instances. The 4 state fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`) stay mutable via deprecated setters until v4.0.
- `ParseErrorCode` backed enum — 46 cases grouped by category; `invalid_reason_code: ?ParseErrorCode` on every entry alongside the `invalid_reason` string.
- Typed output value objects (non-breaking): `ParsedEmailAddress` and `ParseResult` (readonly), plus `parseSingle()` / `parseMultiple()`. `parse()` is unchanged.
- Validation rules: `validateDisplayNamePhrase` (RFC 5322 §3.2.5 phrase syntax) and `strictIdna` (full IDNA2008 conformance; default in `rfc6531()`).

### v3.2 — Streaming, severity levels, obsolete syntax

- `parseStream(iterable, string): Generator<ParsedEmailAddress>` — yields one address at a time; each input item may itself hold several.
- `ValidationSeverity` enum (Critical / Warning / Info), `ParseErrorCode::severity()`, and `ParsedEmailAddress::invalidSeverity()`.
- Obsolete syntax (RFC 5322 §4): `obs-route` (`$allowObsRoute`, captured on `$obsRoute`; default in `rfc5322()` / `rfc2822()`), `obs-angle-addr`, `obs-domain-list`, and CFWS look-ahead at dot-atom and angle-addr boundaries. (`obs-local-part` already shipped in v3.0.)

### v3.3 — Polish, ergonomics

- Serialization: `ParsedEmailAddress::toArray()` / `toJson()`, `implements \Stringable` (returns `simpleAddress`), and `ParseResult` counterparts.
- `canonical()` — minimal-quoting RFC 5322 display form (§3.2.4 local-part, §3.2.5 phrase).
- Optional local-part normalizer callback via `withLocalPartNormalizer()` — for Gmail dot-insensitivity, `+tag` plus-addressing, and similar domain rules.

### v3.8 — Confusable-domain detection

- Opt-in homoglyph / confusable-domain detection: `withDetectConfusableDomain()` runs the `intl` `Spoofchecker` (mixed-script / confusable) over the U-label domain and surfaces `ParsedEmailAddress::$domainIsSuspicious`. It's a security-policy signal, not a validity check — the address stays valid — and legitimate single-script international domains (`почта.рф`, `münchen.de`) are not flagged.

### Deprecations

- **v3.0:** `LengthLimits` moved to readonly constructor promotion (getters/setters removed — see [UPGRADE.md](UPGRADE.md)). The `ParseOptions` setters (`setBannedChars`, `setSeparators`, `setUseWhitespaceAsSeparator`, `setLengthLimits`, `setMaxLocalPartLength`, `setMaxTotalLength`, `setMaxDomainLabelLength`) are marked `@deprecated` and still functional; removal is targeted for v4.0.
- `RfcMode` never shipped (existed only on a feature branch).

### Community & documentation

- `CONTRIBUTING.md`, GitHub issue + PR templates (parser-tailored YAML forms), `CODE_OF_CONDUCT.md`, and the examples cookbook (`docs/cookbook.md`) — all shipped and linked from the README.

## Quality & infrastructure

Continuous work, not tied to a specific release.

**Testing depth:**
- [~] Mutation testing (Infection) — `composer infect`, thresholds `minMsi=80` / `minCoveredMsi=85` (baseline up from 74/79). Target ≥85% overall MSI; raise as more error-path tests land.
- [x] Property-based tests — `tests/PropertyTest.php`, 10 invariants × 200 random iterations (no-crash on arbitrary bytes, determinism, reason/code consistency, severity, Stringable, `toArray` ↔ `parse()` round-trip, valid-address round-trip, all-presets-never-crash). Native PHPUnit + `mt_rand`; deterministic via `SEED`.
- [~] Coverage — `Parse.php` 87.98%, project 91.15%. Remaining gaps are obscure error branches, the defensive "shouldn't get here" default case, and paths reachable only via internal state corruption. ≥95% aspirational.
- [x] CI matrix — PHP 8.5 required; PHP 8.6 nightly allowed-to-fail until stable (~Nov 2026).

**RFC conformance (differential vs `dominicsayers/isemail`, 164 cases):**
- [x] Drove strict-preset false-accepts from 29 → **1** (the intentional trailing root dot, now toggleable). Clusters resolved: quoted-string boundaries (`"test"test@` rejected, `"word".atom` valid); unclosed domain literal (`test@[1.2.3.4`); comment / CFWS parsing (unbalanced nesting, `\)` quoted-pair, C0 controls, atext-after-comment); quoted-string content (bare CR/LF); the CR/LF & folding-whitespace policy (`withTrimSingleAddressWhitespace`, `withStrictMultiWhitespace`); and the trailing domain dot (`withRejectTrailingDot`). The harness is a local dev tool, not a CI gate; every cluster carries regression tests in `tests/ParseTest.php`.

**Pre-existing bugs fixed (found in review; outside the isemail corpus):**
- [x] Angle-addr with a domain-literal (`<user@[1.2.3.4]>`) was wrongly rejected — the `>` handler now accepts `STATE_AFTER_DOMAIN` when a domain/IP is present.
- [x] `word "." word` with quoted-string words (`"x"."y"@`, `x."y"@`, `"a b"."c"@`) now accepted (RFC 5322 §3.4.1).
- [x] `ParserConfusion` no longer reaches callers — `user@a[1.2.3.4]` is rejected up front as `InvalidOpeningBracket`; a 500k-input fuzz confirms the path is unreachable.
- [x] C1 controls (U+0080–U+009F) in comment content now rejected under `rejectC1Controls` (rfc6531), matching local-part and quoted-string handling.

**Static analysis:**
- [x] PHPStan level 6 → 8 (tighter generics; four nullable-return guards, one local docblock shape on `parseMultiple()`).
- [x] Psalm level 3 with baseline as a cross-check — no genuinely new bugs vs PHPStan level 8. `composer psalm`.

**Performance:**
- [x] PhpBench suite (`composer bench`) plus baseline/compare (`bench:baseline`, `bench:compare`; reference figures in `benchmarks/BASELINE.md`) and a non-blocking `benchmarks` CI job.
- [x] Hot-path fix — per-character `mb_substr` (O(n²) for multi-byte encodings) replaced with a single `mb_str_split` pass and array indexing. ~10–27% faster across the suite.

**Maintainability:**
- [x] **`parse()` decomposition** (delivered; unreleased). The ~772-line state-machine loop is now a ~185-line dispatch loop over per-state handler methods, backed by a typed, per-parse `ParseContext` (a fresh instance per call keeps the parser reentrant). Behavior-preserving — same logic, conditions, ordering, and output. See [ARCHITECTURE.md](ARCHITECTURE.md). Follow-ups in the backlog below.

## Planned

### v4.0 — Breaking modernization

**API cleanup:**
- [ ] Remove the `@deprecated` `ParseOptions` setters (deprecated in v3.0).
- [ ] Promote the `ParseOptions` state fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`) to public `readonly` via constructor promotion with named arguments.
- [ ] Remove the polymorphic `parse()` in favor of `parseSingle()` / `parseMultiple()` with typed returns — drops the `$multiple` boolean parameter.
- [ ] Deprecate or remove the `getInstance()` singleton (recommend explicit instantiation).
- [ ] Make the internal validation helpers (`validateLocalPart`, `validateDomainName`) `private`. They are already `@internal`; `validateLocalPart`'s signature became `ParseContext` in the `parse()` decomposition. They take the parser's internal accumulator and were never a supported extension point — validation is customized through `ParseOptions`.

**New capabilities (breaking or late-binding):**
- [ ] DNS/MX validation via a `DnsValidator` callback interface — breaking because the `Parse` constructor grows, and synchronous lookups change performance characteristics.
- [ ] Group syntax (RFC 6854: `Group Name: addr1, addr2;`) — introduces a new output-container shape for grouped results.
- [ ] Confusable-against-a-target-list matching — compare the domain's Unicode skeleton against a caller-supplied brand/skeleton set (`Spoofchecker::areConfusable()`), following on from the v3.8 single-string check. Deferred until the caller-provided target list is designed.

### Backlog (unversioned)

- [ ] **`parse()` refactor follow-ups** (from review; non-blocking): rename `ParseContext`'s accumulator fields `snake_case` → `camelCase`; encode its three concerns (immutable input snapshot / read-only config / mutable accumulator) structurally rather than by convention; drop the `chars` / `len` duplication (loop locals vs context properties — kept for hot-loop locality, measure before changing); decompose `handleStateAddress` further (~200 lines; diminishing returns).
- [ ] **Ecosystem bridges:** `mmucklo/email-parse-symfony` (`Constraint` + `ConstraintValidator`), `mmucklo/email-parse-laravel` (validation rule + service provider), PSR-14 `ParsedAddressEvent` for observability.
- [ ] **Large-batch profiling:** the `mb_str_split` array dominates memory for very large batches; a streaming/chunked reader could bound it.
- [ ] **README cleanup:** split the large reference tables into `docs/` sub-pages if the top-level README keeps growing.
