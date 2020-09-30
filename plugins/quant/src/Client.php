<?php

namespace Quant;

use Quant\Settings;

class Client
{

    private $settings;
    private $headers = [];
    private $endpoint;
    private $webserver;
    private $host;

    public function __construct() {
        $this->settings = get_option(QUANT_SETTINGS_KEY);
        $this->webserver = $this->settings['webserver_url'];
        $this->host = $this->settings['webserver_host'];
        $this->endpoint = $this->settings['api_endpoint'];
        $this->headers['Content-type'] = 'application/json';
        $this->headers['quant-project'] = $this->settings['api_project'];
        $this->headers['quant-customer'] = $this->settings['api_account'];
        $this->headers['quant-token'] = $this->settings['api_token'];
    }

    public function ping() {

        $endpoint = $this->endpoint . '/ping';
        $args = [
            'headers' => $this->headers,
        ];
        $response = wp_remote_get($endpoint, $args);

        if (!is_array($response) || $response['response']['code'] != 200) {
            return FALSE;
        }

        return TRUE;
    }

    public function unpublish($route) {
        $endpoint = $this->endpoint . '/unpublish';
        $headers = $this->headers;
        $headers['quant-url'] = $route;

        $args = [
            'headers' => $headers,
            'method' => 'PATCH'
        ];

        $response = wp_remote_request($endpoint, $args);
        $body = wp_remote_retrieve_body($response);
    }

    public function redirect($from, $to, $code) {

        $data = [
            'url' => $from,
            'redirect_url' => $to,
            'redirect_http_code' => $code,
            'published' => TRUE,
        ];

        $args = [
            'headers' => $this->headers,
            'body' => json_encode($data),
        ];

        $response = wp_remote_post($this->endpoint . '/redirect', $args);
        $body = wp_remote_retrieve_body($response);

        return $body;
    }

    /**
     * Send markup via the Quant API. Returns detected assets.
     *
     * @param string $route
     * @param string $markup
     * @return array $assets
     */
    public function content($data) {

        $args = [
            'headers' => $this->headers,
            'body' => json_encode($data),
        ];

        $response = wp_remote_post($this->endpoint, $args);
        $body = wp_remote_retrieve_body($response);

        return $body;
    }

    /**
     * Send file via the Quant API.
     *
     * @param string $file
     *      The absolute path to the file on disk
     */
    public function file($route, $path) {

        $headers = $this->headers;
        $headers['Content-type']  = 'application/binary';
        $headers['Quant-File-Url'] = $route;

        $endpoint = $this->endpoint . '/file-upload?path=' . $path;
        $args = [
            'headers' => $headers,
        ];

        $response = wp_remote_post($endpoint, $args);
        $body = wp_remote_retrieve_body($response);

        return $body;
    }

    /**
     * Send media/attachments if the existing md5 does not match
     *
     * @param $media Array
     */
    public function sendAttachments($media) {

        foreach ($media as $item) {
            $url = urldecode($item['path']);

            // Ignore anything that isn't relative for now.
            if (substr($url, 0, 1) != "/") {
                continue;
            }

            // Strip query params.
            $file = strtok($url, '?');

            if (isset($item['existing_md5'])) {
                // Skip file: MD5 matches.
                if (file_exists(ABSPATH . $file) && md5_file(ABSPATH . $file) == $item['existing_md5']) {
                    continue;
                }
            }

            if (file_exists(ABSPATH . $file)) {
                $this->file($file, ABSPATH . $file);
            }
        }
    }

    /**
     * Send category markup to Quant.
     *
     * @param $id integer
     */
    public function sendCategory($id, $page=null) {

        $permalink = wp_make_link_relative(get_term_link($id));

        if (!empty($page)) {
            $permalink .= "page/$page/";
        }

        $markup = $this->markupFromRoute($permalink);

        // Markup is blank on 404.
        if (empty($markup)) {
            $this->unpublish($permalink);
            return;
        }

        $payload = [
            'url' => $permalink,
            'content' => $markup,
            'published' =>  true,
        ];

        $res = json_decode($this->content($payload), TRUE);

        if (isset($res['attachments'])) {
            $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);
            $this->sendAttachments($media);
        }
    }


    /**
     * Send arbitrary route markup to Quant.
     *
     * @param $id integer
     */
    public function sendRoute($route) {

        $markup = $this->markupFromRoute($route);

        // Markup is blank on 404.
        if (empty($markup)) {
            $this->unpublish($route);
            return;
        }

        $payload = [
            'url' => $route,
            'content' => $markup,
            'published' =>  true,
        ];

        $res = json_decode($this->content($payload), TRUE);

        if (isset($res['attachments'])) {
            $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);
            $this->sendAttachments($media);
        }
    }

    /**
     * Send arbitrary route markup to Quant.
     *
     * @param $id integer
     */
    public function send404Route($route) {

        $markup = $this->markupFromRoute($route, true);

        $payload = [
            'url' => "/_quant404",
            'content' => $markup,
            'published' =>  true,
        ];

        $res = json_decode($this->content($payload), TRUE);

        if (isset($res['attachments'])) {
            $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);
            $this->sendAttachments($media);
        }
    }

    /**
     * Send post/page markup to Quant.
     *
     * @param $id integer
     */
    public function sendPost($id) {
        $permalink = wp_make_link_relative(get_permalink($id));
        $markup = $this->markupFromRoute($permalink);

        // Markup is blank on 404.
        if (empty($markup)) {
            $this->unpublish($permalink);
            return;
        }

        $payload = [
            'url' => $permalink,
            'content' => $markup,
            'published' =>  get_post_status($id) === 'publish',
            'content_timestamp' => get_post_modified_time('U', false, $id),
            'info' => [
                'author' => '@todo',
                'log' => '@todo'
            ],
        ];

        // @todo: Create plain permalink (?p=123 to slug)
        //$this->redirect($permalink, $slug, 301);

        $res = json_decode($this->content($payload), TRUE);

        if (isset($res['attachments'])) {
            $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);
            $this->sendAttachments($media);
        }
    }

    /**
     * Retrieve markup from route.
     *
     * @param $route string
     * @param $allow404 bool
     * @return $markup or FALSE
     */
    public function markupFromRoute($route, $allow404 = false) {

        $token = get_option('quant_internal_token');

        $endpoint = $this->webserver . $route;
        $args = [
            'headers' => [
                'Host' => $this->host,
                'Quant-Token' => $token,
            ]
        ];

        $response = wp_remote_get($endpoint, $args);
        $status = wp_remote_retrieve_response_code($response);

        if ($status == 404 && !$allow404) {
            return FALSE;
        }

        $body = wp_remote_retrieve_body($response);
        $body = $this->absoluteToRelative($body);

        return $body;
    }

    /**
     * Attempt to replace absolute paths with relative ones.
     * @todo; A more wordpressy way of doing this?
     */
    public function absoluteToRelative($markup) {
        return str_replace(get_site_url(), '', $markup);
    }

}
