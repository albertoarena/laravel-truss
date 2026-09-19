---
paths:
  - "CHANGELOG.md"
---

# Release process

Loaded when `CHANGELOG.md` is touched, which is the anchor of cutting a release.
Follow these steps in order. Never skip the CI gate.

## Two gates at merge time, not at release time

**Neither of these is a release step.** Both are things a pull request must carry
before it merges, and both are here because doing them at tag time is doing them
under time pressure, which is when they get dropped or done badly.

### The changelog gate

**A pull request that changes user-facing behaviour does not merge until it has
added its entry under `## [Unreleased]`.** Same test as the docs gate below, and
the same reason: an entry written at release time is written from a diff, by
somebody reconstructing intent, days later.

**This was missed on the HTML export PR** (#79, 19/09/2026), which shipped a new
export format, a new flag and two new usage errors while `[Unreleased]` stayed
empty. It was caught in review rather than by this file, for a reason worth
keeping: **this rule loads on `CHANGELOG.md`, so it only appears once somebody
has already remembered to open it.** A feature PR that never touches the file
never sees the rule that tells it to. The root `CLAUDE.md` therefore carries the
requirement in one line, since it is always in context, and this section carries
the detail.

**Write it as Added, Changed or Fixed by what a reader of the last release
experiences, not by what the branch did.** A bug introduced and fixed inside the
same unreleased cycle gets no `Fixed` line: that line tells people the previous
version was broken, and they read it to decide whether they are affected. Where
such a fix leaves a deliberate, observable behaviour, document the behaviour
under the feature that owns it.

### The docs gate

**A pull request that changes user-facing behaviour does not merge until its
documentation is written.** Either a PR is open against
`albertoarena/laravel-truss-docs` covering the change, or the PR body says in one
line why the docs site needs nothing.

Checking that box at release time is too late. The tag is usually being cut under
time pressure, and writing a guide is exactly what gets dropped to get it out.
Opening the docs PR alongside the code PR also means the two can be reviewed
against each other, while the behaviour is still fresh.

User-facing means anything a reader of the site could notice: a new or changed
command, a config key, a payload or API shape, a route, or a visible change to
what the dashboard shows. A refactor, an internal rename or a test-only change
needs nothing.

The steps below then only have to confirm the docs PR merged, which is a yes or
no question rather than a writing task.

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

   **This step moves entries; it does not write them.** If `[Unreleased]` is
   empty and the release has content, the changelog gate above was missed, and
   the honest fix is to write the entries from the merged pull requests before
   going further rather than from `git log --oneline`. A changelog reconstructed
   from subjects reads like a list of commits, which is the thing a changelog
   exists not to be.

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

9. **Confirm the site documents what shipped, not just which version.** Step 8 is
   a constant; this is the content. Walk the release's changelog entries and
   check each user-facing one has somewhere on trussphp.com that says so: the
   configuration reference for a config key, the command reference for a command,
   the relevant guide for behaviour a reader would notice. Where the docs gate
   above was honoured this is a five-minute read. Where it was not, this is where
   the release stops rather than where the writing starts.

   Review the built site, not only the diff. The two layout paths mean a page can
   read correctly in source and render wrong.

10. **Update the public roadmap.** In the docs repo, `src/data/roadmap.ts` moves
    whatever the release delivered into Shipped with its version, splitting a
    partially delivered item rather than moving it whole. This is in the root
    `CLAUDE.md` too; it is repeated here because it is part of finishing a
    release rather than a separate chore.

Conventions that always apply: commit subjects `type: short subject` (max 50
chars) with a why-not-how body; no "Generated with Claude Code" or
"Co-Authored-By: Claude"; no em or en dashes anywhere (see the root `CLAUDE.md`
and `CLAUDE.local.md`).
