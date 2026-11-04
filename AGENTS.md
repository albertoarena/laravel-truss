# AGENTS.md: instructions for AI coding agents

These rules apply to every AI agent that works in this repository. `CLAUDE.md` holds the project conventions. A gitignored `AGENTS.local.md`, when present, adds the maintainer's private rules.

## Confidentiality and clarity pass

Before every commit, every pull request and every change to a pull request body, do two passes. Also do the confidentiality pass before anything is posted: issue comments, release notes, discussions and messages to external services. Do the passes on the staged diff and on the message text.

### Clarity pass

Read the text as a new reader.

- Write commit messages and pull requests in ASD-STE100 Simplified Technical English (STE).
- Write one topic in each sentence. Use a maximum of 20 words in an instruction and 25 words in a description.
- Use the active voice and simple tenses.
- Use one word for one meaning in the whole repository (for example "license", not also "licence").
- Do not use a phrasal verb when a single verb is available ("remove", not "take out").
- Keep the articles ("the", "a"). Do not use slang or idioms.
- Use plain English that a non-native speaker can read easily. Do not over-explain.
- Do not use em dashes or en dashes.

### Confidentiality pass

- Do not name a repository, except this one, its sibling public repository and its dependencies. The two public repositories are `albertoarena/laravel-truss` and `albertoarena/laravel-truss-docs`.
- Do not name a person, except the copyright holder and a contributor who is credited by GitHub handle in the changelog or release notes.
- Do not name an AI session or an AI review, and do not give an evaluation of a tool or company.
- Do not add secrets, private notes, personal data or local paths.
- Do not add AI attribution: no "Generated with" footer and no AI co-author trailer.
- The only public contact address is `hello@albertoarena.it`.
- These rules also apply to code comments, fixtures and test names.
- Allowed: Laravel, PHP, the databases, and other public published tools and packages.

### Mechanical checks

- Before a commit, run `git diff --cached --name-only` and read the list. Do not commit generated or local files.
- Before a commit, run `git grep --cached -nE '/(Users|home)/[A-Za-z]'`. It must find nothing.
- Before a push, run `git log --format='%an <%ae>%n%B' origin/main..HEAD`. No commit can carry a trailer.

### Show the result

Show the maintainer the exact commit message or pull request text. Add one line for each pass, for example "Clarity pass: found licence/license mix, fixed" or "Confidentiality pass: no changes needed". If a pass finds a problem, fix it and show the fixed text with the finding. Do not commit, push or post until the maintainer says yes.
