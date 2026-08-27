// Minimal static file server for the Playwright harness. Serves the repo root
// so the harness page, the shipped ES modules under resources/js, and Mermaid
// from node_modules all load same-origin (native module imports need that).
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';

const root = process.cwd();
const port = Number(process.env.PORT || 5178);

const TYPES = {
  '.html': 'text/html',
  '.js': 'text/javascript',
  '.mjs': 'text/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.map': 'application/json',
  '.woff2': 'font/woff2',
};

createServer(async (req, res) => {
  try {
    const pathname = new URL(req.url, 'http://localhost').pathname;
    const safe = normalize(decodeURIComponent(pathname)).replace(/^(\.\.[/\\])+/, '');
    // truss.css asks for its faces with bare relative URLs, which resolve
    // against the stylesheet's own directory. In production that is the asset
    // route, where the CSS and the woff2 sit together; here they do not, so
    // point every font request at resources/fonts and keep the CSS unmodified.
    const path = safe.endsWith('.woff2')
      ? join('resources/fonts', safe.split('/').pop())
      : safe;
    const body = await readFile(join(root, path));
    res.writeHead(200, { 'content-type': TYPES[extname(safe)] || 'application/octet-stream' });
    res.end(body);
  } catch {
    res.writeHead(404);
    res.end('not found');
  }
}).listen(port, () => console.log(`truss e2e static server on ${port}`));
