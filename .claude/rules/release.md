---
paths:
  - "CHANGELOG.md"
---

# Release process

Loaded when `CHANGELOG.md` is touched, which is the anchor of cutting a release.
Follow these steps in order. Never skip the CI gate.

1. **CI and tests must pass first.** Run the full local suite and confirm green:
   `composer test`, `composer lint`, `npm test`, `npx playwright test`. Then
   confirm the GitHub CI checks are green on the commit being released:
   `gh run list` or
   `gh api repos/albertoarena/laravel-truss/commits/<sha>/check-runs`. Do not
   release on a red or still-running pipeline.

2. **Pick the version (SemVer).** Patch for fixes, minor for backward-compatible
   features, major for breaking changes. The last tag is the baseline
   (`git tag --sort=-v:refname | head -1`).

3. **Update `CHANGELOG.md`.** Keep a Changelog format: add a
   `## [X.Y.Z] - YYYY-MM-DD` section (real date), move the relevant Unreleased
   notes into it under `### Added` / `### Changed` / `### Fixed`. Keep an empty
   `## [Unreleased]` at the top. No em or en dashes in the prose.

4. **Commit.** Feature/fix commits land first with their own `type: subject`
   messages. The changelog bump is its own commit: `chore: release vX.Y.Z`, with
   a short body. Never add Claude attribution. Use a heredoc for the message.

5. **Tag.** Annotated tag on the release commit:
   `git tag -a vX.Y.Z -m "vX.Y.Z: short summary"` (no em or en dashes in the
   message). Then push both: `git push origin main` and `git push origin vX.Y.Z`.

6. **Create the GitHub release.** From the changelog section:
   `gh release create vX.Y.Z --title "vX.Y.Z: short summary" --notes-file <file>`.
   The notes mirror the changelog entry. Not a draft, not a prerelease unless
   asked. Verify with `gh release view vX.Y.Z`.

7. **Post-release.** Packagist picks up the new tag on its webhook sync (its
   "Update" button forces it); this is automatic, just verify. No manual version
   bump exists in `composer.json`; the git tag is the source of truth. The docs
   site is a separate repo (`albertoarena/laravel-truss-docs`) and does NOT
   redeploy on a package release: its live demo is pinned to the latest release
   tag, so trigger a docs rebuild there (push or dispatch its Publish workflow)
   to pull the newly released frontend into the demo.

Conventions that always apply: commit subjects `type: short subject` (max 50
chars) with a why-not-how body; no "Generated with Claude Code" or
"Co-Authored-By: Claude"; no em or en dashes anywhere (see the root `CLAUDE.md`
and `CLAUDE.local.md`).
