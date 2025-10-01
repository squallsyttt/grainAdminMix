# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

Project type: PHP (ThinkPHP) + Node (Grunt) FastAdmin application

Commands

- Install PHP dependencies
  ```bash path=null start=null
  composer install
  ```

- Install Node dependencies (uses package-lock.json)
  ```bash path=null start=null
  npm ci
  ```

- Build frontend assets (default task runs deploy + JS/CSS builds)
  ```bash path=null start=null
  npm run build
  ```

- Build individual bundles (useful during frontend development)
  ```bash path=null start=null
  npx grunt frontend:js    # builds public/assets/js/require-frontend.min.js
  npx grunt backend:js     # builds public/assets/js/require-backend.min.js
  npx grunt frontend:css   # builds public/assets/css/frontend.min.css
  npx grunt backend:css    # builds public/assets/css/backend.min.css
  npx grunt deploy         # copies vendor assets from node_modules to public/assets/libs
  ```

- Start a local PHP dev server (serve the public/ directory)
  ```bash path=null start=null
  php -S 127.0.0.1:8000 -t public public/index.php
  # Admin entry is available at http://127.0.0.1:8000/admin.php
  # Installer (first run) is available at http://127.0.0.1:8000/install.php
  ```

- Non-interactive CLI installation (creates DB and admin user). Replace placeholders as needed.
  ```bash path=null start=null
  php think install \
    --hostname=127.0.0.1 \
    --hostport=3306 \
    --database={{DB_NAME}} \
    --prefix=fa_ \
    --username={{DB_USER}} \
    --password={{DB_PASSWORD}}
  # Add --force=true to reinstall if install.lock exists
  ```

Notes on linting and tests

- Lint: No ESLint/PHPCS configuration was found in this repository.
- Tests: No automated test suite (PHPUnit/Jest/etc.) was found.

Architecture overview

- Entry points (public/)
  - public/index.php: Frontend entry.
  - public/admin.php: Admin backend entry.
  - public/install.php: Web installer UI.
  - public/router.php: Router for CLI server usage.

- ThinkPHP console
  - think: Console bootstrap. Useful commands include custom ones under application/admin/command (e.g., install, crud, addon, api, menu, min). List available commands with:
    ```bash path=null start=null
    php think list
    ```

- Application modules (application/)
  - admin: Admin backend implementation
    - controller: Auth, general, user, dashboard, category, etc.
    - model: Admin, AdminLog, AuthGroup, AuthRule, User, etc.
    - library: Auth, traits/Backend for controller mixins
    - validate, lang: Validation rules and i18n for admin
    - command: Project-specific console commands (install, crud, menu, addon, api, min)
  - api: Public API layer
    - controller: Token, User, Sms, Ems, Validate, etc.
    - library: ExceptionHandle
    - lang: API i18n
  - index: Frontend site controllers (Index, User, Ajax) with i18n
  - common: Shared controllers (Api, Backend, Frontend), libraries (Auth, Upload, Token drivers), exceptions, and models (User, Attachment, Config, Category, Version, etc.)

- Configuration
  - application/config.php, application/database.php: Core and DB config
  - application/extra/*.php: Feature-specific configs (addons, queue, site, upload)

- Frontend assets (public/assets)
  - JS is organized with RequireJS. Build targets:
    - public/assets/js/require-frontend.js → require-frontend.min.js
    - public/assets/js/require-backend.js → require-backend.min.js
  - CSS bundles: frontend.css/backend.css → corresponding .min.css via Grunt
  - Vendor libraries are copied into public/assets/libs via the Grunt "deploy" task based on package.json.dists mapping

Key references

- Upstream FastAdmin documentation: https://doc.fastadmin.net
- This repository’s README includes feature overview and links to demo and support.
