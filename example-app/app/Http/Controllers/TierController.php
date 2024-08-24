<?php

namespace App\Http\Controllers;

use Flyo\Api\EntitiesApi;
use Flyo\Configuration;
use Illuminate\Contracts\View\Factory;

class TierController extends Controller
{
    public function __construct(public Factory $viewFactory, public Configuration $config) {}

    public function show(string $slug)
    {
        $api = new EntitiesApi(null, $this->config);

        $entity = $api->entityBySlug($slug);

        return $this->viewFactory->make('tier', [
            'entity' => $entity,
        ]);
    }
}
