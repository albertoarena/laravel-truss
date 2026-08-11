import { describe, it, expect } from 'vitest';
import { selectTables, emptySelectionNotice } from '../../resources/js/selection.js';
import { schema } from './fixtures.js';

const names = (tables) => tables.map((t) => t.name).sort();

describe('selectTables — the filter and focus pipeline', () => {
  it('returns everything when neither filter nor focus is set', () => {
    expect(names(selectTables(schema, {}))).toEqual(names(schema));
  });

  it('applies the filter alone', () => {
    expect(names(selectTables(schema, { search: 'role' }))).toEqual(['role_user', 'roles']);
  });

  it('applies the focus alone', () => {
    expect(names(selectTables(schema, { focusRoot: 'users', depth: 1 })))
      .toEqual(['posts', 'role_user', 'users']);
  });

  it('intersects the two, which is what empties the diagram', () => {
    // roles is outside the users neighbourhood at depth 1.
    expect(selectTables(schema, { search: 'roles', focusRoot: 'users', depth: 1 })).toEqual([]);
  });
});

describe('emptySelectionNotice — why the diagram is empty, and the way out', () => {
  it('says nothing while tables are showing', () => {
    expect(emptySelectionNotice(schema, {})).toBeNull();
    expect(emptySelectionNotice(schema, { search: 'role' })).toBeNull();
    expect(emptySelectionNotice(schema, { focusRoot: 'users', depth: 1 })).toBeNull();
  });

  it('names the filter and the focus, and offers to clear the focus', () => {
    // The reported case: focus a table, then search for one outside it (issue #34).
    expect(emptySelectionNotice(schema, { search: 'roles', focusRoot: 'users', depth: 1 })).toEqual({
      message: 'No tables match "roles" within focus: users.',
      clearFocus: true,
    });
  });

  it('does not offer to clear the focus when the filter alone matches nothing', () => {
    // Clearing the focus would still leave an empty diagram, so do not suggest it.
    expect(emptySelectionNotice(schema, { search: 'zzz', focusRoot: 'users', depth: 1 })).toEqual({
      message: 'No tables match "zzz".',
      clearFocus: false,
    });
  });

  it('offers to clear a focus that matches nothing on its own', () => {
    // e.g. a deep link to a table that config excludes on this connection.
    expect(emptySelectionNotice(schema, { focusRoot: 'gone', depth: 1 })).toEqual({
      message: 'No tables match focus: gone.',
      clearFocus: true,
    });
  });

  it('reports a filter-only miss without mentioning focus', () => {
    expect(emptySelectionNotice(schema, { search: 'zzz' })).toEqual({
      message: 'No tables match "zzz".',
      clearFocus: false,
    });
  });

  it('falls back to the generic message when there is nothing to name', () => {
    expect(emptySelectionNotice([], {})).toEqual({
      message: 'No tables match the current filter or focus.',
      clearFocus: false,
    });
    expect(emptySelectionNotice([], { focusRoot: 'users' })).toEqual({
      message: 'No tables match the current filter or focus.',
      clearFocus: false,
    });
  });

  it('trims the query it quotes back', () => {
    expect(emptySelectionNotice(schema, { search: '  zzz  ' })).toEqual({
      message: 'No tables match "zzz".',
      clearFocus: false,
    });
  });
});
