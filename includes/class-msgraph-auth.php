<?php
if (! defined('ABSPATH')) {
    exit;
}

class MSGraph_Auth
{

    private $client_id;
    private $client_secret;
    private $tenant_id;
    private $token_option_name = 'msgraph_tokens';

    /**
     * Static variable to cache the token for the duration of the request.
     * Optimizes bulk sending by reducing get_option() calls.
     */
    private static $request_token = null;

    public function __construct($client_id, $client_secret, $tenant_id)
    {
        $this->client_id     = $client_id;
        $this->client_secret = $client_secret;
        $this->tenant_id     = $tenant_id;
    }

    /**
     * Get access token.
     */
    public function get_access_token()
    {
        // 1. Check current request cache
        if (self::$request_token !== null) {
            return self::$request_token;
        }

        // 2. Check cached token in DB
        $tokens = get_option($this->token_option_name);

        if (is_array($tokens) && ! empty($tokens['access_token']) && isset($tokens['expires_at']) && time() + 60 < $tokens['expires_at']) {
            self::$request_token = $tokens['access_token'];
            return self::$request_token;
        }

        // 3. Request new token from Microsoft
        return $this->request_new_token();
    }

    /**
     * Request a new token from Microsoft.
     */
    private function request_new_token()
    {
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->tenant_id)) {
            return false;
        }

        // Clear previous error
        delete_transient('msgraph_last_auth_error');

        $url = "https://login.microsoftonline.com/{$this->tenant_id}/oauth2/v2.0/token";
        $body = array(
            'client_id'     => $this->client_id,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
            'client_secret' => $this->client_secret,
        );

        $response = wp_remote_post($url, array(
            'body'    => $body,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('MS Graph Auth Error: ' . $err);
            set_transient('msgraph_last_auth_error', 'WP Error: ' . $err, 300);
            return false;
        }

        $body_content = wp_remote_retrieve_body($response);
        $data = json_decode($body_content, true);

        if (isset($data['error'])) {
            $err_desc = isset($data['error_description']) ? $data['error_description'] : 'No description';
            $full_err = $data['error'] . ': ' . $err_desc;
            error_log('MS Graph Auth Error: ' . $full_err);
            set_transient('msgraph_last_auth_error', $full_err, 300);
            return false;
        }

        if (isset($data['access_token'])) {
            $this->save_tokens($data);
            self::$request_token = $data['access_token'];
            return $data['access_token'];
        }

        set_transient('msgraph_last_auth_error', 'Unknown Error. Response Body: ' . substr($body_content, 0, 200), 300);
        return false;
    }

    private function save_tokens($token_data)
    {
        if (isset($token_data['expires_in'])) {
            $token_data['expires_at'] = time() + intval($token_data['expires_in']);
        }
        update_option($this->token_option_name, $token_data);
    }
}
