import { describe, it, expect } from 'vitest';
import { readPayload, inlinePayload } from '../../resources/js/schema-source.js';
import { schema } from './fixtures.js';

/**
 * The seam that lets the dashboard run on a payload it was handed, rather than
 * one it fetched.
 *
 * Everything downstream (selection, the Mermaid definition, the viewport) is
 * already pure and importable. The one thing that was not was the step in
 * between: reading the API envelope into the state the dashboard renders from,
 * which lived inline in truss.js and therefore could not be reached without an
 * HTTP request. Code embedding Truss's diagram in a page it renders itself (an
 * admin panel, a Livewire component) has the payload in hand from
 * `Truss::payload()` and no reason to ask its own application for it over the
 * network.
 *
 * Tolerance is the point of readPayload. The envelope grows optional keys over
 * releases (`diff`, `doctor`, and the two unavailability flags all arrived after
 * v1.0), so a payload from an older Truss, or a hand-built one in a test, must
 * degrade to a working diagram rather than to a crash.
 */

/** A minimal stand-in for the container element, so this stays a node test. */
const container = (html) => ({
  querySelector: (selector) => (html !== null && selector === 'script[type="application/json"][data-truss-payload]'
    ? { textContent: html }
    : null),
});

describe('readPayload — the API envelope into dashboard state', () => {
  it('reads every field of a full envelope', () => {
    const state = readPayload({
      tables: schema,
      fallback: true,
      generated_at: '2026-09-10T12:00:00+00:00',
      diff: { tables: { added: ['posts'] } },
      doctor: { summary: { total: 2 } },
      cache_unavailable: true,
      diff_unavailable: true,
    });

    expect(state.tables).toEqual(schema);
    expect(state.fallback).toBe(true);
    expect(state.generatedAt).toBe('2026-09-10T12:00:00+00:00');
    expect(state.diff).toEqual({ tables: { added: ['posts'] } });
    expect(state.doctor).toEqual({ summary: { total: 2 } });
    expect(state.cacheUnavailable).toBe(true);
    expect(state.diffUnavailable).toBe(true);
  });

  it('falls back to a working, empty state for a bare envelope', () => {
    // Everything but `tables` is optional in the wire format, and a Truss old
    // enough to predate the diff and the doctor still serves a valid payload.
    expect(readPayload({ tables: [] })).toEqual({
      tables: [],
      fallback: false,
      generatedAt: null,
      diff: null,
      doctor: null,
      cacheUnavailable: false,
      diffUnavailable: false,
    });
  });

  it('treats the unavailability flags as strictly true, never as truthy', () => {
    // They are notices that something is broken, so anything short of an
    // explicit true must not raise a banner. A stray "0" or "false" string from
    // a hand-built payload would otherwise report a broken cache store.
    const state = readPayload({ tables: [], cache_unavailable: 'false', diff_unavailable: 1 });

    expect(state.cacheUnavailable).toBe(false);
    expect(state.diffUnavailable).toBe(false);
  });

  it('survives a missing or malformed envelope rather than throwing', () => {
    // A blank diagram is a recoverable state the dashboard already renders (it
    // shows the empty-state notice). A thrown TypeError from inside the loader
    // is not: it leaves the page on its loading banner forever.
    for (const bad of [null, undefined, 'nope', 42, []]) {
      expect(readPayload(bad).tables).toEqual([]);
    }
  });

  it('ignores a tables value that is not a list', () => {
    expect(readPayload({ tables: { posts: {} } }).tables).toEqual([]);
  });
});

describe('inlinePayload — a payload embedded in the page', () => {
  it('reads and parses the embedded JSON', () => {
    const payload = inlinePayload(container(JSON.stringify({ tables: schema, connection: 'primary' })));

    expect(payload.connection).toBe('primary');
    expect(payload.tables).toHaveLength(schema.length);
  });

  it('returns null when the page embeds nothing', () => {
    // The normal case: Truss's own dashboard fetches, and must keep fetching.
    expect(inlinePayload(container(null))).toBeNull();
  });

  it('throws when a payload is embedded but cannot be parsed', () => {
    // Deliberately not silent. Falling back to a fetch here would send a host
    // that embedded a payload to an endpoint it may not even have, and report
    // the resulting 404 instead of the authoring mistake that caused it.
    expect(() => inlinePayload(container('{ not json'))).toThrow(/could not be parsed/i);
  });

  it('throws when the embedded JSON is not an object', () => {
    expect(() => inlinePayload(container('"just a string"'))).toThrow(/could not be parsed/i);
  });
});
