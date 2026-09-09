<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central accessor for plugin settings.
 *
 * Credentials may be defined as constants in wp-config.php, which takes
 * precedence over the database and keeps secrets out of the options table
 * and out of the settings screen markup.
 */
class MSGraph_Settings
{

    const OPTION_NAME = 'msgraph_mailer_settings';

    /**
     * Map of setting key => wp-config.php constant that overrides it.
     */
    private static $constants = array(
        'client_id'     => 'MSGRAPH_CLIENT_ID',
        'client_secret' => 'MSGRAPH_CLIENT_SECRET',
        'tenant_id'     => 'MSGRAPH_TENANT_ID',
        'from_email'    => 'MSGRAPH_FROM_EMAIL',
    );

    /**
     * Runtime cache of the stored option.
     */
    private static $cache = null;

    /**
     * All stored settings as an array.
     */
    public static function all()
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION_NAME);
            self::$cache = is_array($stored) ? $stored : array();
        }
        return self::$cache;
    }

    /**
     * Get a single setting, preferring a wp-config.php constant when defined.
     */
    public static function get($key, $default = '')
    {
        if (self::is_constant($key)) {
            return constant(self::$constants[$key]);
        }

        $options = self::all();
        return isset($options[$key]) && '' !== $options[$key] ? $options[$key] : $default;
    }

    /**
     * Whether this setting is locked by a wp-config.php constant.
     */
    public static function is_constant($key)
    {
        return isset(self::$constants[$key]) && defined(self::$constants[$key]) && '' !== constant(self::$constants[$key]);
    }

    /**
     * Name of the constant backing a setting, for display purposes.
     */
    public static function constant_name($key)
    {
        return isset(self::$constants[$key]) ? self::$constants[$key] : '';
    }

    /**
     * Whether the plugin has everything it needs to authenticate.
     */
    public static function is_configured()
    {
        return self::get('client_id') && self::get('client_secret') && self::get('tenant_id') && self::get('from_email');
    }

    /**
     * Drop the runtime cache after the option is written.
     */
    public static function flush_cache()
    {
        self::$cache = null;
    }
}
