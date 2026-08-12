<?php

declare(strict_types=1);

/**
 * NTDST Mailer - Template-based Mail System
 *
 * Features:
 * - HTML email templates with variables
 * - Plain text fallback
 * - Queue support (via WP Cron or Action Scheduler)
 * - Event-driven notifications
 * - Error handling and logging
 * - Attachments support
 *
 * Architecture:
 * - Uses Response.php for template loading (DRY principle)
 * - Email-specific wrapping and layout handled by this class
 * - Template paths: {theme}/emails, {parent}/emails, {plugin}/templates/emails
 *
 * Usage:
 *   // Simple email
 *   ntdst_mail()
 *       ->to('user@example.com')
 *       ->subject('Welcome!')
 *       ->template('welcome', ['name' => 'John'])
 *       ->send();
 *
 *   // Queue for later
 *   ntdst_mail()
 *       ->to('user@example.com')
 *       ->template('newsletter', $data)
 *       ->queue();
 *
 *   // Notification events
 *   ntdst_notify('user.registered', $user_data);
 */

defined('ABSPATH') || exit;

class NTDST_Mailer
{
    protected array $to = [];
    protected array $cc = [];
    protected array $bcc = [];
    protected string $subject = '';
    protected string $message = '';
    protected string $from_email = '';
    protected string $from_name = '';
    protected array $headers = [];
    protected array $attachments = [];
    protected array $template_data = [];
    protected string $template = '';
    protected bool $is_html = true;

    public function __construct()
    {
        // Set defaults from WordPress settings.
        // Cast defensively: on a not-yet-installed site (e.g. `wp core install`
        // bootstrapping an empty DB in CI) these options do not exist yet and
        // get_option() returns false, which would fatal on the typed properties.
        $this->from_email = (string) get_option('admin_email');
        $this->from_name = (string) get_option('blogname');
    }

    /**
     * Set recipient(s)
     */
    public function to(string|array $email): self
    {
        $this->to = is_array($email) ? $email : [$email];
        return $this;
    }

    /**
     * Set CC recipients
     */
    public function cc(string|array $email): self
    {
        $this->cc = is_array($email) ? $email : [$email];
        return $this;
    }

    /**
     * Set BCC recipients
     */
    public function bcc(string|array $email): self
    {
        $this->bcc = is_array($email) ? $email : [$email];
        return $this;
    }

    /**
     * Set subject.
     *
     * CRLF stripped defensively — wp_mail's protection varies by WP version,
     * and a CRLF in the subject can be used to inject arbitrary headers.
     */
    public function subject(string $subject): self
    {
        $this->subject = preg_replace('/[\r\n]/', ' ', $subject) ?? '';
        return $this;
    }

    /**
     * Set from address.
     *
     * Email is validated through sanitize_email (returns '' if invalid).
     * Name has CRLF stripped to prevent injection into the From header.
     */
    public function from(string $email, string $name = ''): self
    {
        $this->from_email = sanitize_email($email);
        $cleanName = preg_replace('/[\r\n]/', '', $name) ?? '';
        $this->from_name = $cleanName !== '' ? $cleanName : $this->from_email;
        return $this;
    }

    /**
     * Set message (HTML or plain text)
     */
    public function message(string $message, bool $is_html = true): self
    {
        $this->message = $message;
        $this->is_html = $is_html;
        return $this;
    }

    /**
     * Use email template
     */
    public function template(string $template, array $data = []): self
    {
        $this->template = $template;
        $this->template_data = $data;
        return $this;
    }

    /**
     * Add attachment.
     *
     * Only files inside the WordPress uploads directory (or other
     * allow-listed bases via `ntdst_mail_attachment_bases`) are accepted.
     * Without this check, a caller passing user input could attach
     * arbitrary local files (wp-config.php, /etc/passwd, etc.).
     */
    public function attach(string $path): self
    {
        if (!file_exists($path)) {
            return $this;
        }

        $real = realpath($path);
        if ($real === false) {
            return $this;
        }

        $defaults = [];
        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir();
            if (!empty($upload['basedir'])) {
                $defaults[] = $upload['basedir'];
            }
        }
        $allowed = apply_filters('ntdst_mail_attachment_bases', $defaults);

        foreach ($allowed as $base) {
            $realBase = realpath($base);
            if ($realBase && str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
                $this->attachments[] = $path;
                return $this;
            }
        }

