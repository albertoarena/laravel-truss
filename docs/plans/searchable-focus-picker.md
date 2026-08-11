# Plan: Searchable Focus picker

Status: BUILT 2026-08-11 on `feat/accessibility-phase-1` (PR #41), shipping with the accessibility work as one feature. Unreleased.
Owner: Alberto Arena
Issue: [#39](https://github.com/albertoarena/laravel-truss/issues/39), reported by
Alberto Peripolli ([@trippo](https://github.com/trippo)) from a schema of roughly 200
tables. Credit him in the CHANGELOG entry and the release notes when this ships.
Related: `resources/js/truss.js` (the control, its five programmatic setters, and the
shared overlay machinery), `resources/views/index.blade.php` (toolbar markup),
`resources/css/truss.css`, `.claude/rules/frontend.md`

## Goal

Make the Focus control searchable: type to narrow the table list, matching anywhere in
the name so `item` finds `order_items`, with visible feedback about what matched. The
toolbar filter (`filterTables()`) already does substring matching, so this makes the two
controls behave the same way instead of contradicting each other.

## Current behaviour

`populateFocusOptions()` (`resources/js/truss.js:1065`) rebuilds a native `<select>` with
one `<option>` per table plus a `- none -` entry. At 200 tables that is a 200-item
scrolling list whose only search is the browser's built-in prefix jump, which matches from
the start of the name only and shows nothing about what it matched.

## Agreed requirements

1. **Standalone fix, not a command palette.** The roadmap's "Large-schema navigation"
   (Cmd-K jump, saved views, domain grouping) stays in Exploring. A palette can absorb
   this later: the matcher and the listbox are the same pieces either way, so nothing
   built here is wasted.
2. **Always replace the native select**, at every schema size. One control to learn, one
   code path to test. The cost is the native mobile picker on small schemas, accepted
   deliberately rather than switching behaviour at `truss.large_schema.warn_above` and
   making people learn two controls.
3. **A custom listbox**, not `<input list>` plus `<datalist>`. Datalist substring matching
   is browser-dependent (Chrome matches anywhere, others match prefixes), so it would not
   reliably deliver the one thing the issue asks for.

## Design

### Markup (`index.blade.php`)

Replace the `<select id="truss-focus">` inside `#truss-more` with an ARIA combobox:

```html
<div class="truss-field truss-combo" id="truss-focus-combo">
    <span class="truss-field-label" id="truss-focus-label">Focus</span>
    <input id="truss-focus" type="text" role="combobox" autocomplete="off"
           aria-expanded="false" aria-controls="truss-focus-list"
           aria-labelledby="truss-focus-label" placeholder="none">
    <ul id="truss-focus-list" role="listbox" aria-labelledby="truss-focus-label" hidden></ul>
</div>
```

The id `truss-focus` is kept so existing selectors keep resolving, and the element stays
inside `#truss-more` so the `⋯` secondary panel still governs its visibility.

A dedicated `<ul>`, not the shared `#truss-popover`. The shared popover is claimed by enum
lists, the per-table menu, the export menu, and health findings, and is dismissed on wheel
and zoom; a combobox has to stay open while typing. It still registers with
`closeOverlays()` so opening the Legend, Health, or Changes panel closes it.

### Matching

A pure function in a new `resources/js/table-match.js`, unit-testable without a DOM:

- case-insensitive substring match on the table name;
- results ranked: exact match, then prefix matches, then substring matches, each group in
  the schema's existing order, so `item` yields `items` before `order_items`;
- empty query lists every table;
- the matched span is marked for highlighting, so the UI can show *why* a row matched,
  which is the feedback the native select never gave.

No fuzzy matching. Substring is what the toolbar filter does, and consistency between the
two controls is the point of the issue.

### Behaviour

- Typing filters the list live and opens it. `ArrowDown`/`ArrowUp` move the active option
  (wrapping), `Home`/`End` jump, `Enter` commits, `Escape` closes and reverts the text to
  the current focus, `Tab` closes and commits nothing. `aria-activedescendant` tracks the
  active option so screen readers announce it.
- Clicking an option commits it. Clicking outside closes and reverts.
- The input is free text, so it is validated on commit: a value that is not a real table
  name never sets `state.focusRoot`, and the input reverts to the current focus on blur.
- A `Clear focus` row at the top of the list replaces the `- none -` option, wording that
  matches the empty-state action shipped in v1.8.1.

### The five programmatic setters

`el.focus.value = name` is currently assigned from five places: the per-table menu
(`truss.js:356`), the Changes panel (`:630`), the Health panel (`:898`), `clearFocus()`
(`:112`), and `populateFocusOptions()` after a URL deep link (`:1069`). Introduce one
`setFocus(name)` that updates `state.focusRoot`, the input's text, and the listbox
selection together, and route all five through it. This is the refactor that stops the
rest of the app depending on the control being a `<select>`, and it lands before the
widget does.

## TDD order

Per the mandatory ordering rule, each step is a failing test first, then the code.

1. **Vitest, `tests/js/table-match.test.js`:** substring matching, ranking, empty query,
   case-insensitivity, and the highlight span. Then write `table-match.js`.
2. **Vitest, extend the existing suite:** `setFocus()` updates state and input together,
   and rejects a name that is not in the schema. Then do the five-call-site refactor,
   which must stay green against the current `<select>`.
3. **Playwright, `tests/e2e/toolbar.spec.js`:** open, type, narrow, commit with `Enter`,
   commit with a click, `Escape` reverts, blur on an invalid value reverts, `Clear focus`
   clears, and the combobox closes when the Legend opens. Then build the widget.
4. **Playwright, accessibility:** `aria-expanded` flips, `aria-activedescendant` follows
   the arrow keys, options carry `role="option"` and `aria-selected`. This is the step
   that proves the custom widget is not a regression on the native select.
5. **Playwright, large schema:** `tests/e2e/large-schema.spec.js` already builds a
   50-plus-table fixture; assert the type-to-narrow path there rather than only on the
   small fixture.

### Test migration (do not underestimate this)

Sixteen existing Playwright assertions drive the control as a native select:
`page.selectOption('#truss-focus', ...)` and `toHaveValue()` across `truss.spec.js`,
`large-schema.spec.js`, `health.spec.js`, and `schema-diff.spec.js`. `selectOption` throws
on a non-select element, so every one of them has to move to a helper
(`selectFocus(page, name)`) in the same change. Add the helper first, migrate the specs to
it while they still pass against the select, then swap the widget in. `tests/e2e/toolbar.html`
carries its own copy of the toolbar markup and needs the same change.

## Docs to update in the same change

- `README.md`: the Focus control description, if it names a dropdown.
- `docs/DESIGN.md`: the new `table-match.js` module and the combobox in the toolbar
  inventory.
- Docs site (`albertoarena/laravel-truss-docs`, separate repo): any screenshot or copy
  showing the Focus dropdown, plus the demo page's hand-authored toolbar markup, which
  does not inherit changes from this repo automatically. Lands with the release, since the
  site reads the latest release tag.

## Risks and non-goals

- **Accessibility is the main risk.** A native select gives keyboard, screen-reader, and
  mobile behaviour for free; a hand-rolled combobox only has what it implements. Step 4
  exists to make that explicit rather than assumed. If the ARIA work does not land
  cleanly, the honest fallback is to keep the select and reconsider.
- **Mobile loses the OS picker.** Accepted under requirement 2. The input is a real text
  input, so the on-screen keyboard and tap targets still work.
- **No fuzzy or multi-term matching**, no recent-tables list, no grouping by prefix. Those
  belong to the palette item on the roadmap, not here.
- **The Depth control is untouched**, even though it sits beside Focus.

## Credit

Ship the CHANGELOG entry and release notes with a line thanking Alberto Peripolli
(@trippo), following the v1.8.1 pattern: identical wording in both places, appended to the
end of the entry.

## Built

Delivered as planned, with two deviations worth recording:

- The widget lives in its own module (`resources/js/focus-combobox.js`) rather than inside
  `truss.js`, which was already 1300 lines. The matcher is `resources/js/table-match.js` as
  planned.
- Two bugs the specs caught, both about reopening the list on a committed value. Opening
  while a table is focused used to filter the list by the name already in the box, so the
  only row visible was the one you had chosen and Clear focus was filtered away; opening now
  browses the whole list and selects the text so typing replaces it. And committing with the
  mouse leaves focus in the input (the list suppresses the blur), so a second click fired no
  `focus` event and never reopened the list; `click` now opens it too.

`AssetController`'s allow-list needed both new modules added. The existing
`it serves every shipped JS module` test caught that, which would otherwise have been a
404 on the shipped dashboard while every browser test passed.

Tests: `tests/e2e/focus-picker.spec.js` (18 specs), `tests/js/table-match.test.js` (11),
and the fourteen migrated assertions now going through `tests/e2e/focus-helper.js`.
