import { defineConfig } from 'astro/config'
import starlight from '@astrojs/starlight'

export default defineConfig({
  site: 'https://albertoarena.github.io',
  base: '/laravel-truss',
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
        { tag: 'meta', attrs: { property: 'og:image', content: 'https://albertoarena.github.io/laravel-truss/cover-light.png' } },
        { tag: 'meta', attrs: { property: 'og:image:width', content: '1200' } },
        { tag: 'meta', attrs: { property: 'og:image:height', content: '630' } },
        { tag: 'meta', attrs: { name: 'twitter:card', content: 'summary_large_image' } },
        { tag: 'meta', attrs: { name: 'twitter:image', content: 'https://albertoarena.github.io/laravel-truss/cover-light.png' } },
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
