# Laravel Truss

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="art/cover-dark.png">
  <img src="art/cover-light.png" alt="Laravel Truss — see your database structure as a live, zoomable ER diagram">
</picture>

[![Documentation](https://img.shields.io/badge/docs-website-2f6feb?style=flat-square)](https://trussphp.com)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/albertoarena/laravel-truss.svg?style=flat-square)](https://packagist.org/packages/albertoarena/laravel-truss)
[![Total Downloads](https://img.shields.io/packagist/dt/albertoarena/laravel-truss.svg?style=flat-square)](https://packagist.org/packages/albertoarena/laravel-truss)
[![Tests](https://img.shields.io/github/actions/workflow/status/albertoarena/laravel-truss/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/albertoarena/laravel-truss/actions/workflows/run-tests.yml)
[![License](https://img.shields.io/badge/license-MIT-red.svg?style=flat-square)](LICENSE)
![Repo views](https://raw.githubusercontent.com/albertoarena/laravel-truss/traffic-data/badge.svg)

Laravel Truss is a live database structure viewer. It scans your live schema and renders it as a scrollable, zoomable ER diagram right inside your app, so you can see how the tables actually connect without opening a DB client. It reads **structure only** (tables, columns, keys, indexes); row data is never queried or exposed.

**[Try the live demo](https://trussphp.com/demo/)** to pan, zoom, focus, and export a sample schema in your browser, no install needed.

## Features

- Live ER diagram of your database, rendered with Mermaid.
- Focus mode: a table and its foreign-key neighbours, centred and highlighted.
- Filter by table name, and toggle native types against Laravel-style labels.
- Map-style pan and zoom, with auto-fit and a Fit button.
- Export the diagram as PNG or SVG, or its structure as JSON, CSV, a Markdown data dictionary, or DBML, all generated in the browser and structure-only.
- Light and dark "blueprint" theme.
- Self-contained: Mermaid and fonts are vendored and served from the package, so it works offline and under a strict Content-Security-Policy (no CDN).
- Cached snapshot, rebuilt automatically after migrations.

## Documentation

Full documentation is at **[trussphp.com](https://trussphp.com)**.

- [Installation](https://trussphp.com/getting-started/installation/)
- [Quick start](https://trussphp.com/getting-started/quick-start/)
- [Authorization](https://trussphp.com/guides/authorization/)
- [Configuration reference](https://trussphp.com/reference/configuration/)

## Installation

For local use, install Truss as a dev dependency:

```bash
composer require albertoarena/laravel-truss --dev
```

To run Truss gated on staging or production, install it as a **regular dependency** instead. Dev dependencies are excluded from `composer install --no-dev` builds, so a `--dev` install never reaches a production deploy and `/truss` returns 404 there:

```bash
composer require albertoarena/laravel-truss
```

Requires **PHP 8.3+** and **Laravel 12+**. The service provider is auto-discovered, so there is nothing to publish to get started.

## Quick start

By default Truss is enabled in the `local` environment only. Start your app and visit:

```
/truss
```

To use Truss in a non-local environment you must both enable it and authorize the viewers. See [Authorization](https://trussphp.com/guides/authorization/).

## Security

Truss exposes structure only and never queries row data. Access is protected by the fixed `viewTruss` gate. If you discover a security issue, please email arena.alberto@gmail.com rather than opening a public issue.

## Contributing

Contributions are welcome. Feel free to fork, improve, and open a pull request.

## Support

Laravel Truss is free and open source. If it has saved you time, you can support its ongoing maintenance and new features with a coffee:

**[ko-fi.com/albertoarena](https://ko-fi.com/albertoarena)**

Starring the repo and sharing it help just as much.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
