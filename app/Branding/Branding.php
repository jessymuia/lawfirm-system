<?php

namespace App\Branding;

use App\Support\FirmSettings;
use App\Tenancy\FirmStorage;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * UI-13: how the firm looks: its name as it wants it shown, a logo, a primary colour (the web app's colour scale is
 * derived from it), and the letterhead lines its invoices and other documents carry. Stored with the firm
 * (firm_settings; the logo on the firm's own disk), so it travels with an export and never mixes with another firm's.
 */
class Branding
{
    public const DEFAULT_COLOR = '#0e6b50';

    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const LOGO_MAX_KB = 1024;

    /** @return array{name: string, color: string, letterhead: array<string, ?string>, invoice_footer: ?string, logo: ?array{disk: string, path: string, type: string, version: int}} */
    public static function get(): array
    {
        $b = (array) FirmSettings::get('branding', []);

        return [
            'name' => (string) (($b['name'] ?? '') !== '' ? $b['name'] : config('app.name')),
            'color' => (string) ($b['color'] ?? self::DEFAULT_COLOR),
            'letterhead' => array_merge(array_fill_keys(['address', 'phone', 'email', 'website', 'tax_pin'], null), (array) ($b['letterhead'] ?? [])),
            'invoice_footer' => $b['invoice_footer'] ?? null,
            'logo' => $b['logo'] ?? null,
        ];
    }

    /** The firm's name for the page shell, served on any host: the app's name where no firm is resolved. */
    public static function shellName(): string
    {
        try {
            return self::get()['name'];
        } catch (\Throwable) {
            return (string) config('app.name');
        }
    }

    /** The firm's colour for the page shell (the preloader), the default where no firm is resolved or the value is not a hex colour. */
    public static function shellColor(): string
    {
        try {
            $c = self::get()['color'];
        } catch (\Throwable) {
            $c = self::DEFAULT_COLOR;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : self::DEFAULT_COLOR;
    }

    /** What anyone may see before signing in (the sign-in page, the installed app). */
    public static function public(): array
    {
        $b = self::get();

        return ['name' => $b['name'], 'color' => $b['color'], 'logo_url' => $b['logo'] ? '/api/v1/branding/logo?v='.$b['logo']['version'] : null];
    }

    /** @param array{name?: ?string, color?: ?string, letterhead?: array<string, ?string>, invoice_footer?: ?string} $data */
    public static function update(array $data, ?int $by): array
    {
        $b = (array) FirmSettings::get('branding', []);
        foreach (['name', 'color', 'invoice_footer'] as $k) {
            if (array_key_exists($k, $data)) {
                $b[$k] = $data[$k];
            }
        }
        if (array_key_exists('letterhead', $data)) {
            $b['letterhead'] = array_intersect_key((array) $data['letterhead'], array_flip(['address', 'phone', 'email', 'website', 'tax_pin']));
        }
        FirmSettings::set('branding', $b, $by);

        return self::get();
    }

    public static function setLogo(UploadedFile $file, ?int $by): array
    {
        // judged by its bytes, never its name or the browser's label: a raster image of an accepted type, or nothing
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        $type = is_array($info) ? (string) $info['mime'] : '';
        $ext = self::LOGO_TYPES[$type] ?? throw new \InvalidArgumentException('The logo must be a PNG, JPEG or WebP image.');
        $b = (array) FirmSettings::get('branding', []);
        self::deleteStored($b);
        $disk = FirmStorage::disk();
        $path = app(TenantContext::class)->storagePrefix('branding').'/logo-'.bin2hex(random_bytes(6)).'.'.$ext;
        Storage::disk($disk)->put($path, $bytes);
        $b['logo'] = ['disk' => $disk, 'path' => $path, 'type' => $type, 'version' => (int) (($b['logo']['version'] ?? 0) + 1)];
        FirmSettings::set('branding', $b, $by);

        return self::get();
    }

    public static function removeLogo(?int $by): array
    {
        $b = (array) FirmSettings::get('branding', []);
        self::deleteStored($b);
        unset($b['logo']);
        FirmSettings::set('branding', $b, $by);

        return self::get();
    }

    /** The logo's bytes and type, or null. */
    public static function logo(): ?array
    {
        $logo = self::get()['logo'];
        if (! $logo || ! Storage::disk($logo['disk'])->exists($logo['path'])) {
            return null;
        }

        return [(string) Storage::disk($logo['disk'])->get($logo['path']), $logo['type']];
    }

    /** The logo as a data URI, for documents rendered to PDF. */
    public static function logoDataUri(): ?string
    {
        $l = self::logo();

        return $l ? 'data:'.$l[1].';base64,'.base64_encode($l[0]) : null;
    }

    private static function deleteStored(array $b): void
    {
        if (isset($b['logo']['disk'], $b['logo']['path'])) {
            Storage::disk($b['logo']['disk'])->delete($b['logo']['path']);
        }
    }
}
