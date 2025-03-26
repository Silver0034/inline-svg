<?php

namespace InlineSVG;

/**
 * Plugin Name: Inline SVG
 * Description: A WordPress plugin to easily inline SVGs in your content.
 * Version: 1.0.0
 * Author: Jacob Lodes
 * Author URI: https://jlodes.com
 * Text Domain: inline-svg
 */

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private $transient_prefix = '_transient_inline_svg_';
    private $transient_expiration = 24 * HOUR_IN_SECONDS;
    private $home_url_host;

    public function __construct()
    {
        add_action('init', [$this, 'init']);
        add_filter('upload_mimes', [$this, 'allow_svg_uploads']);
        add_filter('wp_handle_upload_prefilter', [$this, 'sanitize_uploaded_svg']);
        add_filter('wp_check_filetype_and_ext', [$this, 'bypass_svg_image_size_check'], 10, 4);
        register_deactivation_hook(__FILE__, [$this, 'delete_svg_transients']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'delete_svg_transients']);
    }

    public function allow_svg_uploads($mimes): array
    {
        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }

    private function copy_attributes_from_image_to_svg($image_html, $svg_html): string
    {
        preg_match_all('/(class|style|alt|title|width|height|id|data-[^=]+|aria-[^=]+|src)="([^"]*)"/i', $image_html, $attr_matches, PREG_SET_ORDER);

        foreach ($attr_matches as [$full_match, $key, $value]) {
            $key = $key === 'src' ? 'data-src' : $key;
            $value = esc_attr($value);

            if (preg_match('/<svg[^>]*\b' . preg_quote($key) . '="[^"]*"/i', $svg_html)) {
                // Replace existing attribute
                $svg_html = preg_replace(
                    '/(<svg[^>]*\b' . preg_quote($key) . ')="[^"]*"/i',
                    '$1="' . $value . '"',
                    $svg_html,
                    1
                );
            } else {
                // Add new attribute
                $svg_html = str_replace(
                    '<svg ',
                    '<svg ' . $key . '="' . $value . '" ',
                    $svg_html
                );
            }
        }

        return $svg_html;
    }

    public function disable_ssl_verification_on_local_requests($args, $url): array
    {
        if (strpos($url, '.svg') === false) {
            return $args;
        }

        if (strpos($this->get_home_url_host(), '.local') !== false) {
            $args['sslverify'] = false;
        }
        return $args;
    }

    private function get_svg_content($image_url): false|string
    {
        $cached_image = $this->get_transient($image_url);
        if ($cached_image !== false) {
            return $cached_image;
        }

        $response = wp_remote_get($image_url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            error_log("Failed to fetch SVG: $image_url");
            return false;
        }

        $svg_content = wp_remote_retrieve_body($response);
        if (empty($svg_content)) {
            error_log("Empty SVG content: $image_url");
            return false;
        }

        $svg_content = $this->sanitize_svg_content($svg_content);
        if (empty($svg_content)) {
            error_log("Sanitization failed for SVG: $image_url");
            return false;
        }

        $this->set_transient($image_url, $svg_content);
        return $svg_content;
    }

    private function get_transient($image_url): false|string
    {
        $transient_key = $this->get_transient_key($image_url);
        $cached_svg = get_transient($transient_key);

        if ($cached_svg === false) {
            return false;
        }

        return $cached_svg;
    }

    private function get_transient_key($image_url): string
    {
        return $this->transient_prefix . md5($image_url);
    }

    private function get_home_url_host(): string
    {
        if (!$this->home_url_host) {
            $this->home_url_host = parse_url(home_url(), PHP_URL_HOST);
        }
        return $this->home_url_host;
    }

    public function init(): void
    {
        add_filter('http_request_args', [$this, 'disable_ssl_verification_on_local_requests'], 10, 2);
        add_filter('render_block', [$this, 'inline_svg_render_block'], 10, 1);
    }

    public function inline_svg_render_block($block_content): string
    {
        if (!preg_match('/<img[^>]+src=["\']([^"\']+\.svg)["\'][^>]*>/i', $block_content, $matches)) return $block_content;

        $image_tag = $matches[0];
        $image_url = $matches[1];
        if (empty($image_url)) return $block_content;

        if (parse_url($image_url, PHP_URL_HOST) !== $this->get_home_url_host()) {
            return $block_content;
        }

        $svg_content = $this->get_svg_content($image_url);
        if ($svg_content === false) {
            return $block_content;
        }

        $svg_content = $this->copy_attributes_from_image_to_svg($image_tag, $svg_content);

        return str_replace($image_tag, $svg_content, $block_content);
    }

    public function sanitize_svg_content($svg_content): string
    {
        $allowed_tags = [
            'svg'       => ['xmlns' => true, 'viewBox' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'id' => true, 'class' => true],
            'g'         => ['fill' => true, 'stroke' => true, 'id' => true, 'class' => true],
            'path'      => ['d' => true, 'fill' => true, 'stroke' => true, 'id' => true, 'class' => true],
            'circle'    => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'id' => true, 'class' => true],
            'rect'      => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true, 'id' => true, 'class' => true, 'rx' => true],
            'line'      => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'id' => true, 'class' => true],
            'polygon'   => ['points' => true, 'fill' => true, 'id' => true, 'class' => true],
            'polyline'  => ['points' => true, 'fill' => true, 'id' => true, 'class' => true],
            'ellipse'   => ['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'id' => true, 'class' => true],
            'title'     => ['id' => true, 'class' => true],
            'desc'      => ['id' => true, 'class' => true],
        ];

        return wp_kses($svg_content, $allowed_tags);
    }

    public function sanitize_uploaded_svg($file): array
    {
        if (!isset($file['type']) || $file['error'] !== UPLOAD_ERR_OK || $file['type'] !== 'image/svg+xml') {
            return $file;
        }

        $svg_content = file_get_contents($file['tmp_name']);
        $sanitized_svg = $this->sanitize_svg_content($svg_content);

        if (empty($sanitized_svg)) {
            $file['error'] = 'The uploaded SVG file is invalid or could not be sanitized.';
        } else {
            file_put_contents($file['tmp_name'], $sanitized_svg);
        }

        return $file;
    }

    public function set_transient($image_url, $svg_content): void
    {
        $cache_key = $this->get_transient_key($image_url);
        set_transient($cache_key, $svg_content, $this->transient_expiration);
    }

    public function delete_svg_transients(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$this->transient_prefix}%'");
    }

    public function bypass_svg_image_size_check($data, $_, $filename, $__)
    {
        if (empty($filename)) {
            return $data;
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if (empty($ext) || strtolower($ext) !== 'svg') {
            return $data;
        }

        $data['ext'] = 'svg';
        $data['type'] = 'image/svg+xml';

        return $data;
    }
}

new Plugin();
