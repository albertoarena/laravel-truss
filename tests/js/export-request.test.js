// Unit tests for the export-request URL builder: the pure logic that turns the
// current selection into a call to the gated server export route. Written before
// the implementation (resources/js/export-request.js).

import { describe, it, expect } from 'vitest';
import { buildExportUrl } from '../../resources/js/export-request.js';

const template = '/truss/export/__format__';
const params = (url) => new URL(url, 'http://x').searchParams;

describe('buildExportUrl', () => {
  it('returns null instead of throwing when there is no route to call', () => {
    // A dashboard served without an export route (a static page, or an install
    // that turned the route off) has no template. This used to throw a TypeError
    // from inside a promise, so the menu item did nothing at all: no file, no
    // error, nothing the person clicking it could see.
    expect(buildExportUrl(undefined, 'markdown')).toBe(null);
    expect(buildExportUrl(null, 'dbml')).toBe(null);
    expect(buildExportUrl('', 'json')).toBe(null);
  });

  it('substitutes the format into the template', () => {
    expect(buildExportUrl(template, 'dbml')).toBe('/truss/export/dbml');
  });

  it('passes the selected tables as a comma-separated only list', () => {
    const url = buildExportUrl(template, 'json', { only: ['orders', 'order_lines'] });
    expect(params(url).get('only')).toBe('orders,order_lines');
  });

  it('omits only when the selection is empty', () => {
    expect(buildExportUrl(template, 'csv', { only: [] })).toBe('/truss/export/csv');
  });

  it('encodes focus with its depth and the compact flag', () => {
    const p = params(buildExportUrl(template, 'llm', { focus: 'orders', depth: 2, compact: true }));
    expect(p.get('focus')).toBe('orders');
    expect(p.get('depth')).toBe('2');
    expect(p.get('compact')).toBe('1');
  });

  it('passes a non-default connection', () => {
    expect(params(buildExportUrl(template, 'dbml', { connection: 'mysql' })).get('connection')).toBe('mysql');
  });

  it('leaves compact and depth out when not set', () => {
    const p = params(buildExportUrl(template, 'dbml', { focus: 'orders' }));
    expect(p.has('compact')).toBe(false);
    expect(p.has('depth')).toBe(false);
  });
});
