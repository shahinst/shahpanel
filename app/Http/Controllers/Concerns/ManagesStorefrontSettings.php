<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\UpdateStorefrontRequest;
use App\Models\Storefront;
use App\Support\StorefrontSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

trait ManagesStorefrontSettings
{
    abstract protected function storefrontEditView(): string;

    abstract protected function storefrontEditRoute(): string;

    public function edit(Request $request): View
    {
        $storefront = $this->resolveStorefront($request);

        return view($this->storefrontEditView(), [
            'storefront' => $storefront,
            'storefrontEnhanced' => StorefrontSchema::isEnhanced(),
            'storefrontMigrationPending' => ! StorefrontSchema::isEnhanced(),
        ]);
    }

    public function update(UpdateStorefrontRequest $request): RedirectResponse
    {
        $storefront = $this->resolveStorefront($request);

        try {
            $attributes = StorefrontSchema::filterAttributes($request->validated());
            $attributes['is_published'] = $request->boolean('is_published');

            if (StorefrontSchema::hasColumn('updated_at')) {
                $attributes['updated_at'] = now();
            }

            $storefront->update($attributes);
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', StorefrontSchema::isEnhanced()
                    ? __('app.error')
                    : __('storefront.migration_required'));
        }

        return redirect()
            ->route($this->storefrontEditRoute())
            ->with('success', __('app.saved'));
    }

    protected function resolveStorefront(Request $request): Storefront
    {
        return Storefront::query()->firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'brand_name' => $request->user()->full_name,
                'slug' => $this->uniqueSlugForUser($request->user()->username),
                'primary_color' => config('vpnpanel.accent_color', '#6366f1'),
                'is_published' => false,
            ]
        );
    }

    protected function uniqueSlugForUser(string $base): string
    {
        $slug = Str::slug($base) ?: 'store';
        $candidate = $slug;
        $suffix = 1;

        while (Storefront::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
