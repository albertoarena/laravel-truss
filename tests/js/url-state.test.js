import { describe, it, expect } from 'vitest';
import { buildQuery, parseQuery } from '../../resources/js/url-state.js';

describe('buildQuery', () => {
  it('is empty when nothing is set', () => {
    expect(buildQuery({})).toBe('');
    expect(buildQuery({ filter: '', focus: '', labels: false })).toBe('');
  });

  it('serializes only the non-empty fields', () => {
    expect(buildQuery({ focus: 'projects' })).toBe('?focus=projects');
    expect(buildQuery({ filter: 'user' })).toBe('?filter=user');
  });

  it('serializes a full view', () => {
    const qs = buildQuery({ connection: 'mysql', filter: 'proj', focus: 'projects', depth: 2, labels: true });
    const p = new URLSearchParams(qs);
    expect(p.get('connection')).toBe('mysql');
    expect(p.get('filter')).toBe('proj');
    expect(p.get('focus')).toBe('projects');
    expect(p.get('depth')).toBe('2');
    expect(p.get('labels')).toBe('laravel');
  });

  it('omits depth when null and labels when false', () => {
    expect(buildQuery({ focus: 'x', depth: null, labels: false })).toBe('?focus=x');
  });
});

describe('parseQuery', () => {
  it('returns defaults for an empty query', () => {
    expect(parseQuery('')).toEqual({ connection: null, filter: '', focus: '', depth: null, labels: false });
  });

  it('reads each field', () => {
    const v = parseQuery('?connection=mysql&filter=proj&focus=projects&depth=2&labels=laravel');
    expect(v).toEqual({ connection: 'mysql', filter: 'proj', focus: 'projects', depth: 2, labels: true });
  });

  it('ignores a non-numeric depth', () => {
    expect(parseQuery('?depth=abc').depth).toBeNull();
  });

  it('round-trips through buildQuery', () => {
    const view = { connection: 'reporting', filter: 'a b', focus: 'orders', depth: 3, labels: true };
    expect(parseQuery(buildQuery(view))).toEqual(view);
  });
});
