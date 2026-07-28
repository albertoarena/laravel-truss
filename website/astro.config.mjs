import { defineConfig } from 'astro/config'
import starlight from '@astrojs/starlight'

// Build target is env-driven. The default is the production root domain
// (trussphp.com); SITE_URL / SITE_BASE let CI or a preview build target
// elsewhere. SITE_BASE uses ?? so an explicit '' still means "root, no subpath".
const SITE = process.env.SITE_URL || 'https://trussphp.com'
const BASE = process.env.SITE_BASE ?? ''
const COVER = `${SITE}${BASE}/cover-light.png`

export default defineConfig({
  site: SITE,
  base: BASE || undefined,
  integrations: [
    starlight({
      title: 'Laravel Truss',
      description: 'A live database structure viewer for Laravel',
      logo: {
        light: './src/assets/truss-mark-light.svg',
        dark: './src/assets/truss-mark-dark.svg',
      },
      favicon: '/favicon.svg',
      head: [
        { tag: 'meta', attrs: { property: 'og:image', content: COVER } },
        { tag: 'meta', attrs: { property: 'og:image:width', content: '1200' } },
        { tag: 'meta', attrs: { property: 'og:image:height', content: '630' } },
        { tag: 'meta', attrs: { name: 'twitter:card', content: 'summary_large_image' } },
        { tag: 'meta', attrs: { name: 'twitter:image', content: COVER } },
        // Cloudflare Web Analytics beacon (cookieless, no consent banner). The
        // token is public, not a secret. Manual install because the domain is
        // grey-cloud (DNS-only), so Cloudflare cannot auto-inject it.
        {
          tag: 'script',
          attrs: {
            defer: true,
            src: 'https://static.cloudflareinsights.com/beacon.min.js',
            'data-cf-beacon': '{"token": "7f6eaa0832eb43cc838db8d337590f11"}',
          },
        },
      ],
      social: {
        github: 'https://github.com/albertoarena/laravel-truss',
      },
      editLink: {
        baseUrl: 'https://github.com/albertoarena/laravel-truss/edit/main/website/',
      },
      customCss: [
        './src/styles/custom.css',
      ],
      sidebar: [
        {
          label: 'Introduction',
          items: [
            { label: 'Overview', link: '/' },
          ],
        },
        {
          label: 'Getting Started',
          items: [
            { label: 'Installation', link: '/getting-started/installation/' },
            { label: 'Quick start', link: '/getting-started/quick-start/' },
            { label: 'Live demo', link: '/demo/', attrs: { target: '_blank' }, badge: 'Live' },
          ],
        },
        {
          label: 'Guides',
          items: [
            { label: 'Authorization', link: '/guides/authorization/' },
            { label: 'Focus & filter', link: '/guides/focus-and-filter/' },
          ],
        },
        {
          label: 'Reference',
          items: [
            { label: 'Configuration', link: '/reference/configuration/' },
            { label: 'Commands', link: '/reference/commands/' },
          ],
        },
        {
          label: 'Help',
          items: [
            { label: 'Troubleshooting', link: '/help/troubleshooting/' },
          ],
        },
        { label: 'Credits', link: '/credits/' },
      ],
    }),
  ],
})
