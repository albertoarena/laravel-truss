import { describe, it, expect } from 'vitest';
import { tableCountLabel } from '../../resources/js/table-count.js';

describe('tableCountLabel, what the footer says about scope', () => {
  it('states a plain count when the diagram draws everything it knows about', () => {
    expect(tableCountLabel(32, 32)).toBe('32 tables');
  });

  it('keeps the singular for one table', () => {
    expect(tableCountLabel(1, 1)).toBe('1 table');
  });

  it('reads as scope when part of the schema is not drawn', () => {
    // "32 of 40" says Truss saw 40 and is drawing 32, which is the question the
    // reader actually has. "32 tables, 8 hidden" answers one nobody asked and
    // reads as something being withheld.
    expect(tableCountLabel(32, 40)).toBe('32 of 40 tables');
  });

  it('pluralises on the total, so a single known table still reads correctly', () => {
    expect(tableCountLabel(0, 1)).toBe('0 of 1 table');
  });

  it('never claims a total smaller than what is drawn', () => {
    expect(tableCountLabel(5, 3)).toBe('5 tables');
  });

  it('falls back to the plain count when there is no usable total', () => {
    expect(tableCountLabel(7)).toBe('7 tables');
    expect(tableCountLabel(7, null)).toBe('7 tables');
    expect(tableCountLabel(7, Number.NaN)).toBe('7 tables');
  });
});
