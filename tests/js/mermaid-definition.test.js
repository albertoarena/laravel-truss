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

  it('shows a self-referential foreign key as a column note, not a self-loop edge', () => {
    // Mermaid draws self-loops as a large sweeping curve, so we drop the edge
    // and mark the referencing column with a "self-ref" note instead.
    const out = generateErDiagram([categories]);
    expect(out).not.toContain('categories ||--o{ categories');
    expect(out).toMatch(/parent_id\s+FK\s+"self-ref"/);
  });
});

// WCAG 1.1.1: the rendered SVG carries role="graphics-document" but has no
// accessible name unless the definition declares one. Mermaid turns accTitle
// into <title> and accDescr into <desc>, so assistive technology gets a name
// and an orientation summary instead of an unlabelled graphic.
describe('generateErDiagram accessibility directives', () => {
  it('names the diagram with accTitle, immediately after the erDiagram keyword', () => {
    const lines = generateErDiagram([users, posts]).split('\n');
    expect(lines[0]).toBe('erDiagram');
    expect(lines[1]).toMatch(/^\s*accTitle:\s*\S/);
  });

  it('describes the diagram by table and relationship count', () => {
    const out = generateErDiagram([users, posts]);
    expect(out).toMatch(/accDescr:.*\b2 tables\b/);
    expect(out).toMatch(/accDescr:.*\b1 relationship\b/);
  });

  it('singularises the counts for a one-table diagram with no relationships', () => {
    const out = generateErDiagram([users]);
    expect(out).toMatch(/accDescr:.*\b1 table\b/);
    expect(out).toMatch(/accDescr:.*\bno relationships\b/);
  });

  it('states the active filter so the description matches what is on screen', () => {
    const out = generateErDiagram([posts], { view: { filter: 'pos' } });
    expect(out).toMatch(/accDescr:.*filtered by "pos"/);
  });

  it('states the focused table and its depth', () => {
    const out = generateErDiagram([users, posts], { view: { focus: 'users', depth: 2 } });
    expect(out).toMatch(/accDescr:.*focused on users, depth 2/);
  });

  it('keeps the description to a single line when the filter contains newlines', () => {
    // The filter is user input and reaches the definition verbatim; a newline
    // would terminate the directive and corrupt the rest of the diagram.
    const out = generateErDiagram([users], { view: { filter: 'a\nb\rc' } });
    const descr = out.split('\n').filter((l) => l.includes('accDescr:'));
    expect(descr).toHaveLength(1);
    expect(descr[0]).toContain('filtered by "a b c"');
  });

  it('strips quotes from the filter so the quoted value cannot be broken open', () => {
    const out = generateErDiagram([users], { view: { filter: 'a"b' } });
    expect(out).toMatch(/filtered by "ab"/);
  });

  it('mentions neither filter nor focus when the view is unconstrained', () => {
    const out = generateErDiagram([users, posts]);
    expect(out).not.toContain('filtered by');
    expect(out).not.toContain('focused on');
  });
});
