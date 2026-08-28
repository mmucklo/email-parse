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

- **v3.0 → removed v4.0:** the `ParseOptions` mutating setters (`setBannedChars`, `setSeparators`, `setUseWhitespaceAsSeparator`, `setLengthLimits`, `setMaxLocalPartLength`, `setMaxTotalLength`, `setMaxDomainLabelLength`) were deprecated in v3.0 and are removed in v4.0. The state fields are `public readonly`; configure via the constructor or `withX()` builders.
- **v3.0:** `LengthLimits` moved to readonly constructor promotion (getters/setters removed — see [UPGRADE.md](UPGRADE.md)).
- **v3.9 → removed v4.0:** `protected Parse::validateLocalPart(array $emailAddress)` was deprecated in 3.9 and is removed in 4.0 (now a `private` `ParseContext`-based method). Validation is customized via `ParseOptions`.
- **v4.0:** `Parse::parse()` (the polymorphic array API) marked `@deprecated` — kept as a working shim over the typed methods; removal targeted for v5.0.
- **v4.0:** `Parse::getInstance()` (default-options singleton) marked `@deprecated` — use `new Parse($logger, $options)`; removal targeted for v5.0.
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

## Strategic direction (North Star)

Three longer-horizon goals — **PHP framework integration** (Symfony/Laravel), **localized error messages**, and **ports to other languages** (JS/Python/…) — all converge on one architectural principle:

> **Decouple error _identity_ from error _presentation_.** An error is a `ParseErrorCode` plus named parameters (the language-agnostic identity); the human string is _rendered_ from that (the localizable presentation).

This single decoupling serves all three: **i18n** = swap the message catalog; **ports** = the spec asserts on `code + parameters`, message text is non-normative and rendered per implementation; **framework hooks** = the framework's translator is the renderer. `ParseErrorCode` already supplies the identity; the 4.1 keystone adds the parameters + a swappable `MessageProvider`.

Assets that make this credible — protect them as first-class:
- **`testspec.yml`** — a language-agnostic conformance suite; the canonical spec for ports (assert on `invalid_reason_code` + parameters; treat message text as non-normative).
- **`ARCHITECTURE.md`** — the portable algorithm reference for ports.
- **`ParseErrorCode`** — the stable error contract that i18n and ports bind to.

Framework packages (`email-parse-symfony`, `email-parse-laravel`) live in separate repos on their own track, wrapping the frozen 4.0 typed API and wiring the framework Translator in as the `MessageProvider`.

## Planned

### v4.0 — Breaking modernization

**API cleanup:**
- [x] Removed the `@deprecated` `ParseOptions` setters (deprecated in v3.0).
- [x] Promoted the `ParseOptions` state fields (`bannedChars`, `separators`, `useWhitespaceAsSeparator`, `lengthLimits`, `allowedWhitespace`) to `public readonly`. Configured via the constructor or `withX()` builders; the `getX()` accessors remain.
- [x] **Deprecate** the polymorphic `parse()` (the `$multiple`-boolean array API) in favor of `parseSingle()` / `parseMultiple()` / `parseStream()`. Kept as a thin `@deprecated` shim over the private `parseInternal()` core, so it still works; **removal deferred to 5.0** (see below).
- [x] **Deprecate** the `getInstance()` singleton (recommend explicit instantiation — the static singleton carries process-global state and is pinned to the LEGACY preset). Kept working; **removal deferred to 5.0**.
- [x] Removed the deprecated `Parse::validateLocalPart(array)` extension point (deprecated in 3.9) — local-part validation is now a `private`, `ParseContext`-based method, and `validateDomainName()` is `private` too. They took the parser's internal accumulator and were never a supported extension point — validation is customized through `ParseOptions`.

_v4.0 is deliberately **lean**: breaking API cleanup + internal modernization only (see [UPGRADE.md](UPGRADE.md)), so it ships fast and upgrades mechanically. The larger new features once slated here are additive and move to later minors; only the genuinely breaking one (group syntax) moves to 5.0. Internal modernization (`ParseContext` camelCase + readonly, the `ParserState` enum) is tracked under the Backlog below._

### v4.1 — Structured errors (the keystone)

The highest-leverage post-4.0 work: it unlocks i18n, framework-native localization, and clean ports at once (see North Star above). Additive / non-breaking — `invalid_reason` keeps returning today's English strings by default.

