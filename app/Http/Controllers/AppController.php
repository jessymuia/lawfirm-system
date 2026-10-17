<?php

namespace App\Http\Controllers;

class AppController extends Controller
{
    /** "/": the operator console's front door on the platform host, the firm app's own page everywhere else. */
    public function home(\Illuminate\Http\Request $request)
    {
        return \App\Http\Middleware\PlatformHost::matches($request) ? redirect()->route('platform.console') : $this->index();
    }

    public function index()
    {
        return view('app', app(\App\Tenancy\LoginPage::class)->context(request()) + [
            'firmBilling' => (bool) config('tenancy.firm_self_service_billing'),
            'productName' => (string) config('app.name'),
            'productShort' => (string) config('app.short_name'),
        ]);
    }

    /** FUN-19: what makes the web app installable on a phone or desktop, named after the firm. */
    public function manifest()
    {
        $brand = \App\Branding\Branding::get();   // UI-13: the firm's own name and colour
        $name = $brand['name'];

        return response()->json([
            'name' => $name, 'short_name' => self::shortName($name), 'description' => 'Matters, hearings and documents',
            'id' => '/', 'start_url' => '/today?source=pwa', 'scope' => '/', 'display' => 'standalone', 'orientation' => 'any',
            'background_color' => '#f4f6f9', 'theme_color' => $brand['color'],
            'icons' => [
                ['src' => '/images/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/images/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/images/pwa/icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Today', 'url' => '/today'], ['name' => 'Court diary', 'url' => '/court-diary'], ['name' => 'Matters', 'url' => '/cases'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** FUN-19: the name under the home-screen icon: whole words up to 12 characters (the platforms cut longer ones). */
    public static function shortName(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
            if (mb_strlen(trim($out.' '.$word)) > 12) {
                break;
            }
            $out = trim($out.' '.$word);
        }
        $out = trim((string) preg_replace('/[^\p{L}\p{N}]+$/u', '', $out));

        return $out !== '' ? $out : mb_substr($name, 0, 12);
    }
}
