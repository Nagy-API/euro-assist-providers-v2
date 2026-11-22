<?php

namespace App\Services\Transfers;

use App\Rules\SafeHttpUrl;
use Illuminate\Support\Facades\Validator;

/**
 * Field rules identical to App\Services\ProviderService::validate() (kept in sync by RowRulesParityTest),
 * minus the "provider_types exists" rule: the type is resolved from an in-memory map so validation
 * performs ZERO database queries per row. Operates on one row = one locale, so translation fields
 * are flat keys.
 */
class RowRules
{
    /** @var array<string, list<string|SafeHttpUrl>>|null */
    private static ?array $rules = null;

    /** @return array<string, list<string|SafeHttpUrl>> */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $rules = [
            'phone' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'string', 'max:500', new SafeHttpUrl(allowBare: true)],
            'map_url' => ['nullable', 'string', 'max:2048', new SafeHttpUrl(allowBare: true)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
        foreach (TemplateSchema::translationFields() as $field) {
            $max = in_array($field, ['specialization', 'hours', 'discount_text', 'package_text'], true) ? 5000
                : ($field === 'address' ? 500 : 255);
            $rules[$field] = ['nullable', 'string', "max:$max"];
        }

        return self::$rules = $rules;
    }

    /**
     * @param  array<string, string|null>  $values  only the columns present in the file
     * @return list<array{field: string, code: string, params: array<string, scalar|null>}>
     */
    public function check(array $values): array
    {
        $rules = array_intersect_key(self::rules(), $values);
        if ($rules === []) {
            return [];
        }

        $validator = Validator::make($values, $rules);
        if (! $validator->fails()) {
            return [];
        }

        $errors = [];
        foreach ($validator->failed() as $field => $failed) {
            foreach ($failed as $rule => $parameters) {
                $errors[] = [
                    'field' => (string) $field,
                    'code' => $rule === SafeHttpUrl::class ? 'validation_url' : 'validation_'.strtolower($rule),
                    'params' => $parameters !== [] ? ['value' => implode(',', array_map('strval', $parameters))] : [],
                ];
            }
        }

        return $errors;
    }
}
