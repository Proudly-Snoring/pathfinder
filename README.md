# Pathfinder

Mapping tool for [*EvE Online*](https://www.eveonline.com).
- Forked from [goryn-clade/pathfinder](https://github.com/goryn-clade/pathfinder) and [exodus4d/pathfinder](https://github.com/exodus4d/pathfinder). Big thanks to them.
- Deployment with podman/docker is adapted from [goryn-clade/pathfinder-containers](https://github.com/goryn-clade/pathfinder-containers).
- [Screenshots](http://imgur.com/a/k2aVa) and [videos](https://www.youtube.com/@Pathfinder-wSpace/videos) from [exodus4d](https://github.com/exodus4d) (may be slightly outdated).
- [MIT](http://opensource.org/licenses/MIT) licence.

## Project structure
<pre>
 Main application files:
 ─╮
  ├─ app/                → PHP root
  │  ├─ Controller/      → controller classes for app/ajax endpoints (see routes.ini)
  │  ├─ Cron/            → controller classes cronjob endpoints (see cron.ini)
  │  ├─ Data/            → classes for data handling
  │  ├─ Db/              → classes for DB handling
  │  ├─ Exception/       → custom exceptions
  │  ├─ Lib/             → libs
  │  ├─ Model/           → ORM
  │  ├─ config.ini       → config - Fat-Free Framework core config
  │  ├─ cron.ini         → config - cronjobs
  │  ├─ environment.ini  → config - system environment
  │  ├─ pathfinder.ini   → config - pathfinder
  │  ├─ plugin.ini       → config - custom plugins
  │  ├─ requirements.ini → config - system requirements
  │  └─ routes.ini       → config - routes
  ├─ data/               → curated *.csv static data used by /setup page
  ├─ favicon/            → favicons
  ├─ js/                 → JS source files (not used for production)
  │  ├─ app/             → "PATHFINDER" core files
  │  ├─ lib/             → 3rd party libs
  │  └─ app.js           → require.js config
  ├─ public/             → static resources
  │  ├─ css/             → CSS dist/build folder (minified)
  │  ├─ fonts/           → icon-/fonts
  │  ├─ img/             → images
  │  ├─ js/              → JS dist/build folder and source maps (minified, uglified)
  │  └─ templates/       → templates
  ├─ sass/               → SCSS sources (not used for production)
  └─ index.php

 CI/CD config files (not used for production):
 ─╮
  ├─ .jshintrc           → "JSHint" config 
  ├─ composer.json       → "Composer" package definition
  ├─ gulpfile.js         → "Gulp" task config
  ├─ package.json        → "Node.js" dependency config
  └─ README.md
</pre>

## Install

See [deployment.md](docs/deployment.md) for a local installation with podman (docker works too if you prefer).

## Contribute

See [contributing.md](docs/contributing.md).
