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
    case Media = 'media';
    case ThemeAsset = 'theme_asset';
    case Redirect = 'redirect';
    case CustomRoute = 'custom_route';
}
