<?php
if (! defined('ABSPATH')) {
    exit;
}

class MSGraph_Sender
{

    /**
     * Microsoft Graph rejects a sendMail payload above roughly 4 MB, and
     * anything over ~3 MB per attachment is supposed to go through an upload
     * session. Base64 inflates content by about a third, so both limits are
     * checked against the encoded size.
     */
    const MAX_ATTACHMENT_BYTES = 3145728;   // 3 MB per file, encoded.
    const MAX_TOTAL_BYTES      = 4194304;   // 4 MB per message, encoded.

    /**
     * Transient statuses worth one short retry.
     */
    const RETRY_STATUS_CODES = array(429, 500, 502, 503, 504);
    const MAX_RETRIES        = 2;
    const MAX_RETRY_WAIT     = 5;

    /**
     * Intercepts wp_mail() via the pre_wp_mail filter.
     *
     * Returns true when Graph accepted the message and false when it did not,
     * so callers that check wp_mail()'s return value see the real outcome.
     *
     * @param null|bool $return Short-circuit value.
     * @param array     $atts   wp_mail() arguments.
     * @return bool
     */
    public function send_email($return, $atts)
    {
        $to          = isset($atts['to']) ? $atts['to'] : '';
        $subject     = isset($atts['subject']) ? $atts['subject'] : '';
        $message     = isset($atts['message']) ? $atts['message'] : '';
        $headers     = isset($atts['headers']) ? $atts['headers'] : array();
        $attachments = isset($atts['attachments']) ? $atts['attachments'] : array();

        $auth       = new MSGraph_Auth();
        $from_email = MSGraph_Settings::get('from_email');

        $log_id = isset($atts['msgraph_log_id']) ? intval($atts['msgraph_log_id']) : false;

        // Process headers early so resends are matched to their original row.
        $processed_headers = $this->parse_headers($headers);
        if (! $log_id && isset($processed_headers['msgraph_log_id'])) {
            $log_id = $processed_headers['msgraph_log_id'];
        }

        $log_status_success = $log_id ? 'retried_success' : 'success';
        $log_status_failed  = $log_id ? 'retried_failed' : 'failed';

        if (empty($from_email)) {
            return $this->fail($log_id, $log_status_failed, __('"From Email" setting is missing.', 'ms-graph-mailer'), $atts);
        }

        $access_token = $auth->get_access_token();
        if (! $access_token) {
            $auth_error = get_transient('msgraph_last_auth_error');
            $detail = $auth_error ? sprintf(__('Failed to get access token: %s', 'ms-graph-mailer'), $auth_error) : __('Failed to get access token.', 'ms-graph-mailer');
            return $this->fail($log_id, $log_status_failed, $detail, $atts);
        }

        // Recipients
        $to_recipients  = $this->format_recipients($to);
        $cc_recipients  = isset($processed_headers['cc']) ? $this->format_recipients($processed_headers['cc']) : array();
        $bcc_recipients = isset($processed_headers['bcc']) ? $this->format_recipients($processed_headers['bcc']) : array();

        if (empty($to_recipients)) {
            return $this->fail($log_id, $log_status_failed, __('No valid recipient email address.', 'ms-graph-mailer'), $atts);
        }

        // Content type detection.
        $content_type = isset($processed_headers['content-type']) ? $processed_headers['content-type'] : apply_filters('wp_mail_content_type', 'text/plain');

        // Treat the message as HTML when it looks like HTML even if no header said so.
        $is_html = (stripos($content_type, 'html') !== false)
            || (stripos(trim($message), '<!DOCTYPE') === 0)
            || (stripos(trim($message), '<html') === 0);

        // Attachments
        $graph_attachments = $this->build_attachments($attachments);
        if (is_wp_error($graph_attachments)) {
            return $this->fail($log_id, $log_status_failed, $graph_attachments->get_error_message(), $atts);
        }

        // Payload
        $payload = array(
            'message' => array(
                'subject' => (string) $subject,
                'body' => array(
                    'contentType' => $is_html ? 'HTML' : 'Text',
                    'content'     => (string) $message,
                ),
                'toRecipients' => $to_recipients,
            ),
            'saveToSentItems' => (bool) apply_filters('msgraph_mailer_save_to_sent_items', false, $atts),
        );

        if (! empty($cc_recipients)) {
            $payload['message']['ccRecipients'] = $cc_recipients;
        }
        if (! empty($bcc_recipients)) {
            $payload['message']['bccRecipients'] = $bcc_recipients;
        }
        if (! empty($graph_attachments)) {
            $payload['message']['attachments'] = $graph_attachments;
        }

        // Reply-To, honoured from the wp_mail() headers.
        if (! empty($processed_headers['reply-to'])) {
            $reply_to = $this->format_recipients($processed_headers['reply-to']);
            if (! empty($reply_to)) {
                $payload['message']['replyTo'] = $reply_to;
            }
        }

        // A display name may be applied to the configured mailbox. Sending
        // under a different address needs SendAs and is left to the filter.
        $from_name = MSGraph_Settings::get('from_name');
        if ($from_name) {
            $payload['message']['from'] = array(
                'emailAddress' => array(
                    'address' => $from_email,
                    'name'    => $from_name,
                ),
            );
        }

        /**
         * Filter the Graph sendMail payload before it is sent.
         *
         * @param array $payload The request body.
         * @param array $atts    The original wp_mail() arguments.
         */
        $payload = apply_filters('msgraph_mailer_payload', $payload, $atts);

        $endpoint = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($from_email) . '/sendMail';

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
            'method'  => 'POST',
            'timeout' => 30,
        );

