<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Bot\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(static function (Request $request): bool {
            $user = Auth::guard('admin')->user();

            return $user !== null && Gate::forUser($user)->allows('viewHorizon');
        });
    }

    /**
     * Only AdminUsers with the 'admin' role may view the Horizon dashboard.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (AdminUser $user): bool {
            return $user->role === 'admin';
        });
    }
}
