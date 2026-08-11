import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { join, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

// The shipped dashboard is a user-facing prose surface like the README or the
// docs site, so the house style applies to what it says on screen: no em or en
// dashes. Code comments are left alone (they are not shown to anyone), which is
// why the scan strips them first rather than reading whole files.

const root = fileURLToPath(new URL('../..', import.meta.url));

const SURFACES = ['resources/js', 'resources/css', 'resources/views'];
const EXTS = new Set(['.js', '.css', '.php']);

function walk(dir) {
  const out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    // The vendored Mermaid bundle is third-party; its copy is not ours to edit.
    if (entry.name === 'vendor') continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...walk(full));
    } else if (EXTS.has(extname(entry.name))) {
      out.push(full);
    }
  }
  return out;
}

/**
 * Drop comments so only what can reach the screen is scanned: /* ... *​/ blocks
 * (JS and CSS) and whole-line // comments (JS). A trailing // comment on a line
 * of code survives the strip, which is a deliberate simplification: it keeps the
 * helper honest and readable, and the cost is a false positive that a reviewer
 * can dismiss, never a miss on real copy.
 */
function stripComments(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n')
    .filter((line) => !/^\s*\/\//.test(line))
    .join('\n');
}

const files = SURFACES.flatMap((dir) => walk(join(root, dir)));

describe('shipped dashboard copy', () => {
  it('finds the frontend source to scan', () => {
    expect(files.length).toBeGreaterThan(0);
  });

  it('uses no em or en dashes in anything the user can read', () => {
    const offenders = [];
    for (const file of files) {
      stripComments(readFileSync(file, 'utf8')).split('\n').forEach((line, i) => {
        if (line.includes('—') || line.includes('–')) {
          offenders.push(`${file.replace(root, '')}:${i + 1}  ${line.trim()}`);
        }
      });
    }
    expect(offenders, `em/en dash in shipped copy:\n${offenders.join('\n')}`).toEqual([]);
  });
});
