<?php

/**
 * Minimal stand-ins for WordPress escaping and input helpers, used when a
 * test does not stub them. Loaded after Patchwork, so Brain Monkey can still
 * redefine any of them per test.
 */

declare(strict_types=1);

if (!function_exists('esc_html')) {
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data)
    {
        return is_array($data) || is_object($data) ? serialize($data) : $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data)
    {
        if (!is_string($data)) {
            return $data;
        }
        $value = @unserialize($data);

        return $value === false && $data !== 'b:0;' ? $data : $value;
    }
}
