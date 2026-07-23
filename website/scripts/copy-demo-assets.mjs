// Copies the package's *shipped* frontend (the ES modules, truss.css, the
// vendored Mermaid, and the IBM Plex Mono fonts) into the docs site's static
// demo folder, so the live demo at /demo/ runs the exact same code that ships,
// with no drift. Runs automatically via the `prebuild`/`predev` npm hooks.
//
// Everything lands flat in public/demo/assets/ because truss.css references its
// fonts as siblings (url("ibm-plex-mono-400.woff2")) and truss.js imports its
// modules as siblings (./selection.js). The folder is generated, not committed.
import { cp, rm, mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..');
const resources = join(repo, 'resources');
const dest = join(here, '..', 'public', 'demo', 'assets');

await rm(dest, { recursive: true, force: true });
await mkdir(dest, { recursive: true });

// ES modules + vendor/mermaid.min.js (contents of resources/js land in assets/).
await cp(join(resources, 'js'), dest, { recursive: true });

// Stylesheet next to its fonts, so the relative @font-face urls resolve.
await cp(join(resources, 'css', 'truss.css'), join(dest, 'truss.css'));
for (const weight of ['400', '500', '600']) {
  const font = `ibm-plex-mono-${weight}.woff2`;
  await cp(join(resources, 'fonts', font), join(dest, font));
}

console.log(`Copied Truss frontend into ${dest}`);
