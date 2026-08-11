import { describe, it, expect } from 'vitest';
import { matchTables } from '../../resources/js/table-match.js';

// The Focus picker's matcher. It exists because a native <select> only jumps by
// prefix, so `item` never finds `order_items` (issue #39). The toolbar filter
// already matches substrings, and these two controls have to agree.

const names = (result) => result.map((r) => r.name);

describe('matchTables', () => {
  it('returns every table, in order, for an empty query', () => {
    expect(names(matchTables(['users', 'posts'], ''))).toEqual(['users', 'posts']);
  });

  it('treats a whitespace-only query as empty', () => {
    expect(names(matchTables(['users', 'posts'], '   '))).toEqual(['users', 'posts']);
  });

  it('matches anywhere in the name, not just at the start', () => {
    expect(names(matchTables(['users', 'order_items'], 'item'))).toEqual(['order_items']);
  });

  it('ignores case on both sides', () => {
    expect(names(matchTables(['Order_Items'], 'ITEM'))).toEqual(['Order_Items']);
  });

  it('ranks an exact match first, then prefixes, then substrings', () => {
    const result = matchTables(['order_items', 'items', 'item_tags'], 'item');
    expect(names(result)).toEqual(['items', 'item_tags', 'order_items']);
  });

  it('keeps the original order within a rank', () => {
    const result = matchTables(['b_item', 'a_item', 'item_a', 'item_b'], 'item');
    expect(names(result)).toEqual(['item_a', 'item_b', 'b_item', 'a_item']);
  });

  it('returns nothing when no table matches', () => {
    expect(matchTables(['users', 'posts'], 'zzz')).toEqual([]);
  });

  it('reports the matched span so the UI can show why a row matched', () => {
    const [hit] = matchTables(['order_items'], 'item');
    expect(hit.name.slice(hit.start, hit.end)).toBe('item');
    expect(hit.start).toBe(6);
  });

  it('reports no span for an empty query', () => {
    const [hit] = matchTables(['users'], '');
    expect(hit.start).toBe(-1);
    expect(hit.end).toBe(-1);
  });

  it('treats the query as literal text, not a pattern', () => {
    // A regex-flavoured matcher would let "." match any character, so a user
    // typing a dot would get every table back.
    expect(matchTables(['users', 'posts'], '.')).toEqual([]);
    expect(names(matchTables(['v1.users'], '.'))).toEqual(['v1.users']);
  });

  it('accepts table objects as well as names', () => {
    const result = matchTables([{ name: 'order_items' }, { name: 'users' }], 'item');
    expect(names(result)).toEqual(['order_items']);
  });
});
