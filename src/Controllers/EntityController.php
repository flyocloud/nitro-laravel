<?php

namespace Flyo\Laravel\Controllers;

use Exception;
use Flyo\Api\EntitiesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Model\Entity;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Generic Entity Controller Handling.
 * 
 * ```php
 * Route::get('/poi/{slug}', function($slug) {
 *   return app(EntityController::class)->resolve(fn(EntitiesApi $api, $param) => $api->entityBySlug($param))->render($slug, 'poi.detail');
 * });
 * ```
 */
class EntityController
{
    public function __construct(protected Factory $view, protected Configuration $config) {}

    private $resolver;

    public function resolve(callable $fn): self
    {
        $this->resolver = $fn;

        return $this;
    }

    public function render($param, $view): View
    {
        $api = new EntitiesApi(null, $this->config);

        try {
            $entity = call_user_func($this->resolver, $api, $param);

            if (! $entity instanceof Entity) {
                throw new Exception('The resolver must return an instance of Flyo\\Model\\Entity.');
            }
        } catch (\Exception $e) {
            abort(404, $e->getMessage());
        }

        Head::metaEntity($entity);

        return $this->view->make($view, [
            'model' => $entity->getModel(),
            'entity' => $entity->getEntity(),
        ]);
    }
}
