# RFC Compliance Modes - Design Document

## Goal
Upgrade email-parse to support multiple RFC compliance levels:
- RFC 5322 (current standard, 2008)
- RFC 2822 (older standard, 2001)
- RFC 822 (legacy, 1982)
- Plus a legacy mode for backward compatibility

## Proposed Modes

### 1. STRICT (RFC 5322 Strict)
- No obsolete syntax
- Strict character validation in local part
- Proper quoting required for special characters
- RFC length limits enforced (64/254/63)
- **Use case**: Modern applications requiring strict compliance

### 2. NORMAL (RFC 5322 + obsolete) - RECOMMENDED DEFAULT
- Accepts obsolete syntax (obs-local-part, obs-domain)
- RFC 5322 compliant but permissive
- Good balance of compliance and compatibility
- **Use case**: General purpose email validation

### 3. RELAXED (RFC 2822 Compatible)
- More permissive character acceptance
- Accepts common non-standard formats
- Still validates basic structure
- **Use case**: Legacy system integration

### 4. LEGACY (Current Parser Behavior)
- Most permissive
- Maintains exact current behavior
- For backward compatibility
- **Use case**: Existing applications, zero breaking changes

## Implementation Plan

### Phase 1: Infrastructure
- [ ] Create `src/RfcMode.php` enum/class
- [ ] Add `rfcMode` to `ParseOptions`
- [ ] Default to LEGACY initially (can change to NORMAL in v3.0)

### Phase 2: Validation Logic
- [ ] Add mode-specific validation methods
- [ ] Update STATE_LOCAL_PART handling
- [ ] Update STATE_DOMAIN handling  
- [ ] Handle special characters per mode

### Phase 3: Testing
- [ ] 30+ tests for STRICT mode
- [ ] 25+ tests for NORMAL mode
- [ ] 25+ tests for RELAXED mode
- [ ] 15+ tests for LEGACY mode (no regressions)

### Phase 4: Documentation
- [ ] Update README
- [ ] Add migration guide
- [ ] Document each mode clearly

## Default Mode Decision
**Current**: LEGACY (for v2.x - no breaking changes)
**Future**: NORMAL (for v3.0 - modern default)
