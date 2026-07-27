import { describe, it, expect } from 'vitest';
import { tableToMarkdown, schemaToMarkdown } from '../../resources/js/markdown-export.js';
import { users, posts, roleUser, schema } from './fixtures.js';

describe('tableToMarkdown', () => {
  it('renders a heading and the column table', () => {
    const md = tableToMarkdown(users);
    expect(md).toContain('## users');
    expect(md).toContain('| Column | Type | Null | Default | Key |');
    expect(md).toContain('| --- | --- | --- | --- | --- |');
    const rows = md
      .split('\n')
      .filter((l) => l.startsWith('| ') && !l.includes('Column') && !l.includes('---'));
    expect(rows).toHaveLength(users.columns.length);
  });

  it('marks the primary key in the Key column', () => {
    expect(tableToMarkdown(users)).toMatch(/\|\s*id\s*\|[^\n]*\|\s*PK\s*\|/);
  });

  it('shows a composite PK+FK column as "PK, FK"', () => {
    const md = tableToMarkdown(roleUser);
    expect(md).toMatch(/\|\s*role_id\s*\|[^\n]*\|\s*PK, FK\s*\|/);
    expect(md).toMatch(/\|\s*user_id\s*\|[^\n]*\|\s*PK, FK\s*\|/);
  });

  it('renders nullability as yes/no', () => {
    const md = tableToMarkdown(posts);
    expect(md).toMatch(/\|\s*published_at\s*\|[^\n]*\|\s*yes\s*\|/);
    expect(md).toMatch(/\|\s*id\s*\|[^\n]*\|\s*no\s*\|/);
  });

  it('renders a default value when present', () => {
    const t = {
      name: 't',
      columns: [{ name: 'active', type: 'tinyint(1)', nullable: false, default: '1' }],
      primary_key: [], indexes: [], foreign_keys: [],
    };
    expect(tableToMarkdown(t)).toMatch(/\|\s*active\s*\|[^\n]*\|\s*1\s*\|\s*\|/);
  });

  it('lists foreign keys with their referential action', () => {
    const md = tableToMarkdown(posts);
    expect(md).toContain('user_id -> users.id');
    expect(md).toMatch(/on delete: cascade/);
  });

  it('lists indexes and flags UNIQUE', () => {
    const t = {
      name: 't',
      columns: [{ name: 'email', type: 'varchar(255)', nullable: false, default: null }],
      primary_key: [], indexes: [{ name: 't_email_unique', columns: ['email'], unique: true }],
      foreign_keys: [],
    };
    const md = tableToMarkdown(t);
    expect(md).toContain('t_email_unique (email)');
    expect(md).toContain('UNIQUE');
  });

  it('escapes a pipe inside a value', () => {
    const t = {
      name: 't',
      columns: [{ name: 'c', type: "enum('a|b')", nullable: false, default: null }],
      primary_key: [], indexes: [], foreign_keys: [],
    };
    expect(tableToMarkdown(t)).toContain("enum('a\\|b')");
  });
});

describe('schemaToMarkdown', () => {
  it('has a top title and one section per table', () => {
    const md = schemaToMarkdown(schema);
    expect(md).toContain('# Data dictionary');
    const sections = md.split('\n').filter((l) => l.startsWith('## '));
    expect(sections).toHaveLength(schema.length);
  });

  it('includes a connection line when provided', () => {
    expect(schemaToMarkdown(schema, { connection: 'mysql' })).toContain('Connection: mysql');
  });

  it('renders the title only for an empty schema', () => {
    const md = schemaToMarkdown([]);
    expect(md).toContain('# Data dictionary');
    expect(md).not.toContain('## ');
  });
});
