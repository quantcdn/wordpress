<?php

namespace Quant;

use Quant\Settings;

class Client
{

    private $settings;
    private $seedOptions;
    private $headers = [];
    private $endpoint;
    private $webserver;
    private $host;
    private $disableTlsVerify = FALSE;
    private $httpRequestTimeout = 15;

    function activate() {}
    function deactivate() {}

    public function __construct() {
        $settings = get_option(QUANT_SETTINGS_KEY);
        $this->seedOptions = get_option(QUANT_SEED_KEY);
        $this->settings = $settings;
        $this->webserver = $settings['webserver_url'];
        $this->host = $settings['webserver_host'];
        $this->endpoint = $settings['api_endpoint'] . '/v1';
        $this->headers['Content-type'] = 'application/json';
        $this->headers['quant-project'] = $settings['api_project'];
        $this->headers['quant-customer'] = $settings['api_account'];
        $this->headers['quant-token'] = $settings['api_token'];
        $this->disableTlsVerify = $settings['disable_tls_verify'];
        $this->httpRequestTimeout = intval($settings['http_request_timeout']) ?? 15;
    }

    public function ping() {

        $endpoint = $this->endpoint . '/ping';
        $args = [
            'headers' => $this->headers,
            'timeout' => $this->httpRequestTimeout,
        ];

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

        $response = wp_remote_get($endpoint, $args);

        if (!is_array($response) || $response['response']['code'] != 200) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Unpublishes a route.
     *
     * @param string $route
     */
    public function unpublish($route) {
        $endpoint = $this->endpoint . '/unpublish';
        $headers = $this->headers;
        $originalUrl = $route;

        // Strip trailing slashes from content routes (except home).
        if ( strlen( $route ) > 1 ) {
            $route  = rtrim($route, '/');
        }

        $headers['quant-url'] = $route;

        $args = [
            'headers' => $headers,
            'method' => 'PATCH',
            'timeout' => $this->httpRequestTimeout,
        ];

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

        // Ensure the URL is unpublished with trailing slash too.
        $this->purge($originalUrl);

        $response = wp_remote_request($endpoint, $args);
        $body = wp_remote_retrieve_body($response);
    }

    public function redirect($from, $to, $code) {

        // Strip trailing slashes from redirects.
        // This ensures consistency with how content routes are managed.
        if ( strlen( $from ) > 1 ) {
            $from = rtrim($from, '/');
        }

        if ( strlen( $to ) > 1 ) {
            $to = rtrim($to, '/');
        }

        // Bail if either from or to is empty.
        if (empty($from) || empty($to)) {
          return;
        }

        $data = [
            'url' => $from,
            'redirect_url' => $to,
            'redirect_http_code' => $code,
            'published' => TRUE,
        ];

        $args = [
            'headers' => $this->headers,
            'body' => json_encode($data),
            'timeout' => $this->httpRequestTimeout,
        ];

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

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

        $originalUrl = $data['url'];

        // Strip trailing slashes from content routes (except home).
        if ( strlen( $data['url'] ) > 1 ) {
            $data['url'] = rtrim($data['url'], '/');
        }

        // Bail if the URL is empty.
        if (empty($data['url'])) {
          return;
        }

        $args = [
            'headers' => $this->headers,
            'body' => json_encode($data),
            'timeout' => $this->httpRequestTimeout,
        ];

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

        $response = wp_remote_post($this->endpoint, $args);
        $body = wp_remote_retrieve_body($response);

        // Ensure the URL is unpublished with trailing slash too.
        $this->purge($originalUrl);

        return $body;
    }

    /**
     * Purge the cache for a route in Quant.
     *
     * @param string $route
     */
    public function purge($route) {

        $args = [
            'headers' => $this->headers,
            'timeout' => $this->httpRequestTimeout,
        ];

        $args['headers']['Quant-Url'] = $route;

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

        $response = wp_remote_post($this->endpoint . '/purge', $args);
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
            'timeout' => $this->httpRequestTimeout,
        ];

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

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

        // Determine current site (multisite installs).
        if (is_multisite()) {
            $site = get_site();
        }

        $attachments = [];

        foreach ($media as $item) {
            $url = urldecode($item['path']);

            // Ignore anything that isn't relative for now.
            if (substr($url, 0, 1) != "/") {
                continue;
            }

            // Strip query params.
            $file = $url = strtok($url, '?');

            // Strip the site path from the front of the URL.
            if (isset($site->path)) {
              $file = preg_replace("#^{$site->path}#", '/', $file);
            }

            if (isset($item['existing_md5'])) {
                // Skip file: MD5 matches.
                if (file_exists(ABSPATH . $file) && md5_file(ABSPATH . $file) == $item['existing_md5']) {
                    continue;
                }
            }

            if (file_exists(ABSPATH . $file)) {
                $attachments[] = [
                  'file' => $file,
                  'url' => $url,
                ];
            }
        }

        $this->multipleFiles($attachments);
    }


    /**
     * Send multiple files in parallel to Quant API.
     *
     * @param array $files
     *      Array of files to send in parallel.
     */
    public function multipleFiles($files) {

        $headers = $this->headers;
        $headers['Content-type']  = 'application/binary';

        $requests = [];

        foreach ($files as $file) {
            $headers['Quant-File-Url'] = $file['url'];
            $path = ABSPATH . $file['file'];

            $requests[] = [
                'url' => $this->endpoint . '/file-upload?path=' . $path,
                'type' => 'POST',
                'headers' => $headers,
                'sslverify' => $this->disableTlsVerify ? FALSE : TRUE,
                'timeout' => $this->httpRequestTimeout,
            ];
        }

        // Register hook to alter curl opts on each handle.
        $hooks = new \Requests_Hooks();
        $hooks->register('curl.before_multi_add', function(&$data) {
            quant_attach_file($data);
        });

        $options = [
          'hooks' => $hooks,
        ];

        $requests = \Requests::request_multiple($requests, $options);
    }


