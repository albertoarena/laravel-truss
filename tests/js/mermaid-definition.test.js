import { describe, it, expect } from 'vitest';
import { generateErDiagram } from '../../resources/js/mermaid-definition.js';
import { users, posts, roles, roleUser, categories } from './fixtures.js';

describe('generateErDiagram', () => {
  it('opens with the erDiagram keyword and an entity block per table', () => {
    const out = generateErDiagram([users, posts]);
    expect(out.startsWith('erDiagram')).toBe(true);
    expect(out).toContain('users {');
    expect(out).toContain('posts {');
  });

  it('derives the PK badge from primary_key and the FK badge from foreign_keys[].columns', () => {
    const out = generateErDiagram([users, posts]);
    expect(out).toMatch(/id\s+PK/);
    expect(out).toMatch(/user_id\s+FK/);
  });

  it('marks a column that is both primary and foreign with PK, FK', () => {
    const out = generateErDiagram([roleUser, roles, users]);
    expect(out).toMatch(/role_id\s+PK,\s*FK/);
    expect(out).toMatch(/user_id\s+PK,\s*FK/);
  });

  it('sanitizes native types into Mermaid-safe tokens (no spaces or parens)', () => {
    const out = generateErDiagram([posts]);
    // The attribute-type slot must be a single token: no spaces, no parentheses.
    expect(out).not.toMatch(/bigint unsigned/);
    expect(out).not.toMatch(/varchar\(255\)/);
    expect(out).toContain('bigint_unsigned');
    expect(out).toContain('varchar_255');
  });

  it('compacts enum/set to the keyword so long value lists do not blow out the column', () => {
    const out = generateErDiagram([posts]);
    expect(out).toMatch(/enum\s+status/);
    expect(out).not.toContain('draft'); // enum values never reach the label
    expect(out).not.toContain('archived');
  });

  it('renders Laravel-style short labels when typeLabels is "laravel"', () => {
    const out = generateErDiagram([posts], { typeLabels: 'laravel' });
    expect(out).toMatch(/string\s+title/);
    expect(out).toMatch(/integer\s+id/);
    expect(out).not.toContain('varchar_255');
  });

  it('emits a crow\'s-foot relationship for a foreign key within the subset', () => {
    const out = generateErDiagram([users, posts]);
    expect(out).toContain('users ||--o{ posts');
    expect(out).toContain('posts_user_id_foreign');
  });

  it('omits relationships whose referenced table is not in the subset (no phantom entities)', () => {
    // posts alone: its FK points at users, which is absent — no relationship line.
    const out = generateErDiagram([posts]);
    expect(out).not.toContain('||--o{');
    expect(out).not.toContain('users {');
  });

  it('renders a self-referential relationship', () => {
    const out = generateErDiagram([categories]);
    expect(out).toContain('categories ||--o{ categories');
  });
});
