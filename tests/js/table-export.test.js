import { describe, it, expect } from 'vitest';
import { toJson, toCsv } from '../../resources/js/table-export.js';
import { roleUser, users } from './fixtures.js';

describe('toJson', () => {
  it('serializes the full table structure', () => {
    const parsed = JSON.parse(toJson(users));
    expect(parsed.name).toBe('users');
    expect(parsed.primary_key).toEqual(['id']);
    expect(parsed.columns.map((c) => c.name)).toContain('email');
  });
});

describe('toCsv', () => {
  it('has a header and one row per column', () => {
    const lines = toCsv(users).split('\n');
    expect(lines[0]).toBe('name,type,nullable,default,key');
    expect(lines).toHaveLength(1 + users.columns.length);
  });

  it('derives PK and FK in the key column', () => {
    const csv = toCsv(roleUser); // composite PK whose columns are both FKs
    expect(csv).toMatch(/role_id,[^\n]*"PK, FK"/);
    expect(csv).toMatch(/user_id,[^\n]*"PK, FK"/);
  });

  it('escapes values containing a comma or quote', () => {
    const table = {
      name: 't',
      columns: [{ name: 'c', type: "enum('a','b')", nullable: false, default: null }],
      primary_key: [], indexes: [], foreign_keys: [],
    };
    expect(toCsv(table)).toContain('"enum(\'a\',\'b\')"');
  });
});
