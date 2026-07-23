import { describe, it, expect } from 'vitest';
import { filterTables, focusTables } from '../../resources/js/selection.js';
import { schema } from './fixtures.js';

const names = (tables) => tables.map((t) => t.name).sort();

describe('filterTables — text search over table names', () => {
  it('returns every table for an empty query', () => {
    expect(names(filterTables(schema, ''))).toEqual(names(schema));
    expect(names(filterTables(schema, '   '))).toEqual(names(schema));
  });

  it('matches case-insensitively on a substring of the name', () => {
    expect(names(filterTables(schema, 'role'))).toEqual(['role_user', 'roles']);
    expect(names(filterTables(schema, 'USERS'))).toEqual(['users']);
  });

  it('returns nothing when no name matches', () => {
    expect(filterTables(schema, 'zzz')).toEqual([]);
  });
});

describe('focusTables — a table plus its FK neighbours to a given depth', () => {
  it('depth 1 returns the root and its direct FK neighbours (both directions)', () => {
    // users is referenced by posts and role_user; roles is one more hop away.
    expect(names(focusTables(schema, 'users', 1))).toEqual(['posts', 'role_user', 'users']);
  });

  it('depth 2 reaches neighbours-of-neighbours', () => {
    expect(names(focusTables(schema, 'users', 2))).toEqual(['posts', 'role_user', 'roles', 'users']);
  });

  it('treats a self-referential FK as a single node', () => {
    expect(names(focusTables(schema, 'categories', 1))).toEqual(['categories']);
  });

  it('returns just the root when it has no neighbours', () => {
    expect(names(focusTables(schema, 'roles', 0))).toEqual(['roles']);
  });

  it('returns an empty set when the root is unknown', () => {
    expect(focusTables(schema, 'nope', 1)).toEqual([]);
  });
});
