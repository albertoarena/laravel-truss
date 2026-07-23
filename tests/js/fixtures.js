// Schema fixtures shaped exactly like the /api/schema `tables[]` payload
// (see docs/DESIGN.md output shape). Shared across the JS unit tests.

const col = (name, type, extra = {}) => ({ name, type, nullable: false, default: null, ...extra });

export const users = {
  name: 'users',
  columns: [col('id', 'bigint unsigned'), col('name', 'varchar(255)'), col('email', 'varchar(255)')],
  primary_key: ['id'],
  indexes: [],
  foreign_keys: [],
};

export const posts = {
  name: 'posts',
  columns: [col('id', 'bigint unsigned'), col('user_id', 'bigint unsigned'), col('title', 'varchar(255)'), col('published_at', 'timestamp', { nullable: true })],
  primary_key: ['id'],
  indexes: [],
  foreign_keys: [
    { name: 'posts_user_id_foreign', columns: ['user_id'], references_table: 'users', references_columns: ['id'], on_update: null, on_delete: 'cascade' },
  ],
};

export const roles = {
  name: 'roles',
  columns: [col('id', 'bigint unsigned'), col('name', 'varchar(255)')],
  primary_key: ['id'],
  indexes: [],
  foreign_keys: [],
};

// Pivot: composite primary key whose columns are BOTH foreign keys.
export const roleUser = {
  name: 'role_user',
  columns: [col('role_id', 'bigint unsigned'), col('user_id', 'bigint unsigned')],
  primary_key: ['role_id', 'user_id'],
  indexes: [],
  foreign_keys: [
    { name: 'role_user_role_id_foreign', columns: ['role_id'], references_table: 'roles', references_columns: ['id'], on_update: null, on_delete: 'cascade' },
    { name: 'role_user_user_id_foreign', columns: ['user_id'], references_table: 'users', references_columns: ['id'], on_update: null, on_delete: 'cascade' },
  ],
};

// Self-referential foreign key.
export const categories = {
  name: 'categories',
  columns: [col('id', 'bigint unsigned'), col('parent_id', 'bigint unsigned', { nullable: true })],
  primary_key: ['id'],
  indexes: [],
  foreign_keys: [
    { name: 'categories_parent_id_foreign', columns: ['parent_id'], references_table: 'categories', references_columns: ['id'], on_update: null, on_delete: 'set null' },
  ],
};

export const schema = [users, posts, roles, roleUser, categories];
