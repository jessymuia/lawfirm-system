const { execSync } = require('child_process');
const path = require('path');

/**
 * §3.2: "Tests must run against a properly seeded application in CI."
 *
 * Seeding itself is run as an explicit CI step in
 * .github/workflows/ci.yml (so a developer running `npx playwright test`
 * against an already-seeded local/staging environment isn't forced to
 * re-seed and doesn't need PHP on their machine just to run the suite).
 * This file is kept as a reusable, standalone seed script for that CI
 * step and for local full-stack runs.
 *
 * Usage: `node global-setup.js` from a machine that has the Laravel app
 * checked out and `php`/`composer` available (i.e. run from the
 * repository root, or set APP_DIR below).
 */
const APP_DIR = path.resolve(__dirname, '..');

function run(command) {
  console.log(`$ ${command}`);
  execSync(command, { cwd: APP_DIR, stdio: 'inherit' });
}

function seed() {
  run('php artisan migrate --force');
  // DatabaseSeeder now runs RoleSeeder + E2ETestSeeder first (fixing the
  // seeding-order bug documented in those seeder files), then the rest
  // of the demo data.
  run('php artisan db:seed --force');
}

if (require.main === module) {
  seed();
}

module.exports = { seed };
