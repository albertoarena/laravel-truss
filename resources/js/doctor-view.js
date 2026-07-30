// Pure helpers that turn the API `doctor` field (see DoctorReport) into render
// models for the dashboard "Health" view: a per-table grouped list for the panel
// and per-node severity badges. No DOM, no fetch. Structure only, the findings
// name tables and columns, never row data.

const EMPTY_SUMMARY = { error: 0, warning: 0, info: 0, total: 0 };
const SEVERITY_RANK = { error: 3, warning: 2, info: 1 };

// True only when a doctor payload is present and actually reports findings.
export function hasDoctor(doctor) {
  return !!(doctor && (doctor.summary?.total ?? 0) > 0);
}

// Counts of error / warning / info findings (plus their total).
export function doctorSummary(doctor) {
  if (!doctor || !doctor.summary) return { ...EMPTY_SUMMARY };
  const { error = 0, warning = 0, info = 0 } = doctor.summary;
  return { error, warning, info, total: error + warning + info };
}

// The highest severity present across all findings, or null when there are none.
export function worstSeverity(doctor) {
  let worst = null;
  for (const f of doctor?.findings ?? []) {
    if (worst === null || SEVERITY_RANK[f.severity] > SEVERITY_RANK[worst]) worst = f.severity;
  }
  return worst;
}

// Map of tableName -> { severity: worstForThatTable, count } for tinting diagram
// nodes. Tables with no findings are absent from the map.
export function tableBadges(doctor) {
  const map = new Map();
  for (const f of doctor?.findings ?? []) {
    const current = map.get(f.table);
    if (!current) {
      map.set(f.table, { severity: f.severity, count: 1 });
      continue;
    }
    current.count += 1;
    if (SEVERITY_RANK[f.severity] > SEVERITY_RANK[current.severity]) current.severity = f.severity;
  }
  return map;
}

// The worst severity for a single table, or 'none' when it has no findings.
export function tableSeverity(doctor, name) {
  return tableBadges(doctor).get(name)?.severity ?? 'none';
}

// Ordered per-table group model for the panel: [{ table, severity, findings }].
// Each table appears once, in the order its first finding does (the payload is
// already sorted by severity, then table, then code), and `severity` is the
// worst across that table's findings.
export function findingGroups(doctor) {
  const order = [];
  const groups = new Map();
  for (const f of doctor?.findings ?? []) {
    if (!groups.has(f.table)) {
      groups.set(f.table, { table: f.table, severity: f.severity, findings: [] });
      order.push(f.table);
    }
    const group = groups.get(f.table);
    group.findings.push(f);
    if (SEVERITY_RANK[f.severity] > SEVERITY_RANK[group.severity]) group.severity = f.severity;
  }
  return order.map((name) => groups.get(name));
}
