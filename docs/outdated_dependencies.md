# Outdated dependencies

Audit date: 2026-06-24 (branch `f/dependencies-update`).

Method:
- PHP: `composer outdated` (run in `composer:latest` podman container; PHP/composer not installed on host).
- Node: `npm outdated`.

## Summary

| Ecosystem | Direct deps        | Transitive deps          |
| --------- | ------------------ | ------------------------ |
| Composer  | all up to date     | 3 outdated (see below)   |
| npm       | all up to date     | all up to date           |

All **direct** dependencies (`composer.json` `require`, `package.json` `devDependencies`)
are current. The only outdated packages are 3 transitive Composer deps held back by the
`cache/*` adapter family.

## Outdated transitive Composer dependencies

| Package            | Installed | Latest  | Required by (constraint)                          |
| ------------------ | --------- | ------- | ------------------------------------------------- |
| league/flysystem   | 1.1.10    | 3.35.0  | cache/filesystem-adapter (`^1.0`)                 |
| psr/cache          | 2.0.0     | 3.0.0   | cache/* adapters (`^1.0 \|\| ^2.0`)               |
| psr/simple-cache   | 1.0.1     | 3.0.0   | cache/* adapters (`^1.0`)                         |

### Why not updatable

All three are pinned by the PHP-Cache `cache/*` adapters (`cache/filesystem-adapter`,
`cache/redis-adapter`, `cache/array-adapter`, `cache/namespaced-cache`, `cache/void-adapter`,
`cache/adapter-common`, `cache/tag-interop`, `cache/hierarchical-cache`). Those adapters are
themselves already at their **latest** release (1.2.x / 1.3.x) and still constrain
flysystem to `^1.0` and psr/cache/simple-cache to v1–v2.

Bumping these requires replacing the `cache/*` library family (e.g. switching to
symfony/cache or a maintained PSR-6 stack) — not a simple version bump.
