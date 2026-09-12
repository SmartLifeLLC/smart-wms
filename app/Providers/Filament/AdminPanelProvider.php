<?php

namespace App\Providers\Filament;

use App\Enums\EMenuCategory;
use Archilex\AdvancedTables\Plugin\AdvancedTablesPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Sakemaru\Auth\Services\PermissionService;
use WatheqAlshowaiter\FilamentStickyTableHeader\StickyTableHeaderPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->favicon(asset('favicon.ico'))
            ->topNavigation() // トップナビゲーションを有効化
            ->maxContentWidth('full')
            ->breadcrumbs(false) // パンくずリストを無効化
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->darkMode(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->navigationGroups(
                collect(EMenuCategory::cases())
                    ->sortBy(fn (EMenuCategory $category) => $category->sort())
                    ->map(fn (EMenuCategory $category) => NavigationGroup::make($category->label()))
                    ->values()
                    ->toArray()
            )
            ->navigationItems([
                NavigationItem::make('API仕様書')
                    ->url('/api/documentation', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-link')
                    ->group(EMenuCategory::SETTINGS->label())
                    ->visible(fn (): bool => $this->can('wms.api-document.view')),
                NavigationItem::make('倉庫移動伝票')
                    ->url(config('app.core_url').'/stocks/inventory/transfer', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrows-right-left')
                    ->group(EMenuCategory::ORDER_HISTORY->label())
                    ->visible(fn (): bool => $this->can('wms.warehouse-stock-transfer-delivery-course.view'))
                    ->sort(4),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // AuthenticateSession::class, // Disabled for cross-app session sharing
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                StickyTableHeaderPlugin::make(),
                AdvancedTablesPlugin::make()
                    ->userViewsEnabled(false)
                    ->resourceNavigationGroup(EMenuCategory::SETTINGS->label())
                    ->resourceNavigationSort(1000)
                    ->userView(\App\Models\FilamentFilterSets\UserView::class)
                    ->managedUserView(\App\Models\FilamentFilterSets\ManagedUserView::class)
                    ->managedPresetView(\App\Models\FilamentFilterSets\ManagedPresetView::class)
                    ->managedDefaultView(\App\Models\FilamentFilterSets\ManagedDefaultView::class),
            ]
            )
            ->authMiddleware([
                Authenticate::class,
            ])->authGuard('web')
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('livewire.warehouse-selector-hook'),
            );
    }

    protected function can(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(PermissionService::class)->check($user, $permission);
    }
}
