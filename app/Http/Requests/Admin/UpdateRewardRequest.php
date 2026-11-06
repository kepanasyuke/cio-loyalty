<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cost_points' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['title', 'description', 'image_url'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        if ($this->has('cost_points')) {
            $payload['cost_points'] = (int) $this->input('cost_points');
        }

        if ($this->has('stock')) {
            $payload['stock'] = $this->filled('stock') ? (int) $this->input('stock') : null;
        }

        $payload['is_active'] = $this->boolean('is_active');

        return $payload;
    }
}
