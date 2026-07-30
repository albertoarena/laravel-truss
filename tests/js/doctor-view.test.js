import { describe, it, expect } from 'vitest';
import {
  hasDoctor,
  doctorSummary,
  worstSeverity,
  tableBadges,
  tableSeverity,
  findingGroups,
  columnMarkers,
} from '../../resources/js/doctor-view.js';

// A doctor payload shaped exactly like the API `doctor` field (see DoctorReport),
// already sorted by severity, then table, then code.
const doctor = {
  summary: { total: 4, error: 2, warning: 2, info: 0 },
  findings: [
    { code: 'TRUSS-INT-003', severity: 'error', table: 'activity_logs', column: 'company_uuid', message: 'FK type mismatch.', confidence: 'high', category: 'integrity' },
    { code: 'TRUSS-INT-001', severity: 'error', table: 'ledger', column: null, message: 'No primary key.', confidence: 'high', category: 'integrity' },
    { code: 'TRUSS-INT-007', severity: 'warning', table: 'activity_logs', column: null, message: 'Pivot without unique key.', confidence: 'high', category: 'integrity' },
    { code: 'TRUSS-TYP-002', severity: 'warning', table: 'flags', column: 'is_active', message: 'Boolean as string.', confidence: 'heuristic', category: 'type' },
  ],
};

describe('hasDoctor', () => {
  it('is true only for a payload with findings', () => {
    expect(hasDoctor(doctor)).toBe(true);
    expect(hasDoctor(null)).toBe(false);
    expect(hasDoctor({ summary: { total: 0 }, findings: [] })).toBe(false);
  });
});

describe('doctorSummary', () => {
  it('counts findings by severity', () => {
    expect(doctorSummary(doctor)).toEqual({ error: 2, warning: 2, info: 0, total: 4 });
  });

  it('is all zeros for a null payload', () => {
    expect(doctorSummary(null)).toEqual({ error: 0, warning: 0, info: 0, total: 0 });
  });
});

describe('worstSeverity', () => {
  it('returns the highest severity present', () => {
    expect(worstSeverity(doctor)).toBe('error');
    expect(worstSeverity({ findings: [{ severity: 'warning', table: 'x' }] })).toBe('warning');
    expect(worstSeverity(null)).toBe(null);
  });
});

describe('tableBadges / tableSeverity', () => {
  it('maps each table to its worst severity and finding count', () => {
    const map = tableBadges(doctor);
    expect(map.get('activity_logs')).toEqual({ severity: 'error', count: 2 });
    expect(map.get('ledger')).toEqual({ severity: 'error', count: 1 });
    expect(map.get('flags')).toEqual({ severity: 'warning', count: 1 });
    expect(map.has('unrelated')).toBe(false);
  });

  it('returns none for a table with no findings, and for a null payload', () => {
    expect(tableSeverity(doctor, 'unrelated')).toBe('none');
    expect(tableSeverity(null, 'ledger')).toBe('none');
    expect(tableSeverity(doctor, 'activity_logs')).toBe('error');
  });
});

describe('findingGroups', () => {
  it('groups findings by table, worst severity first per table', () => {
    const groups = findingGroups(doctor);
    const tables = groups.map((g) => g.table);

    expect(tables).toEqual(['activity_logs', 'ledger', 'flags']);

    const activity = groups[0];
    expect(activity.severity).toBe('error');
    expect(activity.findings).toHaveLength(2);
    expect(activity.findings.map((f) => f.code)).toEqual(['TRUSS-INT-003', 'TRUSS-INT-007']);
  });

  it('carries per-finding confidence so heuristics can be marked', () => {
    const flags = findingGroups(doctor).find((g) => g.table === 'flags');
    expect(flags.findings[0].confidence).toBe('heuristic');
  });

  it('is empty for a null payload', () => {
    expect(findingGroups(null)).toEqual([]);
    expect(tableBadges(null).size).toBe(0);
  });
});

describe('columnMarkers', () => {
  it('maps a table per-column findings, worst severity per column', () => {
    const map = columnMarkers(doctor, 'activity_logs', ['id', 'company_uuid']);
    expect(map.get('company_uuid').severity).toBe('error');
    expect(map.get('company_uuid').findings.map((f) => f.code)).toEqual(['TRUSS-INT-003']);
    expect(map.has('id')).toBe(false);
  });

  it('leaves out table-level findings and columns not on the table', () => {
    // activity_logs also has INT-007 with column null: table-level, not a marker.
    const map = columnMarkers(doctor, 'activity_logs', ['company_uuid']);
    expect([...map.keys()]).toEqual(['company_uuid']);

    // A column the table does not have yields nothing.
    expect(columnMarkers(doctor, 'flags', ['nope']).size).toBe(0);
  });

  it('is empty for a null payload', () => {
    expect(columnMarkers(null, 'x', ['a']).size).toBe(0);
  });
});