        $response = $this->request_with_retry($endpoint, $args);

        if (is_wp_error($response)) {
            return $this->fail($log_id, $log_status_failed, $response->get_error_message(), $atts);
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $this->record($log_id, $log_status_success, '', $atts);
            do_action('msgraph_mailer_sent', $atts, $log_id);
            return true;
        }

        return $this->fail($log_id, $log_status_failed, $this->format_api_error($code, $response), $atts);
    }

    /**
     * POST to Graph, retrying briefly on throttling and transient 5xx.
     */
    private function request_with_retry($endpoint, $args)
    {
        $attempt = 0;

        while (true) {
            $response = wp_remote_request($endpoint, $args);

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);

            if ($attempt >= self::MAX_RETRIES || ! in_array((int) $code, self::RETRY_STATUS_CODES, true)) {
                return $response;
            }

            // Honour Retry-After when it is short enough to wait out inline.
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            if ($retry_after > self::MAX_RETRY_WAIT) {
                return $response;
            }
            if ($retry_after < 1) {
                $retry_after = $attempt + 1;
            }

            sleep($retry_after);
            $attempt++;
        }
    }

    /**
     * Turn a Graph error response into a short, readable log message.
     */
    private function format_api_error($code, $response)
    {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error']['message'])) {
            $detail = $data['error']['message'];
            if (isset($data['error']['code'])) {
                $detail = $data['error']['code'] . ': ' . $detail;
            }
        } else {
            $detail = $body;
        }

        return sprintf('API Error %d: %s', $code, mb_substr((string) $detail, 0, 500));
    }

    /**
     * Build the Graph attachment collection.
     *
     * @return array|WP_Error
     */
    private function build_attachments($attachments)
    {
        if (empty($attachments)) {
            return array();
        }

        if (! is_array($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
        }

        $graph_attachments = array();
        $total = 0;

        foreach ($attachments as $file_path) {
            $file_path = trim((string) $file_path);
            if ('' === $file_path) {
                continue;
            }

            if (! is_readable($file_path) || ! is_file($file_path)) {
                return new WP_Error(
                    'msgraph_attachment_missing',
                    sprintf(__('Attachment "%s" could not be read.', 'ms-graph-mailer'), basename($file_path))
                );
            }

            $content = file_get_contents($file_path);
            if (false === $content) {
                return new WP_Error(
                    'msgraph_attachment_unreadable',
                    sprintf(__('Attachment "%s" could not be read.', 'ms-graph-mailer'), basename($file_path))
                );
            }

            $encoded = base64_encode($content);
            $size    = strlen($encoded);

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                return new WP_Error(
                    'msgraph_attachment_too_large',
                    sprintf(
                        /* translators: 1: file name, 2: size limit. */
                        __('Attachment "%1$s" exceeds the %2$s Microsoft Graph limit for a single attachment.', 'ms-graph-mailer'),
                        basename($file_path),
                        size_format(self::MAX_ATTACHMENT_BYTES)
                    )
                );
            }

            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                return new WP_Error(
                    'msgraph_message_too_large',
                    sprintf(
                        /* translators: %s: size limit. */
                        __('Total attachment size exceeds the %s Microsoft Graph message limit.', 'ms-graph-mailer'),
                        size_format(self::MAX_TOTAL_BYTES)
                    )
                );
            }

            $graph_attachments[] = array(
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => basename($file_path),
                'contentType'  => $this->guess_mime_type($file_path),
                'contentBytes' => $encoded,
            );
        }

        return $graph_attachments;
    }

    private function guess_mime_type($file_path)
    {
        $type = wp_check_filetype(basename($file_path));
        return ! empty($type['type']) ? $type['type'] : 'application/octet-stream';
    }

    /**
     * Record a failure, notify listeners, and produce wp_mail()'s return value.
     *
     * @return bool
     */
    private function fail($log_id, $status, $error, $atts)
    {
        $this->record($log_id, $status, $error, $atts);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MS Graph Mailer: ' . $error);
        }

        // Core fires this from wp_mail(); pre_wp_mail short-circuits before it
        // ever runs, so the plugin has to fire it itself.
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $error, $atts));

        /**
         * Whether a failed send should make wp_mail() return false.
         *
         * Returning true here is the correct behaviour, but the filter allows
         * restoring the pre-2.2 "always report success" behaviour for sites
         * with callers that cannot cope with a false return.
         *
         * @param bool  $report True to return false from wp_mail() on failure.
         * @param array $atts   The wp_mail() arguments.
         */
        return ! apply_filters('msgraph_mailer_report_failures', true, $atts);
    }

    /**
     * Record the outcome of a send.
     *
     * New sends create a row; resends update the row they originated from.
     * The message body is only persisted when the "Store Email Content"
     * setting is enabled.
     */
    private function record($log_id, $status, $error, $atts)
    {
        if ($log_id) {
            MSGraph_Logger::update_log_with_result($log_id, $status, $error);
            return $log_id;
        }

        $store_body = (int) MSGraph_Settings::get('store_body', 1);

        return MSGraph_Logger::log(
            isset($atts['to']) ? $atts['to'] : '',
            isset($atts['subject']) ? $atts['subject'] : '',
            $status,
            $error,
            $store_body && isset($atts['message']) ? $atts['message'] : '',
            $store_body && isset($atts['headers']) ? $atts['headers'] : array(),
            $store_body && isset($atts['attachments']) ? $atts['attachments'] : array()
        );
    }

    /**
     * Convert a wp_mail() recipient list into Graph recipient objects.
     *
     * Invalid addresses are dropped: one malformed entry would otherwise make
     * Graph reject the whole message.
     */
    private function format_recipients($to)
    {
        $recipient_array = array();

        if (! is_array($to)) {
            $to = self::split_address_list((string) $to);
        }

        foreach ($to as $recipient) {
            $recipient = trim((string) $recipient);
            if ('' === $recipient) {
                continue;
            }

            $name = '';
            if (preg_match('/^(.*)<([^>]+)>$/', $recipient, $matches)) {
                $name  = trim($matches[1], " \t\n\r\0\x0B\"'");
                $email = trim($matches[2]);
            } else {
                $email = $recipient;
            }

            if (! is_email($email)) {
                continue;
            }

            $address = array('address' => $email);
            if ('' !== $name) {
                $address['name'] = $name;
            }

            $recipient_array[] = array('emailAddress' => $address);
        }

        return $recipient_array;
    }

    /**
     * Split a comma-separated address list.
     *
     * A plain explode(',') breaks on the comma inside a quoted display name
     * such as '"Doe, Jane" <jane@example.com>', which loses the recipient, so
     * commas inside quotes or angle brackets are ignored.
     *
     * @return string[]
     */
    private static function split_address_list($list)
    {
        $addresses = array();
        $current   = '';
        $in_quotes = false;
        $in_angle  = false;
        $length    = strlen($list);

        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];

            if ('\\' === $char && $in_quotes && $i + 1 < $length) {
                $current .= $char . $list[++$i];
                continue;
            }

            if ('"' === $char) {
                $in_quotes = ! $in_quotes;
            } elseif (! $in_quotes && '<' === $char) {
                $in_angle = true;
            } elseif (! $in_quotes && '>' === $char) {
                $in_angle = false;
            } elseif (',' === $char && ! $in_quotes && ! $in_angle) {
                $addresses[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $addresses[] = $current;

        return $addresses;
    }

    private function parse_headers($headers)
    {
        $processed = array();

        if (empty($headers)) {
            return $processed;
        }

        if (! is_array($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        foreach ($headers as $header) {
            $parts = explode(':', (string) $header, 2);
            if (count($parts) < 2) {
                continue;
            }

            $key   = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            switch ($key) {
                case 'content-type':
                case 'cc':
                case 'bcc':
                case 'reply-to':
                    // Repeated Cc/Bcc/Reply-To headers are additive.
                    $processed[$key] = isset($processed[$key]) ? $processed[$key] . ',' . $value : $value;
                    break;
                case 'x-msgraph-log-id':
                    $processed['msgraph_log_id'] = intval($value);
                    break;
            }
        }

        // Content-Type is single-valued; keep the first one seen.
        if (isset($processed['content-type']) && false !== strpos($processed['content-type'], ',')) {
            $processed['content-type'] = strtok($processed['content-type'], ',');
        }

        return $processed;
    }
}