- [ ] Capture **named message parameters** at each error site and expose them as `ParsedEmailAddress::$messageParameters` (`array<string, scalar>`; `[]` when valid).
- [ ] Introduce a `MessageProvider` interface with a built-in `EnglishMessageProvider` default that renders `invalid_reason` from `code + parameters`:
  ```php
  interface MessageProvider
  {
      /** @param array<string, scalar> $parameters e.g. ['char' => ':'] or ['limit' => 64] */
      public function message(ParseErrorCode $code, array $parameters = [], ?string $locale = null): string;
  }
  ```
  **Named** (not positional) parameters — word order varies across languages. Frameworks re-render from `code + messageParameters` via their own translator; injecting a custom `MessageProvider` into `Parse` is the optional path for localized `invalid_reason` at the source.
- [ ] Evolve `testspec.yml` so `invalid_reason_code` (+ parameters) is the normative assertion and message text is non-normative — making the spec port-ready.

### v4.2 — planned

- [ ] DNS/MX validation via a `DnsValidator` callback interface. Additive and opt-in (off by default): the `Parse` constructor gains a parameter, and synchronous lookups change performance characteristics, so callers choose it explicitly.

### v4.3 — planned

- [ ] Confusable-against-a-target-list matching — compare the domain's Unicode skeleton against a caller-supplied brand/skeleton set (`Spoofchecker::areConfusable()`), following on from the v3.8 single-string check. Additive; deferred until the caller-provided target-list API is designed.
- [ ] **RFC 6854 group syntax** (`Group Name: addr1, addr2;`; empty groups `Name:;`) — parse the group construct (RFC 5322 §3.4 / RFC 6854) **additively**: group members flatten into `emailAddresses` unchanged (each gaining an optional `->group` name), plus a `ParseResult::groups()` typed view (`ParsedGroup { string $name; ParsedEmailAddress[] $addresses }`) for callers who want structure, including empty groups. Non-breaking, so it stays a minor — only a *structural* redesign that changed `emailAddresses`' type would force a major.

### v5.0 — planned

- [ ] Remove the deprecated `parse()` method (deprecated in 4.0). `parseSingle()` / `parseMultiple()` / `parseStream()` are the entry points; the private `parseInternal()` core stays.
- [ ] Remove the deprecated `Parse::getInstance()` singleton (deprecated in 4.0). Use `new Parse($logger, $options)`.

_(RFC 6854 group syntax moved to 4.2/4.3 — it can be added additively, see above; only a structural redesign of `emailAddresses` would make it a 5.0 break.)_

### Backlog (unversioned)

- [ ] **`parse()` refactor & modernization follow-ups** (from review; non-blocking, each behavior-preserving and test-gated):
  - [x] Renamed `ParseContext`'s accumulator fields `snake_case` → `camelCase` to match the codebase. Output-array keys stay `snake_case` (public API, string literals in `addAddress()`); only the internal properties changed.
  - [x] **Encoded `ParseContext`'s three concerns structurally.** The input snapshot (`chars`/`len`/`emails`) and hoisted config (`separators`, `bannedChars`, …) are now `public readonly` constructor-promoted properties, so only the per-address accumulator stays mutable — a handler can no longer write config. `parseInternal()`'s setup was reordered to build the config before constructing the context.
  - [x] **Introduced a `ParserState: int` backed enum** (`src/ParserState.php`) in place of the 13 `Parse::STATE_*` int constants. `ParseContext::$state`/`$subState` are now typed `ParserState`, so the parser can never hold an out-of-range state (this also retires the old "misleading 0 default" caveat). Enum `===` is identity comparison, so no measurable hot-loop cost is expected; the perf no-regression constraint is verified by the CI **Benchmarks (vs base)** job (`bench:compare`, ≤1.5× base) — local benchmarking is unreliable in the dev sandbox (Xdebug + `opcache.enable_cli=0`).
  - [ ] Drop the `chars`/`len` double source of truth (loop locals vs context properties — kept for hot-loop locality; measure before changing).
  - [ ] Decompose the two remaining large methods — `handleStateAddress` (~209 lines; CFWS/`@`/non-atext already peeled off, the rest is inherent to the addr-spec sub-machine) and `addAddress` (~221 lines, pre-existing; splits into IP-literal detection, validation, and output-array assembly). Both diminishing-returns polish.
- [ ] **Ecosystem bridges:** `mmucklo/email-parse-symfony` (`Constraint` + `ConstraintValidator`), `mmucklo/email-parse-laravel` (validation rule + service provider), PSR-14 `ParsedAddressEvent` for observability.
- [ ] **Large-batch profiling:** the `mb_str_split` array dominates memory for very large batches; a streaming/chunked reader could bound it.
- [ ] **README cleanup:** split the large reference tables into `docs/` sub-pages if the top-level README keeps growing.
