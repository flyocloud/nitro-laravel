<?php

namespace Flyo\Laravel\Components;

use Flyo\Bridge\Image;
use Flyo\Model\Entity;
use Flyo\Model\Page as ModelPage;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Env;
use Illuminate\View\Component;

class Head extends Component
{
    public static array $scripts = [];

    public static array $jsonLd = [];

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

    public static function jsonLd(array|object $jsonLd)
    {
        self::$jsonLd = (array) $jsonLd;
    }

    public static function script(string $script)
    {
        self::$scripts[] = $script;
    }

    public static function metaEntity(Entity $entity)
    {
        self::metaTitle($entity->getEntity()->getEntityTitle());
        self::metaDescription($entity->getEntity()->getEntityTeaser());
        self::metaImage($entity->getEntity()->getEntityImage());
        self::jsonLd($entity->getJsonld());

        if (Env::get('APP_ENV') === 'production') {
            self::script("fetch('{$entity->getEntity()->getEntityMetric()->getApi()}')");
        }
    }

    public function render(): string
    {
        $html = '';

        if (self::$metas['title'] ?? false) {
            $html .= '<title>'.self::$metas['title'].'</title>'.PHP_EOL;
            $html .= '<meta property="og:title" content="'.self::$metas['title'].'">'.PHP_EOL;
            $html .= '<meta name="twitter:title" content="'.self::$metas['title'].'">'.PHP_EOL;
        }

        if (self::$metas['description'] ?? false) {
            $html .= '<meta name="description" content="'.self::$metas['description'].'">'.PHP_EOL;
            $html .= '<meta property="og:description" content="'.self::$metas['description'].'">'.PHP_EOL;
            $html .= '<meta name="twitter:description" content="'.self::$metas['description'].'">'.PHP_EOL;
        }

        if (self::$metas['image'] ?? false) {
            $img = Image::source(self::$metas['image'], 1200, 630, 'jpg');
            $html .= '<meta property="og:image" content="'.$img.'">'.PHP_EOL;
            $html .= '<meta name="twitter:image" content="'.$img.'">'.PHP_EOL;
        }

        if (count(self::$scripts) > 0) {
            $html .= '<script>';
            foreach (self::$scripts as $script) {
                $html .= $script.PHP_EOL;
            }
            $html .= '</script>';
        }

        if (count(self::$jsonLd) > 0) {
            $html .= '<script type="application/ld+json">';
            $html .= json_encode(self::$jsonLd);
            $html .= '</script>';
        }

        return $html;
    }
}
