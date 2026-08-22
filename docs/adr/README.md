# Architecture Decision Records

One file per architectural decision that is expensive to reverse or easy to
re-litigate. Numbered, immutable once accepted: a decision that changes gets a
new ADR that supersedes the old one, rather than an edit.

**An addition to the same decision gets a dated addendum at the bottom, not a new
number and not an edit to the body** (convention added 22/08/2026, with ADR 0003
as the first case). The rule above is about a decision *changing*, and it left no
home for a later change that touches the same thing without contradicting it.
**The test is whether the original text is still true.** If it is, the accepted
decision stays readable exactly as accepted and the addendum says what was added
and when. If it is not, that is a supersession and it needs its own number. **A
change too small to carry alternatives and evidence of its own belongs in an
addendum or in `DECISIONS.md`, never in a thin ADR**, because the format is worth
something only while every file in it earns the format.

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
| [0003](0003-pivot-detection.md) | Narrow pivot detection in `TRUSS-INT-007` | Accepted, implemented, **addendum 22/08/2026** |

All three come from the same source: the field study in
[`../research/2026-08-doctor-field-study.md`](../research/2026-08-doctor-field-study.md),
which ran `truss:doctor` against twelve real open-source Laravel applications.
Read that first if any of these decisions look arbitrary.

## Status values

- **Proposed**: written, not approved, no code changed.
- **Accepted**: approved. Implementation may still be pending, and shipping is a separate question; the ADR says where it stands.
- **Superseded by NNNN**: replaced. The file stays.
