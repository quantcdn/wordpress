<?php

declare(strict_types=1);

namespace Quant\Sync;

enum SyncType: string
{
    case Post = 'post';
    case Page = 'page';
    case CustomPostType = 'custom_post_type';
    case Taxonomy = 'taxonomy';
    case Archive = 'archive';
    case Author = 'author';
    case Media = 'media';
    case ThemeAsset = 'theme_asset';
    case ThemeAssets = 'theme_assets';
    case Redirect = 'redirect';
    case CustomRoute = 'custom_route';
    case Route = 'route';
    case Home = 'home';
    case NotFound = 'not_found';
    case Sitemap = 'sitemap';
    case SearchRecord = 'search_record';
    case File = 'file';

    /**
     * Types whose object id is a WordPress post ID.
     */
    public function isPostBacked(): bool
    {
        return in_array($this, [self::Post, self::Page, self::CustomPostType, self::Media, self::SearchRecord], true);
    }

    /**
     * Types that publish rendered HTML for a URL path.
     */
    public function isRoute(): bool
    {
        return in_array($this, [
            self::Archive,
            self::Author,
            self::CustomRoute,
            self::Route,
            self::Home,
            self::NotFound,
            self::Sitemap,
        ], true);
    }
}
