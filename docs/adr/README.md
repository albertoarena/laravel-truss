# Architecture Decision Records

One file per architectural decision that is expensive to reverse or easy to
re-litigate. Numbered, immutable once accepted: a decision that changes gets a
new ADR that supersedes the old one, rather than an edit.

**Relationship to [`../DECISIONS.md`](../DECISIONS.md).** That file stays the
short register of every significant choice, one paragraph each, and it remains
the place to look first. An ADR is for the small number of decisions that need
the evidence, the alternatives and the consequences written down, because the
reasoning is the expensive part and a paragraph loses it. Where both exist,
`DECISIONS.md` carries the one-line answer and links here.

**This directory is `export-ignore`d**, like the rest of `docs/`, so nothing here
reaches a `composer require`. It is tracked in git, which is the point: it has to
outlive the session that produced it.

## Index

| ADR | Title | Status |
|---|---|---|
| [0001](0001-php-82-minimum.md) | Lower the PHP floor to 8.2 | Accepted, implemented |
| [0002](0002-defer-gate-registration.md) | Do not resolve the Gate contract at boot | Accepted, implemented |
| [0003](0003-pivot-detection.md) | Narrow pivot detection in `TRUSS-INT-007` | Accepted, implemented |

All three come from the same source: the field study in
[`../research/2026-08-doctor-field-study.md`](../research/2026-08-doctor-field-study.md),
which ran `truss:doctor` against twelve real open-source Laravel applications.
Read that first if any of these decisions look arbitrary.

## Status values

- **Proposed** — written, not approved, no code changed.
- **Accepted** — approved. Implementation may still be pending, and shipping is a separate question; the ADR says where it stands.
- **Superseded by NNNN** — replaced. The file stays.
