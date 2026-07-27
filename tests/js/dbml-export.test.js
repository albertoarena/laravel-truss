import { describe, it, expect } from 'vitest';
import { schemaToDbml } from '../../resources/js/dbml-export.js';
import { users, posts, roleUser } from './fixtures.js';

describe('schemaToDbml', () => {
  it('emits a Table block with a single-column [pk]', () => {
    const dbml = schemaToDbml([users]);
    expect(dbml).toContain('Table users {');
    expect(dbml).toMatch(/id "bigint unsigned" \[pk\]/);
  });

  it('quotes types with spaces but leaves simple types bare', () => {
    const dbml = schemaToDbml([users]);
    expect(dbml).toContain('"bigint unsigned"');
    expect(dbml).toMatch(/name varchar\(255\)(?!")/);
  });

  it('renders a composite PK as an indexes block', () => {
    const dbml = schemaToDbml([roleUser]);
    expect(dbml).toContain('indexes {');
    expect(dbml).toContain('(role_id, user_id) [pk]');
  });

  it('marks non-nullable non-key columns [not null] and leaves nullable ones bare', () => {
    const t = {
      name: 't',
      columns: [
        { name: 'a', type: 'integer', nullable: false, default: null },
        { name: 'b', type: 'integer', nullable: true, default: null },
      ],
      primary_key: [], indexes: [], foreign_keys: [],
    };
    const dbml = schemaToDbml([t]);
    expect(dbml).toMatch(/a integer \[not null\]/);
    expect(dbml).toMatch(/b integer(?!\s*\[)/);
  });

  it('renders a default setting', () => {
    const t = {
      name: 't',
      columns: [{ name: 'n', type: 'integer', nullable: false, default: '0' }],
      primary_key: [], indexes: [], foreign_keys: [],
    };
    expect(schemaToDbml([t])).toContain('default: 0');
  });

  it('emits a Ref per foreign key with the correct direction', () => {
    expect(schemaToDbml([users, posts])).toContain('Ref: posts.user_id > users.id');
  });

  it('renders a composite foreign key with parenthesised columns', () => {
    const x = {
      name: 'x',
      columns: [
        { name: 'a', type: 'integer', nullable: false, default: null },
        { name: 'b', type: 'integer', nullable: false, default: null },
      ],
      primary_key: [], indexes: [],
      foreign_keys: [{ name: 'fk', columns: ['a', 'b'], references_table: 'y', references_columns: ['c', 'd'], on_update: null, on_delete: null }],
    };
    const y = {
      name: 'y',
      columns: [
        { name: 'c', type: 'integer', nullable: false, default: null },
        { name: 'd', type: 'integer', nullable: false, default: null },
      ],
      primary_key: ['c', 'd'], indexes: [], foreign_keys: [],
    };
    expect(schemaToDbml([x, y])).toContain('Ref: x.(a, b) > y.(c, d)');
  });

  it('drops a Ref when the referenced table is absent from the export set', () => {
    expect(schemaToDbml([posts])).not.toContain('Ref:');
  });

  it('keeps an enum as a quoted type with no Enum block', () => {
    const dbml = schemaToDbml([posts]);
    expect(dbml).toContain('"enum(\'draft\',\'published\',\'archived\')"');
    expect(dbml).not.toContain('Enum ');
  });
});
