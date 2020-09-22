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

        if ($response['response']['code'] != 200) {
            return FALSE;
        }

        return TRUE;
    }

    public function unpublish($route) {
        $endpoint = $this->endpoint . '/unpublish';
        $headers = $this->headers;
        $headers['quant-url'] = $route;

        error_log("MEANT TO BE UNPUBLISHING $route");

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

        error_log(print_r($data, TRUE));
        
        $args = [
            'headers' => $this->headers,
            'body' => json_encode($data),
        ];

        $response = wp_remote_post($this->endpoint . '/redirect', $args);
        $body = wp_remote_retrieve_body($response);

        error_log($body);

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

        $args = [
            'headers' => $headers,
        ];

        // @todo: Replace with a Wordpress HTTP API request.
        $curl_headers = array();
        foreach ($headers as $header => $value) {
          $curl_headers[] = "{$header}: {$value}";
        }
      
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      
        $data['data'] = curl_file_create(
          $path,
          mime_content_type($path),
          basename($path)
        );
      
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      
        $result = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
      
        $response = json_decode($result);

        return $response;
    }

    public function sendPost($id) {
        $permalink = wp_make_link_relative(get_permalink($id));
        $markup = $this->markupFromRoute($permalink);

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

        $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);

        foreach ($media as $item) {
            // @todo: Determine local vs. remote.
            // @todo: Configurable to disallow remote files.
            // @todo: Strip base domain.
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

    public function markupFromRoute($route) {
        $endpoint = $this->webserver . $route;
        $args = [
            'headers' => [
                'Host' => $this->host,
                'Quant-Token' => 'ABC123',
            ]
        ];

        $response = wp_remote_get($endpoint, $args);
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