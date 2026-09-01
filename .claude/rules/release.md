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

   **The title always carries the summary**, never a bare `vX.Y.Z`. Same shape as
   the tag message: the version, a colon, then what the release is about in a few
   words, lowercase after the colon, no em or en dashes. It is what people read in
   the releases list and in a notification, and a bare version number tells them
   nothing. v1.8.1 shipped bare by mistake and was renamed afterwards with
   `gh release edit vX.Y.Z --title "..."`, which rewrites the title without
   touching the tag or the notes, so a bad title is fixable but better avoided.
   Confirm the exact title with the maintainer before publishing, along
   with the notes, since a release is outward facing and awkward to restate.

7. **Post-release.** Packagist picks up the new tag on its webhook sync (its
   "Update" button forces it); this is automatic, just verify. No manual version
   bump exists in `composer.json`; the git tag is the source of truth. The docs
   site is a separate repo (`albertoarena/laravel-truss-docs`) and does NOT
   redeploy on a package release: its live demo is pinned to the latest release
   tag, so trigger a docs rebuild there (push or dispatch its Publish workflow)
   to pull the newly released frontend into the demo.

8. **Bump the docs site's version constant, every single release.** In
   `albertoarena/laravel-truss-docs`, `src/config/package.js` holds
   `PACKAGE_VERSION` as a hand-maintained literal, and the landing page badge
   and the structured data both render from it. It does **not** follow the tag.
   Leave it and trussphp.com advertises the previous release to every visitor.

   **This has now happened twice**, at v1.10.0 and again at v1.11.0, so treat it
   as part of the release rather than as tidying afterwards. The release is not
   done until that constant matches the tag you just pushed.

   The repo does guard it: `tests/structured-data.test.js` compares the constant
   against `.demo-asset-version`, the tag the prebuild resolved from
   `releases/latest`, and fails loudly. **Do not rely on the guard to stop a bad
   deploy.** Its `CI` job and its `Publish` job are independent, so on v1.11.0 CI
   went red while Publish succeeded and the stale badge shipped anyway. The guard
   tells you afterwards; only doing the bump prevents it.

   Order matters, for the reason in step 7: publish the package release first, so
   the docs prebuild resolves the new tag, then bump the constant and let the
   rebuild carry both.

Conventions that always apply: commit subjects `type: short subject` (max 50
chars) with a why-not-how body; no "Generated with Claude Code" or
"Co-Authored-By: Claude"; no em or en dashes anywhere (see the root `CLAUDE.md`
and `CLAUDE.local.md`).
