<?php

namespace Flyo\Laravel\Components;

use Flyo\Model\Entity;
use Flyo\Model\Page as ModelPage;
use Illuminate\Contracts\View\Factory;
use Illuminate\View\Component;

class Head extends Component
{
    public static array $scripts = [];

    public static array $metas = [];

    public function __construct(public ModelPage $page, public Factory $viewFactory) {}

    public static function metaTitle(string $title)
    {
        self::$metas['title'] = $title;
    }

    public static function metaDescription(string $description)
    {
        self::$metas['description'] = $description;
    }

    public static function metaImage(string $image)
    {
        self::$metas['image'] = $image;
    }

    public static function metaEntity(Entity $entity)
    {
        self::metaTitle($entity->getEntity()->getEntityTitle());
        self::metaDescription($entity->getEntity()->getEntityTeaser());
        self::metaImage($entity->getEntity()->getEntityImage());
    }

    public function render(): string
    {
        $html = '';

        if (self::$metas['title']) {
            $html .= '<title>'.self::$metas['title'].'</title>';
            $html .= '<meta property="og:title" content="'.self::$metas['title'].'">';
            $html .= '<meta name="twitter:title" content="'.self::$metas['title'].'">';
        }

        if (self::$metas['description']) {
            $html .= '<meta name="description" content="'.self::$metas['description'].'">';
            $html .= '<meta property="og:description" content="'.self::$metas['description'].'">';
            $html .= '<meta name="twitter:description" content="'.self::$metas['description'].'">';
        }

        if (self::$metas['image']) {
            $html .= '<meta property="og:image" content="'.self::$metas['image'].'">';
            $html .= '<meta name="twitter:image" content="'.self::$metas['image'].'">';
        }

        if (count(self::$scripts) > 0) {
            $html .= '<script>';
            foreach (self::$scripts as $script) {
                $html .= $script;
            }
            $html .= '</script>';
        }

        return $html;
    }
}
