import { describe, it, expect } from 'vitest';
import { toShortLabel } from '../../resources/js/type-labels.js';

describe('toShortLabel — best-effort native → Laravel-style label', () => {
  it('maps integer family (incl. unsigned) to integer', () => {
    expect(toShortLabel('bigint unsigned')).toBe('integer');
    expect(toShortLabel('int')).toBe('integer');
    expect(toShortLabel('integer')).toBe('integer');
    expect(toShortLabel('smallint')).toBe('integer');
    expect(toShortLabel('mediumint')).toBe('integer');
  });

  it('maps tinyint(1) to boolean but wider tinyint to integer', () => {
    expect(toShortLabel('tinyint(1)')).toBe('boolean');
    expect(toShortLabel('tinyint')).toBe('integer');
  });

  it('maps char/varchar families to string', () => {
    expect(toShortLabel('varchar(255)')).toBe('string');
    expect(toShortLabel('char(36)')).toBe('string');
  });

  it('maps text families to text', () => {
    expect(toShortLabel('text')).toBe('text');
    expect(toShortLabel('longtext')).toBe('text');
  });

  it('maps decimal/numeric to decimal and float/double to float', () => {
    expect(toShortLabel('decimal(8,2)')).toBe('decimal');
    expect(toShortLabel('numeric(10,0)')).toBe('decimal');
    expect(toShortLabel('double')).toBe('float');
    expect(toShortLabel('float')).toBe('float');
  });

  it('maps temporal, boolean, json, uuid', () => {
    expect(toShortLabel('timestamp')).toBe('datetime');
    expect(toShortLabel('datetime')).toBe('datetime');
    expect(toShortLabel('date')).toBe('date');
    expect(toShortLabel('boolean')).toBe('boolean');
    expect(toShortLabel('json')).toBe('json');
    expect(toShortLabel('uuid')).toBe('uuid');
  });

  it('falls back to the base token for anything unrecognized', () => {
    expect(toShortLabel('geometry')).toBe('geometry');
    expect(toShortLabel('some_weird(1,2)')).toBe('some_weird');
  });
});
