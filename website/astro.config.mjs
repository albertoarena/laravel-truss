import { defineConfig } from 'astro/config'
import starlight from '@astrojs/starlight'

// Build target is env-driven so the same source builds for two hosts:
//   - GitHub Pages (default): albertoarena.github.io/laravel-truss
//   - Netsons root domain:    trussphp.com (SITE_URL set, SITE_BASE empty)
// SITE_BASE uses ?? so an explicit empty string ('') means "root, no subpath".
const SITE = process.env.SITE_URL || 'https://albertoarena.github.io'
const BASE = process.env.SITE_BASE ?? '/laravel-truss'
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
