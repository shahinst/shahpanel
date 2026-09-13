<?php

namespace App\Http\Requests;

use App\Models\Storefront;
use App\Rules\NoForbiddenStorefrontTerms;
use App\Support\StorefrontSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStorefrontRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        $nullable = [
            'tagline',
            'logo_path',
            'telegram_contact',
            'instagram_contact',
            'whatsapp_contact',
            'phone_contact',
            'email_contact',
            'website_url',
            'description',
            'support_note',
        ];

        $merged = [];

        foreach ($nullable as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $merged[$field] = null;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        $storefrontId = Storefront::query()
            ->where('user_id', $this->user()->id)
            ->value('id');

        $forbidden = new NoForbiddenStorefrontTerms;

        $rules = [
            'brand_name' => ['required', 'string', 'max:255', $forbidden],
            'slug' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                $forbidden,
                Rule::unique('storefronts', 'slug')->ignore($storefrontId),
            ],
            'primary_color' => ['required', 'string', 'max:20'],
            'logo_path' => ['nullable', 'string', 'max:500', $forbidden],
            'telegram_contact' => ['nullable', 'string', 'max:200', $forbidden],
            'phone_contact' => ['nullable', 'string', 'max:30', $forbidden],
            'description' => ['nullable', 'string', 'max:5000', $forbidden],
            'is_published' => ['sometimes', 'boolean'],
        ];

        if (StorefrontSchema::hasColumn('tagline')) {
            $rules['tagline'] = ['nullable', 'string', 'max:255', $forbidden];
        }

        if (StorefrontSchema::hasColumn('instagram_contact')) {
            $rules['instagram_contact'] = ['nullable', 'string', 'max:200', $forbidden];
        }

        if (StorefrontSchema::hasColumn('whatsapp_contact')) {
            $rules['whatsapp_contact'] = ['nullable', 'string', 'max:30', $forbidden];
        }

        if (StorefrontSchema::hasColumn('email_contact')) {
            $rules['email_contact'] = ['nullable', 'email', 'max:255'];
        }

        if (StorefrontSchema::hasColumn('website_url')) {
            $rules['website_url'] = ['nullable', 'url', 'max:500', $forbidden];
        }

        if (StorefrontSchema::hasColumn('support_note')) {
            $rules['support_note'] = ['nullable', 'string', 'max:2000', $forbidden];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'brand_name' => __('validation.attributes.brand_name'),
            'tagline' => __('storefront.tagline'),
            'slug' => __('validation.attributes.slug'),
            'primary_color' => __('storefront.primary_color'),
            'logo_path' => __('storefront.logo_url'),
            'telegram_contact' => __('storefront.telegram'),
            'instagram_contact' => __('storefront.instagram'),
            'whatsapp_contact' => __('storefront.whatsapp'),
            'phone_contact' => __('storefront.phone'),
            'email_contact' => __('storefront.email'),
            'website_url' => __('storefront.website'),
            'description' => __('storefront.description'),
            'support_note' => __('storefront.support_note'),
            'is_published' => __('storefront.is_published'),
        ];
    }
}
