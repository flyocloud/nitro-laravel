<?php

namespace Flyo\Laravel\Controllers;

use Exception;
use Flyo\Api\EntitiesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Model\Entity;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Generic Entity Controller Handling.
 *
 * ```php
 * Route::get('/poi/{slug}', function($slug) {
 *   return app(EntityController::class)->resolve(fn(EntitiesApi $api, $param) => $api->entityBySlug($param))->render($slug, 'poi.detail');
 * });
 * ```
 *
 * A few more examples with an explicit entity type id:
 *
 * ```php
 * Route::get('/story/{slug}', function($slug) {
 *   return app(EntityController::class)->resolve(fn(EntitiesApi $api, $param) => $api->entityBySlug($param, 115))->render($slug, 'story.detail');
 * });
 * ```
 *
 * with by unique id
 *
 * ```php
 * Route::get('/event/{uid}', function($uid) {
 *   return app(EntityController::class)->resolve(fn(EntitiesApi $api, $param) => $api->entityByUniqueid($param, 117))->render($uid, 'event.detail');
 * });
 * ```
 *
 * with custom exception handling
 *
 * ```php
 * Route::get('/event/{uid}', function($uid) {
 *   return app(EntityController::class)
 *       ->resolve(fn(EntitiesApi $api, $param) => $api->entityByUniqueid($param, 117))
 *       ->onException(function($exception, $param, $view) {
 *           // Custom exception handling
 *           return response()->view('errors.custom', ['error' => $exception->getMessage()], 500);
 *       })
 *       ->render($uid, 'event.detail');
 * });
 * ```
 */
class EntityController
{
    public function __construct(protected Factory $view, protected Configuration $config) {}

    private $resolver;
    private $exceptionHandler;

    public function resolve(callable $fn): self
    {
        $this->resolver = $fn;

        return $this;
    }

    public function onException(callable $handler): self
    {
        $this->exceptionHandler = $handler;

        return $this;
    }

    public function render($param, $view): View|RedirectResponse
    {
        $api = new EntitiesApi(null, $this->config);

        try {
            $entity = call_user_func($this->resolver, $api, $param);

            if (! $entity instanceof Entity) {
                throw new Exception('The resolver must return an instance of Flyo\\Model\\Entity.');
            }
        } catch (\Exception $e) {
            if ($this->exceptionHandler) {
                return call_user_func($this->exceptionHandler, $e, $param, $view);
            }
            
            abort(404, $e->getMessage());
        }

        Head::metaEntity($entity);

        return $this->view->make($view, [
            'model' => $entity->getModel(),
            'entity' => $entity->getEntity(),
            'translation' => $entity->getTranslation(),
            'breadcrumb' => $entity->getBreadcrumb(),
        ]);
    }
}
