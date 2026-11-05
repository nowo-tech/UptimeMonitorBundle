# Feature Specification: SSL monitor SPKI pinning

**Feature Branch**: `feat/ssl-spki-pins`  
**Created**: 2026-11-05  
**Status**: Implemented (Unreleased)  
**Input**: An SSL monitor only proves that a publicly trusted, unexpired certificate answers. Operators who pin their
keys (HPKP-style `pins.json`, mobile apps, mTLS clients) need the monitor to fail when the served chain no longer
contains a key they expect — a rotation that would break pinned clients, or an unexpected certificate.

**Related docs**: [`docs/CHECK-TYPES.md`](../../docs/CHECK-TYPES.md), baseline
[`001-baseline/spec.md`](../001-baseline/spec.md) (FR-CHECK-004, FR-CHECK-006)

---

## User Scenarios & Testing

### US-01 — Alert when the served chain drops my pinned keys (Priority: P1)

As a monitor manager, I paste my SPKI pins into an SSL monitor. The monitor stays *up* while any certificate of the
served chain (leaf, intermediates, root if sent) has one of them, and goes *down* with
`Served chain contains none of the expected SPKI pins` otherwise.

**Acceptance**:

1. Without pins the check is unchanged (expiry / verification only; the chain is not even requested).
2. A pin of the leaf, of an intermediate or of a sent root matches.
3. On a mismatch the result metadata lists `served_spki_pins`.
4. A stored list whose pins are all malformed is *down* without connecting (fail closed).

### US-02 — Enter pins safely (Priority: P1)

As a monitor manager, I enter one pin per line (`#` comments allowed) or paste a JSON list. A malformed pin or more
than 20 pins is refused with a field error; backup import applies the same rules.

---

## Requirements

- **FR-SSL-PIN-001**: `expected_spki_pins` is a list of at most 20 distinct `sha256/<base64>` values (standard base64
  of 32 bytes with padding). `Monitor\SpkiPins` parses the form text (lines or JSON array / `{"pins": [...]}`),
  validates it and computes a certificate's pin as SHA-256 of its DER SubjectPublicKeyInfo
  (`openssl_pkey_get_public` + `openssl_pkey_get_details`).
- **FR-SSL-PIN-002**: `SslCheckRunner` adds `capture_peer_cert_chain` only for a pinned monitor and compares the pins
  of the leaf and of every certificate of the captured chain. Pinning adds no new target: the SSRF guard and the
  pinned-address connection of FR-CHECK-006 are unchanged. TLS verification stays on.
- **FR-SSL-PIN-003**: The pins are public data (not secrets, not redacted). They are stored in the monitor `config`
  JSON only when present, so no schema change is needed and unpinned configs are byte-identical to earlier versions.

## Tests

`tests/Unit/Monitor/SpkiPinsTest.php` (format, parsing, fixture pin computed with the openssl CLI, generated chain),
`tests/Unit/Check/SslCheckRunnerTest.php` (in-memory root → intermediate → leaf chain via
`tests/Support/TestCertificateChain.php`, no network), `StreamSocketConnectorTest` (chain captured over loopback),
`MonitorFormTypeTest`, `MonitorFactoryTest`, `MonitorBackupServiceDbTest`.
