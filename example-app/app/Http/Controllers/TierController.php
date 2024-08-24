<?php

namespace App\Http\Controllers;

use Flyo\Api\EntitiesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Illuminate\Contracts\View\Factory;

class TierController extends Controller
{
    public function __construct(public Factory $viewFactory, public Configuration $config) {}

    public function show(string $slug)
    {
        $api = new EntitiesApi(null, $this->config);

        $entity = $api->entityBySlug($slug);

        Head::metaEntity($entity);

        return $this->viewFactory->make('tier', [
            'entity' => $entity,
        ]);
    }
}
