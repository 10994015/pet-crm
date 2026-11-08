<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // The reset link goes out by email, so it's only offered once the site can send one.
            ->passwordReset(config('mail.default') === 'log' ? null : RequestPasswordReset::class)
            ->profile(isSimple: false)
            ->brandName(config('app.name'))
            ->brandLogo(fn () => view('filament.brand.logo'))
            ->font('Montserrat', provider: GoogleFontProvider::class)
            ->darkMode(false)
            ->sidebarWidth('14rem')
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => '#0e8f08',
                'purple' => Color::Violet,
                'orange' => Color::Amber,
                'green' => Color::Emerald,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;600;700&display=swap">'
                .'<link rel="stylesheet" href="'.asset('css/bibidog.css').'?v='.filemtime(public_path('css/bibidog.css')).'">'
                .'<script defer src="'.asset('js/instant-pick.js').'?v='.filemtime(public_path('js/instant-pick.js')).'"></script>'
                .'<script defer src="'.asset('js/busy.js').'?v='.filemtime(public_path('js/busy.js')).'"></script>'
            ))
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('filament.brand.sidebar-user'))
            ->renderHook(PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, fn (array $scopes) => view('filament.brand.page-insights', ['scopes' => $scopes]))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn () => view('filament.brand.sidebar-art'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.brand.topbar-bell'))
            ->renderHook(PanelsRenderHook::USER_MENU_AFTER, fn () => new HtmlString(
                '<span class="bibi-user-name">'.e(auth()->user()?->name).'</span>'
            ))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
