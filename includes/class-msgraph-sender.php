<?php
if (! defined('ABSPATH')) {
    exit;
}

class MSGraph_Sender
{

    public function send_email($return, $atts)
    {

        // Unpack attributes
        $to          = isset($atts['to']) ? $atts['to'] : '';
        $subject     = isset($atts['subject']) ? $atts['subject'] : '';
        $message     = isset($atts['message']) ? $atts['message'] : '';
        $headers     = isset($atts['headers']) ? $atts['headers'] : array();
        $attachments = isset($atts['attachments']) ? $atts['attachments'] : array();

        // Get Settings
        $options = get_option('msgraph_mailer_settings');
        $auth = new MSGraph_Auth(
            isset($options['client_id']) ? $options['client_id'] : '',
            isset($options['client_secret']) ? $options['client_secret'] : '',
            isset($options['tenant_id']) ? $options['tenant_id'] : ''
        );

        $from_email = isset($options['from_email']) ? $options['from_email'] : '';
        $log_id = isset($atts['msgraph_log_id']) ? intval($atts['msgraph_log_id']) : false;

        // Process Headers early to detect resends
        $processed_headers = $this->parse_headers($headers);
        if (! $log_id && isset($processed_headers['msgraph_log_id'])) {
            $log_id = $processed_headers['msgraph_log_id'];
        }

        $log_status_success = $log_id ? 'retried_success' : 'success';
        $log_status_failed  = $log_id ? 'retried_failed' : 'failed';

        if (empty($from_email)) {
            if ($log_id) {
                MSGraph_Logger::update_log_with_result($log_id, $log_status_failed, '"From Email" setting is missing.');
            } else {
                MSGraph_Logger::log($to, $subject, $log_status_failed, '"From Email" setting is missing.', $message, $headers, $attachments);
            }
            return true;
        }

        $access_token = $auth->get_access_token();
        if (! $access_token) {
            if ($log_id) {
                MSGraph_Logger::update_log_with_result($log_id, $log_status_failed, 'Failed to get access token.');
            } else {
                MSGraph_Logger::log($to, $subject, $log_status_failed, 'Failed to get access token.', $message, $headers, $attachments);
            }
            return true;
        }

        // Recipients
        $to_recipients = $this->format_recipients($to);
        $cc_recipients = isset($processed_headers['cc']) ? $this->format_recipients($processed_headers['cc']) : array();
        $bcc_recipients = isset($processed_headers['bcc']) ? $this->format_recipients($processed_headers['bcc']) : array();

        // Content Type
        $content_type = isset($processed_headers['content-type']) ? $processed_headers['content-type'] : 'text/plain';
        $is_html = stripos($content_type, 'html') !== false;
        $body_type = $is_html ? 'HTML' : 'Text';

        // Prepare Attachments
        $graph_attachments = array();
        if (! empty($attachments)) {
            if (! is_array($attachments)) {
                $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
            }
            foreach ($attachments as $file_path) {
                if (file_exists($file_path)) {
                    $file_size = filesize($file_path);
                    $max_size = 10 * 1024 * 1024; // 10MB limit for production safety

                    if ($file_size > $max_size) {
                        $error_msg = sprintf('Attachment "%s" exceeds 10MB limit. Skipping send.', basename($file_path));
                        if ($log_id) {
                            MSGraph_Logger::update_log_with_result($log_id, $log_status_failed, $error_msg);
                        } else {
                            MSGraph_Logger::log($to, $subject, $log_status_failed, $error_msg, $message, $headers, $attachments);
                        }
                        return true;
                    }

                    $content = file_get_contents($file_path);
                    $graph_attachments[] = array(
                        '@odata.type' => '#microsoft.graph.fileAttachment',
                        'name'        => basename($file_path),
                        'contentBytes' => base64_encode($content),
                    );
                }
            }
        }

        // API Payload
        $payload = array(
            'message' => array(
                'subject' => $subject,
                'body' => array(
                    'contentType' => $body_type,
                    'content'     => $message,
                ),
                'toRecipients' => $to_recipients,
            ),
            'saveToSentItems' => 'false',
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

        // Send
        $endpoint = "https://graph.microsoft.com/v1.0/users/" . urlencode($from_email) . "/sendMail";

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => json_encode($payload),
            'method'  => 'POST',
            'timeout' => 30,
        );

        $response = wp_remote_request($endpoint, $args);


        if (is_wp_error($response)) {
            if ($log_id) {
                MSGraph_Logger::update_log_with_result($log_id, $log_status_failed, $response->get_error_message());
            } else {
                MSGraph_Logger::log($to, $subject, $log_status_failed, $response->get_error_message(), $message, $headers, $attachments);
            }
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                if ($log_id) {
                    MSGraph_Logger::update_log_with_result($log_id, $log_status_success, '');
                } else {
                    MSGraph_Logger::log($to, $subject, $log_status_success, '', $message, $headers, $attachments);
                }
            } else {
                $body = wp_remote_retrieve_body($response);
                if ($log_id) {
                    MSGraph_Logger::update_log_with_result($log_id, $log_status_failed, "API Error $code: $body");
                } else {
                    MSGraph_Logger::log($to, $subject, $log_status_failed, "API Error $code: $body", $message, $headers, $attachments);
                }
            }
        }

        return true; // Always return true to intercept.
    }

    private function format_recipients($to)
    {
        $recipient_array = array();

        if (! is_array($to)) {
            $to = explode(',', $to);
        }

        foreach ($to as $recipient) {
            $recipient = trim($recipient);
            if (empty($recipient)) continue;

            if (preg_match('/(.*)<(.+)>/', $recipient, $matches)) {
                $name = trim($matches[1]);
                $email = trim($matches[2]);
            } else {
                $name = '';
                $email = $recipient;
            }

            $recipient_array[] = array(
                'emailAddress' => array(
                    'address' => $email,
                    'name'    => $name,
                ),
            );
        }

        return $recipient_array;
    }

    private function parse_headers($headers)
    {
        $processed = array();

        if (empty($headers)) return $processed;

        if (! is_array($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) < 2) continue;

            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($key === 'content-type') {
                $processed['content-type'] = $value;
            } elseif ($key === 'cc') {
                $processed['cc'] = $value;
            } elseif ($key === 'bcc') {
                $processed['bcc'] = $value;
            } elseif ($key === 'x-msgraph-log-id') {
                $processed['msgraph_log_id'] = intval($value);
            }
        }

        return $processed;
    }
}
