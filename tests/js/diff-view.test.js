import { describe, it, expect } from 'vitest';
import {
  hasDiff,
  diffSummary,
  tableStatuses,
  tableStatus,
  columnBadges,
  changeList,
} from '../../resources/js/diff-view.js';

// A diff shaped exactly like the API `diff` field (see SchemaDiffer output).
const diff = {
  tables_added: [{ name: 'comments' }],
  tables_removed: [{ name: 'legacy' }],
  tables_changed: [
    {
      name: 'users',
      columns_added: [{ name: 'phone', type: 'varchar(255)', nullable: true, default: null }],
      columns_removed: [{ name: 'nickname' }],
      columns_changed: [{ name: 'votes', changes: { type: { before: 'integer', after: 'bigint' } } }],
      indexes_added: [{ name: 'users_phone_index', columns: ['phone'], unique: false }],
      indexes_removed: [],
      indexes_changed: [],
      foreign_keys_added: [],
      foreign_keys_removed: [{ name: 'users_team_id_foreign' }],
      foreign_keys_changed: [],
      changes: { primary_key: { before: ['id'], after: ['id', 'uuid'] } },
    },
  ],
  has_changes: true,
  baseline_generated_at: '2026-07-01T00:00:00+00:00',
  current_generated_at: '2026-07-29T00:00:00+00:00',
};

describe('hasDiff', () => {
  it('is true only for a diff with changes', () => {
    expect(hasDiff(diff)).toBe(true);
    expect(hasDiff(null)).toBe(false);
    expect(hasDiff({ has_changes: false })).toBe(false);
  });
});

describe('diffSummary', () => {
  it('counts added, removed and changed tables', () => {
    expect(diffSummary(diff)).toEqual({ added: 1, removed: 1, changed: 1, total: 3 });
  });

  it('is all zeros for a null diff', () => {
    expect(diffSummary(null)).toEqual({ added: 0, removed: 0, changed: 0, total: 0 });
  });
});

describe('tableStatuses / tableStatus', () => {
  it('maps each table name to its change status', () => {
    const map = tableStatuses(diff);
    expect(map.get('comments')).toBe('added');
    expect(map.get('legacy')).toBe('removed');
    expect(map.get('users')).toBe('changed');
    expect(map.has('unrelated')).toBe(false);
  });

  it('returns unchanged for a table not in the diff, and for a null diff', () => {
    expect(tableStatus(diff, 'unrelated')).toBe('unchanged');
    expect(tableStatus(null, 'users')).toBe('unchanged');
    expect(tableStatus(diff, 'users')).toBe('changed');
  });
});

describe('columnBadges', () => {
  it('maps a changed table columns to their badges', () => {
    const map = columnBadges(diff, 'users');
    expect(map.get('phone')).toBe('added');
    expect(map.get('nickname')).toBe('removed');
    expect(map.get('votes')).toBe('changed');
  });

  it('is empty for a table with no column changes or a null diff', () => {
    expect(columnBadges(diff, 'comments').size).toBe(0);
    expect(columnBadges(null, 'users').size).toBe(0);
  });
});

describe('changeList', () => {
  it('flattens the diff into an ordered list model', () => {
    const list = changeList(diff);

    expect(list).toHaveLength(3);
    expect(list[0]).toEqual({ kind: 'table-added', table: 'comments' });
    expect(list[1]).toEqual({ kind: 'table-removed', table: 'legacy' });
    expect(list[2].kind).toBe('table-changed');
    expect(list[2].table).toBe('users');
  });

  it('carries the per-table change details', () => {
    const details = changeList(diff)[2].details;
    const kinds = details.map((d) => d.kind);

    expect(kinds).toContain('column-added');
    expect(kinds).toContain('column-removed');
    expect(kinds).toContain('column-changed');
    expect(kinds).toContain('index-added');
    expect(kinds).toContain('fk-removed');
    expect(kinds).toContain('primary-key-changed');

    const pk = details.find((d) => d.kind === 'primary-key-changed');
    expect(pk.before).toEqual(['id']);
    expect(pk.after).toEqual(['id', 'uuid']);
  });

  it('is empty for a null diff', () => {
    expect(changeList(null)).toEqual([]);
    expect(tableStatuses(null).size).toBe(0);
  });
});