    /**
     * Send category markup to Quant.
     *
     * @param $id integer
     */
    public function sendCategory($id, $page=null) {

        $permalink = $this->permalinkToRelative(get_term_link($id));

        if (!empty($page)) {
            $permalink .= "page/$page/";
        }

        $data = $this->markupFromRoute($permalink);
        $markup = $data['content'];

        // Unpublish content on 404 or 403 response.
        if ($data['status'] == 403 || $data['status'] == 404) {
            $this->unpublish($permalink);
        }

        // Return if content is blank.
        if (empty($markup)) {
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

        // Ensure route is relative.
        $route = $this->absoluteToRelative($route, $this->host);

        // Odd behaviour when run via CLI.
        // Some routes begin with http: inexplicibly.
        $route = preg_replace('/^http:(\/)*/', '/', $route);
        $data = $this->markupFromRoute($route);
        $markup = $data['content'];
        $content_type = $data['content_type'];

        // Unpublish content on 404 or 403 response.
        if ($data['status'] == 403 || $data['status'] == 404) {
            $this->unpublish($route);
        }

        // Return if content is blank.
        if (empty($markup)) {
            return;
        }

        $payload = [
            'url' => $route,
            'content' => $markup,
            'published' =>  true,
            'headers' => [
                'content-type' => $content_type,
            ],
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

        $data = $this->markupFromRoute($route, true);
        $markup = $data['content'];

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

        $permalink = $this->permalinkToRelative(get_permalink($id));

        $data = $this->markupFromRoute($permalink);
        $markup = $data['content'];

        // Unpublish content on 404 or 403 response.
        if ($data['status'] == 403 || $data['status'] == 404) {
            $this->unpublish($permalink);
        }

        // Return if content is blank.
        if (empty($markup)) {
            return;
        }

        // Retrieve author info.
        $post = get_post($id);
        $author_id = $post->post_author;

        $payload = [
            'url' => $permalink,
            'content' => $markup,
            'published' =>  get_post_status($id) === 'publish',
            'content_timestamp' => get_post_modified_time('U', false, $id),
            'info' => [
                'author_name' => get_the_author_meta( 'display_name' , $author_id ),
            ],
            'search_record' => [
                'categories' => [
                    'content_type' => get_post_type($id),
                    'tags' => [],
                    'categories' => wp_get_post_categories($id, ['fields' => 'names']),
                    'site_id' => get_current_blog_id(),
                    'timestamp_modified' => get_post_modified_time('U', false, $id),
                    'timestamp_published' => get_post_time('U', false, $id),
                ]
            ]
        ];

        foreach (wp_get_post_tags($id) as $tag) {
            $payload['search_record']['categories']['tags'][] = $tag->name;
        }

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
            ],
            'timeout' => $this->httpRequestTimeout,
        ];

        if ($this->disableTlsVerify) {
          $args['sslverify'] = FALSE;
        }

        $response = wp_remote_get($endpoint, $args);
        $status = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);

        if ($status == 404 && !$allow404) {
            return [
                'content' => NULL,
                'content_type' => NULL,
                'status' => $status,
            ];
        }

        if ($status != 200 && $status != 404) {
            return [
                'content' => NULL,
                'content_type' => NULL,
                'status' => $status,
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $body = $this->absoluteToRelative($body, $this->host);
        $content_type = $headers['content-type'];

        return [
            'content' => $body,
            'content_type' => $content_type,
            'status' => $status,
        ];
    }

    /**
     * Attempt to replace absolute paths with relative ones.
     * @todo; A more wordpressy way of generating relative markup?
     */
    public function absoluteToRelative($markup, $host) {

        $port = $_SERVER['SERVER_PORT'];
        $markup = preg_replace("/http(s?)\:\/\/{$host}\:{$port}/i", '', $markup);
        $markup = preg_replace("/http(s?)\:\/\/{$host}/i", '', $markup);

        // Allow additional domain rewrites for relative paths.
        $stripDomains = explode("\n", $this->seedOptions['domains_strip'] ?? '');
        foreach ($stripDomains as $domain) {
            $d = trim($domain);

            if (!empty($d)) {
                $markup = preg_replace("/http(s?)\:\/\/{$d}\:{$port}/i", '', $markup);
                $markup = preg_replace("/http(s?)\:\/\/{$d}/i", '', $markup);
            }
        }

        // Quant enforces SSL.
        // The above catches expected local assets, this ensures external references are updated.
        $markup = preg_replace("/http\:\/\//i", 'https://', $markup);

        return str_replace(get_site_url(), '', $markup);
    }

    /**
     * Ensure the permalink generated is a relative path.
     */
    private function permalinkToRelative($permalink) {
        if (!is_string($permalink)) {
            // get_term_link applies filters which can operate unexpectedly on the terms
            // provided and this isn't always guaranteed to produce a string output.
            return false;
        }

        // Resolve rare error where protocol is repeated.
        $permalink = preg_replace('/(http(s?)\:\/\/){2,3}/', 'http://', $permalink);

        // wp_make_link_relative() does not work when using a non-standard port.
        $permalink_parts = parse_url($permalink);
        return $permalink_parts['path'];
    }

}

function register_quant_client() {
    $client = new Client();
    register_activation_hook(__FILE__, [$client, 'activate']);
    register_deactivation_hook(__FILE__, [$client, 'deactivate']);
}
register_quant_client();
