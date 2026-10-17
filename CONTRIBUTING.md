# Contributing

## Commit messages and pull request titles

Every commit, and every pull request title (it becomes the commit when a PR is squash-merged), follows
[Conventional Commits](https://www.conventionalcommits.org/). The `commits` check enforces it, and
`php artisan release:draft` turns them into the changelog and picks the next version.

```
type(scope): summary in the imperative, lower case
```

| type | use for | version bump |
|---|---|---|
| `feat` | something a firm, its staff or an operator can now do | minor |
| `fix` | a bug fixed | patch |
| `perf` | faster, same behaviour | patch |
| `refactor`, `docs`, `test`, `chore`, `ci`, `build`, `style` | everything else | none (not in the changelog) |
| `revert` | undoing an earlier commit | patch |

A breaking change adds `!` after the type or scope (`feat(api)!: …`) or a `BREAKING CHANGE: …` line in the body; it
bumps the major version and needs an upgrade note in `docs/upgrade/`. The scope is the area (`billing`, `tenancy`,
`api`, …) or the backlog id's area. Check a range locally:

```
git log --no-merges --format=%s origin/main..HEAD | php artisan release:draft --strict
```

## Releases

`scripts/release.sh <version>` bumps `VERSION`, the Helm chart and the changelog and creates the `v<version>` tag;
pushing the tag runs `release.yml` (verify, then publish the image, the Helm chart and the bare-metal bundle). Changelog
rules are at the top of CHANGELOG.md.
