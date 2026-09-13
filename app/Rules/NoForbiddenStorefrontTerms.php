<?php

namespace App\Rules;

use App\Support\ForbiddenStorefrontTerms;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoForbiddenStorefrontTerms implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $term = ForbiddenStorefrontTerms::detectedTerm($value);

        if ($term !== null) {
            $fail(__('storefront.forbidden_term_field', ['term' => $term]));
        }
    }
}