        if (function_exists('ntdst_log')) {
            ntdst_log('mail')->warning('Refused attachment outside allowed bases: ' . $path);
        }
        return $this;
    }

    /**
     * Add custom header.
     *
     * CRLF and ':' (in name) are stripped to prevent header injection.
     */
    public function header(string $name, string $value): self
    {
        $cleanName = preg_replace('/[\r\n:]/', '', $name) ?? '';
        $cleanValue = preg_replace('/[\r\n]/', '', $value) ?? '';
        if ($cleanName === '') {
            return $this;
        }
        $this->headers[] = "{$cleanName}: {$cleanValue}";
        return $this;
    }

    /**
     * Send email immediately
     */
    public function send(): bool
    {
        try {
            // Load template if specified
            if ($this->template) {
                $this->message = $this->renderTemplate($this->template, $this->template_data);
            } elseif ($this->is_html && $this->message) {
                // Wrap HTML messages in layout if not already wrapped
                if (!str_contains($this->message, '<html')) {
                    $this->message = $this->wrapInLayout($this->message, []);
                }
            }

            // Build headers
            $headers = $this->buildHeaders();

            // Fire before hook
            do_action('ntdst_mail_before_send', $this);

            // Send email
            $result = wp_mail(
                $this->to,
                $this->subject,
                $this->message,
                $headers,
                $this->attachments,
            );

            if ($result) {
                ntdst_log('mail')->info('Email sent', [
                    'to' => implode(', ', $this->to),
                    'subject' => $this->subject,
                    'template' => $this->template ?: 'custom',
                ]);

                // Fire success hook
                do_action('ntdst_mail_sent', $this);
            } else {
                ntdst_log('mail')->error('Email failed to send', [
                    'to' => implode(', ', $this->to),
                    'subject' => $this->subject,
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            ntdst_log('mail')->error('Email exception', [
                'error' => $e->getMessage(),
                'to' => implode(', ', $this->to),
                'subject' => $this->subject,
            ]);

            return false;
        }
    }

    /**
     * Queue email for later delivery
     */
    public function queue(int $delay_seconds = 0): bool
    {
        $args = [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'message' => $this->message,
            'template' => $this->template,
            'template_data' => $this->template_data,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
            'headers' => $this->headers,
            'attachments' => $this->attachments,
            'is_html' => $this->is_html,
        ];

        // Schedule with WP Cron
        $timestamp = time() + $delay_seconds;
        $scheduled = wp_schedule_single_event($timestamp, 'ntdst_send_queued_mail', [$args]);

        if ($scheduled !== false) {
            ntdst_log('mail')->debug('Email queued', [
                'to' => implode(', ', $this->to),
                'subject' => $this->subject,
                'delay' => $delay_seconds,
            ]);
        }

        return $scheduled !== false;
    }

    /**
     * Build email headers
     */
    protected function buildHeaders(): array
    {
        $headers = [];

        // From header
        $headers[] = sprintf('From: %s <%s>', $this->from_name, $this->from_email);

        // Reply-To (use from by default)
        $headers[] = sprintf('Reply-To: %s', $this->from_email);

        // CC
        foreach ($this->cc as $email) {
            $headers[] = "Cc: {$email}";
        }

        // BCC
        foreach ($this->bcc as $email) {
            $headers[] = "Bcc: {$email}";
        }

        // Content type
        if ($this->is_html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        // Custom headers
        $headers = array_merge($headers, $this->headers);

        return $headers;
    }

    /**
     * Render email template using Response.php for loading.
     *
     * Pre-checks that the template file exists before delegating to
     * Response::html(), so we don't depend on Response's error-HTML
     * format to detect missing templates (fragile coupling).
     */
    protected function renderTemplate(string $template, array $data): string
    {
        // Get template paths (allow filtering)
        $template_paths = apply_filters('ntdst_mail_template_paths', [
            get_stylesheet_directory() . '/views/emails',
            get_template_directory() . '/views/emails',
            NTDST_PATH . '/templates/emails',
        ]);

        $templateFile = $template . (str_ends_with($template, '.php') ? '' : '.php');
        $found = false;
        foreach ($template_paths as $path) {
            if (file_exists(rtrim($path, '/') . '/' . $templateFile)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $this->getDefaultTemplate($data);
        }

        // Create Response instance for template rendering
        $response = ntdst_response();
        foreach ($template_paths as $path) {
            $response->addPath($path);
        }

        try {
            $content = $response->withData($data)->html($template);
        } catch (\Throwable $e) {
            if (function_exists('ntdst_log')) {
                ntdst_log('mail')->error('Email template render failed: ' . $e->getMessage());
            }
            return $this->getDefaultTemplate($data);
        }

        // Wrap in email layout if not already wrapped
        if (!str_contains($content, '<html')) {
            $content = $this->wrapInLayout($content, $data);
        }

        return $content;
    }

    /**
     * Wrap content in email layout
     */
    protected function wrapInLayout(string $content, array $data): string
    {
        return ntdst_wrap_email_in_layout($content, $this->subject);
    }

    /**
     * Get default template.
     *
     * `{{key}}` substitutions are esc_html'd so that user-controlled values
     * (names, emails) can't inject HTML into the email body.
     */
    protected function getDefaultTemplate(array $data): string
    {
        $content = $this->message ?: '<p>No content provided.</p>';

        // Replace variables
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $content = str_replace('{{' . $key . '}}', esc_html((string) $value), $content);
            }
        }

        return $this->wrapInLayout($content, $data);
    }

    /**
     * Get email data for debugging
     */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'from' => $this->from_email,
            'from_name' => $this->from_name,
            'template' => $this->template,
            'is_html' => $this->is_html,
        ];
    }
}

