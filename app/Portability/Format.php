<?php

namespace App\Portability;

/**
 * PLAT-9: the version of the export bundle format, kept separate from the application version.
 *
 *   MAJOR  incompatible change (a reader of the old major cannot read the new bundle, or vice versa) = breaking API change
 *   MINOR  backward-compatible addition (new optional manifest keys, new tables); older readers of the same major still work
 *
 * An importer accepts the same major and any minor up to its own; it refuses anything else with a clear message.
 */
final class Format
{
    public const NAME = 'lawfirm-export';

    public const VERSION = '1.0.0';

    /** @return string|null a reason this bundle cannot be read by this build, null when it can */
    public static function incompatibility(?string $name, ?string $version): ?string
    {
        if ($name !== self::NAME) {
            return 'Not a lawfirm export bundle (format "'.($name ?? 'missing').'").';
        }
        if (! is_string($version) || ! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $b)) {
            return 'The bundle has no valid format version.';
        }
        preg_match('/^(\d+)\.(\d+)\./', self::VERSION, $own);
        if ((int) $b[1] !== (int) $own[1]) {
            return "Bundle format {$version} has a different major version than this release reads (".self::VERSION.'). Use a release that reads it.';
        }
        if ((int) $b[2] > (int) $own[2]) {
            return "Bundle format {$version} is newer than this release reads (".self::VERSION.'). Upgrade this installation first.';
        }

        return null;
    }
}
