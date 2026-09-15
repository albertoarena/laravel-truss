/**
 * What the footer says about how much of the schema is on screen.
 *
 * Two numbers, never three: what the diagram draws, and what it could draw. A
 * filter or a focus narrows the first; `truss.excluded_tables` lowers the
 * second's starting point, and the payload reports how many it removed.
 *
 * Phrased as scope rather than as concealment. "32 of 40 tables" says Truss saw
 * forty and is drawing thirty-two, which answers the question a reader actually
 * has when the diagram looks short. Naming it as hiding ("32 tables, 8 hidden")
 * tells them something is being kept from them, which is the wrong register even
 * when it is true, and the names are never disclosed either way.
 */
export function tableCountLabel(drawn, known = null) {
  const total = Number.isFinite(known) ? known : drawn;

  // A total below the drawn count is nonsense rather than news, so say the
  // simple true thing instead of printing "5 of 3".
  if (total <= drawn) {
    return `${drawn} ${drawn === 1 ? 'table' : 'tables'}`;
  }

  return `${drawn} of ${total} ${total === 1 ? 'table' : 'tables'}`;
}
