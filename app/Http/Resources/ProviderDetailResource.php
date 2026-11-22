<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Detail view: the list representation plus every stored translation keyed by locale. */
class ProviderDetailResource extends ProviderResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return parent::toArray($request) + ['translations' => $this->translationsMap()];
    }
}
