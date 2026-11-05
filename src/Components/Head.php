<?php

namespace Flyo\Laravel\Components;

use Flyo\Bridge\Image;
use Flyo\Laravel\DraftMode;
use Flyo\Model\Entity;
use Flyo\Model\Page as PageModel;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

class Head extends Component
{
    public static array $scripts = [];

    public static array $jsonLd = [];

    public static array $metas = [];

    public function __construct(public Factory $viewFactory) {}

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

    public static function jsonLd(array|object|null $jsonLd)
    {
        self::$jsonLd = $jsonLd === null ? [] : (array) $jsonLd;
    }

    public static function script(string $script)
    {
        self::$scripts[] = $script;
    }

    public static function canonical(string $url)
    {
        $url = rtrim(request()->root(), '/').'/'.ltrim($url, '/');
        self::$metas['canonical'] = $url;
    }

    /**
     * Flags the current document as not indexable, which renders a robots noindex meta tag.
     */
    public static function noIndex(bool $noIndex = true)
    {
        if ($noIndex) {
            self::$metas['noindex'] = true;
        } else {
            unset(self::$metas['noindex']);
        }
    }

    /**
     * Whether the given is_indexable api value marks the document as not indexable.
     *
     * The value is nullable (and typed as int for pages, bool for entities), a missing value is not
     * an instruction to hide the document from search engines.
     */
    private static function isNotIndexable(mixed $isIndexable): bool
    {
        return $isIndexable !== null && ! $isIndexable;
    }

    /**
     * Assigns the meta informations and the schema.org json-ld object of a page response.
     */
    public static function metaPage(PageModel $page)
    {
        $meta = $page->getMetaJson();

        if ($meta !== null) {
            if (($title = self::stringOrNull($meta->getTitle())) !== null) {
                self::metaTitle($title);
            }

            if (($description = self::stringOrNull($meta->getDescription())) !== null) {
                self::metaDescription($description);
            }

            if (($image = self::stringOrNull($meta->getImage())) !== null) {
                self::metaImage($image);
            }
        }

        if (! empty($page->getHref())) {
            self::canonical($page->getHref());
        }

        self::noIndex(self::isNotIndexable($page->getIsIndexable()));

        self::jsonLd($page->getJsonld());
    }

    /**
     * Assigns the meta informations and the schema.org json-ld object of an entity response.
     *
     * An entity delivered through a draft link is flagged as uncacheable here as well, so an entity
     * page assigning its meta data through this method is protected without a further call, see
     * DraftMode.
     */
    public static function metaEntity(Entity $entity)
    {
        DraftMode::detect($entity);

        self::metaTitle($entity->getEntity()->getEntityTitle());
        self::metaDescription($entity->getEntity()->getEntityTeaser());
        self::metaImage($entity->getEntity()->getEntityImage());
        self::jsonLd($entity->getJsonld());

        if (! empty($entity->getCanonical())) {
            self::canonical($entity->getCanonical());
        }

        self::noIndex(self::isNotIndexable($entity->getIsIndexable()));

        if (config('app.env') === 'production') {
            self::script('fetch('.self::jsonForScript($entity->getEntity()->getEntityMetric()->getApi()).')');
        }
    }

    /**
     * Normalizes an api response value into a usable meta string.
     *
     * Values of the api response are optional and therefore nullable, some of them are typed as
     * models without properties by the php sdk (like the meta image), those must not end up in the
     * head as their json encoded representation.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Encodes a value as json which can be placed inside a script tag, everything which could close
     * the tag or open a html comment is escaped.
     */
    private static function jsonForScript(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * Escapes a value for a html attribute or the title element.
     *
     * An already escaped value (like `e($title)` passed to metaTitle()) is not escaped a second time.
     */
    private static function escape(mixed $value): string
    {
        return e((string) $value, false);
    }

    /**
     * The meta tags are returned as html string, a plain string returned by a component would be
     * compiled as blade template by laravel.
     */
    public function render(): HtmlString
    {
        $html = '';

        if (self::$metas['title'] ?? false) {
            $title = self::escape(self::$metas['title']);
            $html .= '<title>'.$title.'</title>'.PHP_EOL;
            $html .= '<meta property="og:title" content="'.$title.'">'.PHP_EOL;
            $html .= '<meta name="twitter:title" content="'.$title.'">'.PHP_EOL;
        }

        if (self::$metas['description'] ?? false) {
            $description = self::escape(self::$metas['description']);
            $html .= '<meta name="description" content="'.$description.'">'.PHP_EOL;
            $html .= '<meta property="og:description" content="'.$description.'">'.PHP_EOL;
            $html .= '<meta name="twitter:description" content="'.$description.'">'.PHP_EOL;
        }

        if (self::$metas['image'] ?? false) {
            $img = self::escape(Image::source(self::$metas['image'], 1200, 630, 'jpg'));
            $html .= '<meta property="og:image" content="'.$img.'">'.PHP_EOL;
            $html .= '<meta name="twitter:image" content="'.$img.'">'.PHP_EOL;
            $html .= '<meta property="twitter:card" content="summary_large_image">'.PHP_EOL;
        }

        if (self::$metas['canonical'] ?? false) {
            $html .= '<link rel="canonical" href="'.self::escape(self::$metas['canonical']).'">'.PHP_EOL;
        }

        if (self::$metas['noindex'] ?? false) {
            $html .= '<meta name="robots" content="noindex">'.PHP_EOL;
        }

        $appName = config('app.name', '');

        if (! empty($appName)) {
            $html .= '<meta property="og:site_name" content="'.self::escape($appName).'">'.PHP_EOL;
        }

        $currentAbsoluteUrl = url()->current();

        if (! empty($currentAbsoluteUrl)) {
            $html .= '<meta property="og:url" content="'.self::escape($currentAbsoluteUrl).'">'.PHP_EOL;
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
            $html .= json_encode(self::$jsonLd, JSON_HEX_TAG);
            $html .= '</script>';
        }

        return new HtmlString($html);
    }
}
