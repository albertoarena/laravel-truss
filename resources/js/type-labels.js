// Best-effort native-type → Laravel-style short label. This is a presentation
// convenience only: it is lossy and never written back into the schema. The
// native full type remains the source of truth (see docs/DESIGN.md). The UI
// toggles between the two; this computes the "laravel" side.

const BASE = (type) => String(type).trim().toLowerCase().match(/^[a-z0-9_]+/)?.[0] ?? '';

/**
 * @param {string} nativeType e.g. "bigint unsigned", "varchar(255)", "tinyint(1)"
 * @returns {string} e.g. "integer", "string", "boolean"
 */
export function toShortLabel(nativeType) {
  const raw = String(nativeType).trim().toLowerCase();
  const base = BASE(raw);

  // tinyint(1) is Laravel's boolean; wider tinyint is a plain integer.
  if (base === 'tinyint') {
    return /\(\s*1\s*\)/.test(raw) ? 'boolean' : 'integer';
  }

  switch (base) {
    case 'bigint':
    case 'int':
    case 'integer':
    case 'smallint':
    case 'mediumint':
      return 'integer';

    case 'char':
    case 'varchar':
    case 'nvarchar':
    case 'string':
      return 'string';

    case 'text':
    case 'tinytext':
    case 'mediumtext':
    case 'longtext':
      return 'text';

    case 'decimal':
    case 'numeric':
      return 'decimal';

    case 'float':
    case 'double':
    case 'real':
      return 'float';

    case 'timestamp':
    case 'datetime':
      return 'datetime';

    case 'date':
      return 'date';

    case 'time':
      return 'time';

    case 'bool':
    case 'boolean':
      return 'boolean';

    case 'json':
    case 'jsonb':
      return 'json';

    case 'uuid':
      return 'uuid';

    default:
      return base;
  }
}
