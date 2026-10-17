# Upgrading to X.Y.Z

Copy this file to `docs/upgrade/<version>.md` (or `unreleased.md` while the release is in progress) and fill every section.
`php artisan release:check` fails on a missing section or a leftover to-do marker. A guide is required for any release with breaking
changes and for every minor or major step. Write for the person running the upgrade, not for developers.

## Before you upgrade

What must be true first: minimum version you are coming from, backups, scheduler/worker requirements, anything to announce to users.

## What changes

The behaviour, configuration and API changes, with the reason for each. Say who is affected.

## Required actions

Numbered, copy-pasteable steps in the order to do them. State which are optional.

## After you upgrade

How to check it worked (commands, endpoints, screens).

## Rollback

What can be rolled back and how (schema changes are expand-only: the previous release keeps working on the new schema within one minor),
and anything that cannot be undone.
