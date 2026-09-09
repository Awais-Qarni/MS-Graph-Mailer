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

    /**
     * Set once a token request has failed, so a bulk send does not hammer
     * Microsoft with one doomed token request per message.
     */
    private static $request_failed = false;

    public function __construct($client_id = null, $client_secret = null, $tenant_id = null)
    {
        // Arguments stay supported for back-compat, but the settings accessor
        // is authoritative so wp-config.php constants always win.
        $this->client_id     = null === $client_id ? MSGraph_Settings::get('client_id') : $client_id;
        $this->client_secret = null === $client_secret ? MSGraph_Settings::get('client_secret') : $client_secret;
        $this->tenant_id     = null === $tenant_id ? MSGraph_Settings::get('tenant_id') : $tenant_id;
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

        if (self::$request_failed) {
            return false;
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
            self::$request_failed = true;
            set_transient('msgraph_last_auth_error', __('Client ID, Client Secret and Tenant ID are all required.', 'ms-graph-mailer'), 300);
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
            self::log_error($err);
            set_transient('msgraph_last_auth_error', 'WP Error: ' . $err, 300);
            self::$request_failed = true;
            return false;
        }

        $body_content = wp_remote_retrieve_body($response);
        $data = json_decode($body_content, true);

        if (isset($data['error'])) {
            $err_desc = isset($data['error_description']) ? $data['error_description'] : 'No description';
            $full_err = $data['error'] . ': ' . $err_desc;
            self::log_error($full_err);
            set_transient('msgraph_last_auth_error', $full_err, 300);
            self::$request_failed = true;
            return false;
        }

        if (isset($data['access_token'])) {
            $this->save_tokens($data);
            self::$request_token = $data['access_token'];
            return $data['access_token'];
        }

        self::$request_failed = true;
        set_transient('msgraph_last_auth_error', 'Unknown error. Response body: ' . substr($body_content, 0, 200), 300);
        return false;
    }

    /**
     * Persist the token.
     *
     * Stored with autoload disabled: this is a live bearer token and must not
     * be pulled into alloptions on every front-end request.
     */
    private function save_tokens($token_data)
    {
        $expires_in = isset($token_data['expires_in']) ? intval($token_data['expires_in']) : 3600;

        $stored = array(
            'access_token' => $token_data['access_token'],
            'expires_at'   => time() + $expires_in,
        );

        update_option($this->token_option_name, $stored, false);
    }

    /**
     * Forget the cached token, in memory and in the database.
     */
    public static function clear_token()
    {
        self::$request_token  = null;
        self::$request_failed = false;
        delete_option('msgraph_tokens');
        delete_transient('msgraph_last_auth_error');
    }

    /**
     * Error messages go to the PHP log only when debug logging is on.
     */
    private static function log_error($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MS Graph Mailer auth error: ' . $message);
        }
    }
}