/**
 * Process queued email
 */
add_action('ntdst_send_queued_mail', function ($args) {
    $mailer = ntdst_mail();

    // Restore mail properties
    $mailer->to($args['to'])
        ->subject($args['subject'])
        ->from($args['from_email'], $args['from_name']);

    if (!empty($args['cc'])) {
        $mailer->cc($args['cc']);
    }

    if (!empty($args['bcc'])) {
        $mailer->bcc($args['bcc']);
    }

    if ($args['template']) {
        $mailer->template($args['template'], $args['template_data']);
    } else {
        $mailer->message($args['message'], $args['is_html']);
    }

    foreach ($args['attachments'] as $attachment) {
        $mailer->attach($attachment);
    }

    $mailer->send();
});

/**
 * Global helper - get mailer instance
 */
if (!function_exists('ntdst_mail')) {
    function ntdst_mail(): NTDST_Mailer
    {
        return new NTDST_Mailer();
    }
}

/**
 * Quick send helper
 */
if (!function_exists('ntdst_send_mail')) {
    function ntdst_send_mail(string $to, string $subject, string $message, bool $is_html = true): bool
    {
        return ntdst_mail()
            ->to($to)
            ->subject($subject)
            ->message($message, $is_html)
            ->send();
    }
}

/**
 * Send notification based on event
 */
if (!function_exists('ntdst_notify')) {
    function ntdst_notify(string $event, array $data = []): void
    {
        do_action('ntdst_notification', $event, $data);
        do_action('ntdst_notification_' . $event, $data);
    }
}

/**
 * Wrap email content in layout
 *
 * @param string $content Email body content
 * @param string $subject Email subject (for title tag)
 * @return string Wrapped HTML email
 */
if (!function_exists('ntdst_wrap_email_in_layout')) {
    function ntdst_wrap_email_in_layout(string $content, string $subject = ''): string
    {
        // Escape values that come from settings or callers; $content is HTML
        // by contract (templates handle their own escaping).
        $site_name = esc_html(get_option('blogname'));
        $site_url = esc_url(home_url());
        $safe_subject = esc_html($subject);

        // Look for layout template
        $layout_paths = apply_filters('ntdst_email_layout_paths', [
            get_stylesheet_directory() . '/views/emails/layout.php',
            get_template_directory() . '/views/emails/layout.php',
            NTDST_PATH . '/templates/emails/layout.php',
        ]);

        $layout_file = null;
        foreach ($layout_paths as $path) {
            if (file_exists($path)) {
                $layout_file = $path;
                break;
            }
        }

        // If layout file exists, use it
        if ($layout_file) {
            ob_start();
            include $layout_file;
            return ob_get_clean();
        }

        // Fallback to inline layout
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safe_subject}</title>
</head>
<body style="margin:0; padding:0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; border-bottom: 1px solid #e0e0e0;">
                            <h2 style="margin: 0; color: #333333;">{$site_name}</h2>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px;">
                            {$content}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 40px; text-align: center; border-top: 1px solid #e0e0e0; color: #999999; font-size: 12px;">
                            <p style="margin: 0 0 10px;">&copy; {$year} {$site_name}. All rights reserved.</p>
                            <p style="margin: 0;"><a href="{$site_url}" style="color: #666666; text-decoration: none;">Visit our website</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}

/**
 * Global email wrapper (opt-in)
 *
 * Enable with: add_filter('ntdst_wrap_all_emails', '__return_true');
 * Or: update_option('ntdst_wrap_all_emails', true);
 */
add_filter('wp_mail', function ($args) {
    // Check if global wrapping is enabled (default: off)
    $enabled = apply_filters('ntdst_wrap_all_emails', get_option('ntdst_wrap_all_emails', false));
    if (!$enabled) {
        return $args;
    }

    // Only wrap HTML emails
    $is_html = false;
    if (isset($args['headers'])) {
        $headers = is_array($args['headers']) ? $args['headers'] : explode("\n", $args['headers']);
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type: text/html') !== false) {
                $is_html = true;
                break;
            }
        }
    }

    // Wrap if HTML and not already wrapped
    if ($is_html && isset($args['message']) && !str_contains($args['message'], '<html')) {
        $args['message'] = ntdst_wrap_email_in_layout($args['message'], $args['subject'] ?? '');
    }

    return $args;
}, 999);
