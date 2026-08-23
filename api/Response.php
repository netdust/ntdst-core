<?php

declare(strict_types=1);

/**
 * NTDST Response - Fast template rendering
 * JSON, HTML, or file download output with WordPress template hierarchy integration
 */

defined('ABSPATH') || exit;

class NTDST_Response
{
    protected array $data = [];
    protected ?string $error = null;
    protected int $status = 200;
    protected ?string $template = null;

    /**
     * MIME type mappings
     */
    protected static array $mimeTypes = [
        // Documents
        'pdf' => 'application/pdf',
        'csv' => 'text/csv; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain; charset=utf-8',

        // Calendar/Contact
        'ics' => 'text/calendar; charset=utf-8',
        'vcf' => 'text/vcard; charset=utf-8',

        // Images
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',

        // Archives
        'zip' => 'application/zip',
        'gz' => 'application/gzip',

        // Office
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Reset state for reuse
     */
    public function reset(): self
    {
        $this->data = [];
        $this->error = null;
        $this->status = 200;
        $this->template = null;
        return $this;
    }

    /**
     * Set data
     */
    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Set multiple data at once
     */
    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Set error
     */
    public function error(string $message, int $status = 400): self
    {
        $this->error = $message;
        $this->status = $status;
        return $this;
    }

    /**
     * Refuse the request as Not Found (HTTP 404), with no body of its own.
     *
     * A route callback returns this to say "no": NTDST_Pages honours the 404
     * status by leaving WordPress's not-found state intact, so WordPress's own
     * 404 template renders. Reads cleaner at the call site than error('', 404)
     * and sets no error message — this is a refusal that defers to WordPress's
     * 404 page, not an error body to render.
     */
    public function notFound(): self
    {
        $this->status = 404;
        return $this;
    }

    /**
     * Set template for deferred rendering
     */
    public function template(string $template): self
    {
        $this->template = $template;
        return $this;
    }

    /**
     * Get stored template name
     */
    public function getTemplate(): ?string
    {
        return $this->template;
    }

    /**
     * The HTTP status this response carries. Read by NTDST_Pages to honour a
     * route callback's decision: a >=400 status refuses (leave WordPress's
     * not-found state intact), a 2xx succeeds (clear it and render).
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    // =========================================================================
    // OUTPUT METHODS
    // =========================================================================

    /**
     * Return JSON response.
     *
     * If the payload fails to serialize (non-UTF-8 strings, circular refs),
     * we fall back to a structured error body rather than emitting a blank
     * response that clients would mistake for a network failure.
     */
    public function json(): never
    {
        http_response_code($this->status);
        header('Content-Type: application/json');

        $body = json_encode($this->jsonPayload());
        if ($body === false) {
            $body = json_encode([
                'success' => false,
                'error' => 'serialization_failed: ' . json_last_error_msg(),
            ]);
        }

        echo $body;
        exit;
    }

    protected function jsonPayload(): array
    {
        return $this->error
            ? ['success' => false, 'error' => $this->error]
            : ['success' => true, 'data' => $this->data];
    }

    /**
     * Redirect to URL
     *
     * Uses wp_safe_redirect() for internal URLs.
     * If error is set, appends ?error= query param to the URL.
     *
     * @example ntdst_response()->redirect(home_url('/dashboard'));
     * @example ntdst_response()->error('Invalid token.')->redirect(home_url('/login'));
     */
    public function redirect(string $url): never
    {
        if ($this->error) {
            $url = add_query_arg('error', $this->error, $url);
        }

        wp_safe_redirect($url, 302);
        exit;
    }

    /**
     * Render HTML template.
     *
     * Commits its OWN HTTP status before emitting, exactly as json() does
     * (http_response_code at the top of json()). This matters because render()
     * exits and never returns: a route callback that renders can never hand a
     * Response back to NTDST_Pages' deferred commitOk(), so render() must own
     * its status here. A normal render clears WordPress's pre-set 404 for an
     * unmatched URL and sends 200; an error render (error set, or template
     * not found) routes through renderError(), which commits its own >=400
     * status instead — so `error(...)->render()` / `notFound()->render()`
     * still yield the non-200 the caller asked for.
     */
    public function render(string $template, array $data = []): never
    {
        if ($this->error) {
            $this->renderError();
        }

        $file = NTDST_Template_Loader::locate($template);

        if (!$file) {
            $this->error("Template not found: {$template}", 404)->renderError();
        }

        $this->commitRenderStatus();

        $data = array_merge($this->data, $data);
        extract($data, EXTR_SKIP);

        include $file;
        exit;
    }

    /**
     * Commit the render status: clear the 404 WordPress pre-set for an
     * unmatched URL and send $this->status (200 for a normal render). Guarded,
     * so it is a safe no-op when nothing set 404. Mirrors NTDST_Pages::commitOk()'s
     * intent for the render-and-exit path; protected so tests can seam it
     * without reaching the exit in render().
     */
    protected function commitRenderStatus(): void
    {
        global $wp_query;
        if ($wp_query && $wp_query->is_404()) {
            $wp_query->is_404 = false;
        }
        status_header($this->status);
    }

    /**
     * Return HTML as string
     */
    public function html(string $template, array $data = []): string
    {
        if ($this->error) {
            return $this->getErrorHtml();
        }

        $file = NTDST_Template_Loader::locate($template);

        if (!$file) {
            return $this->error("Template not found: {$template}", 404)->getErrorHtml();
        }

        $data = array_merge($this->data, $data);
        extract($data, EXTR_SKIP);

        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Send file as download (attachment)
     *
     * @param string $content File content
     * @param string $filename Download filename
     * @param string|null $contentType MIME type (auto-detected if null)
     *
     * @example ntdst_response()->download($pdf, 'invoice.pdf');
     * @example ntdst_response()->download($ical, 'calendar.ics');
     */
    public function download(string $content, string $filename, ?string $contentType = null): never
    {
        $this->sendFile($content, $filename, $contentType, 'attachment');
    }

    /**
     * Send file inline (display in browser)
     *
     * @param string $content File content
     * @param string $filename Filename for content-type detection
     * @param string|null $contentType MIME type (auto-detected if null)
     *
     * @example ntdst_response()->inline($pdf, 'invoice.pdf');
     */
    public function inline(string $content, string $filename, ?string $contentType = null): never
    {
        $this->sendFile($content, $filename, $contentType, 'inline');
    }

    /**
     * Send file response.
     */
    protected function sendFile(
        string $content,
        string $filename,
        ?string $contentType,
        string $disposition,
    ): never {
        nocache_headers();
        foreach ($this->fileHeaders($content, $filename, $contentType, $disposition) as $header) {
            header($header);
        }

        echo $content;
        exit;
    }

    /**
     * The header policy for a file response, WITHOUT the body.
     *
     * `download()` and `inline()` take the content and echo it, which is right
     * for a vCard or an invoice and impossible for a large archive. A caller
     * that streams — daan's press kit sends a ZIP of a few hundred megabytes
     * chunked from a handle, and can never hold it whole — borrows the policy
     * here and emits its own bytes. Core does not learn to stream; the caller
     * does not re-derive the headers.
     *
     * That re-derivation is the thing this prevents, and it has happened:
     * PressKitService arrived independently at Content-Type, Content-Length and
     * BOTH filename forms, and missed `X-Content-Type-Options: nosniff`.
     * Correct in three headers, wrong in the fourth. That is what hand-rolling
     * a policy looks like every time — right until the one line nobody
     * remembers.
     *
     * Filename is sanitized to strip CRLF (header injection) and double
     * quotes (Content-Disposition value boundary), and reduced to its
     * basename so a path can never reach the header. Both `filename=`
     * (ASCII fallback) and `filename*=UTF-8''…` per RFC 5987 are sent, so
     * non-ASCII names (Dutch accents etc.) render correctly across browsers.
     *
     * `X-Content-Type-Options: nosniff` is always sent: a body whose bytes
     * look like HTML/SVG (e.g. a user-uploaded "proof", or an asset inside a
     * press kit) must never be sniffed into executing markup in the site
     * origin.
     *
     * @param int    $length      Byte length of the body the caller will emit.
     * @param string $filename    Download filename; sanitized here.
     * @param string $disposition `attachment` or `inline`.
     *
     * @return list<string>
     */
    public static function downloadHeaders(
        int $length,
        string $filename,
        ?string $contentType = null,
        string $disposition = 'attachment',
    ): array {
        $contentType ??= self::getMimeType($filename);

        // basename strips paths; the regex strips header-injection chars
        // and quotes that would break the Content-Disposition value.
        $safe = preg_replace('/[\r\n"]/', '', basename($filename)) ?? '';
        // ASCII fallback for `filename=`
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $safe) ?? $safe;
        $utf8 = "filename*=UTF-8''" . rawurlencode($safe);

        return [
            'Content-Type: ' . $contentType,
            'Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; ' . $utf8,
            'Content-Length: ' . $length,
            'X-Content-Type-Options: nosniff',
        ];
    }

    /**
     * Build the headers for a file response whose body is in hand.
     *
     * Kept as the in-memory path's entry point; the policy itself lives in
     * downloadHeaders(). The content was only ever used to measure itself.
     *
     * @return list<string>
     */
    protected function fileHeaders(
        string $content,
        string $filename,
        ?string $contentType,
        string $disposition,
    ): array {
        return self::downloadHeaders(strlen($content), $filename, $contentType, $disposition);
    }

    /**
     * Get MIME type from filename
     */
    public static function getMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::$mimeTypes[$ext] ?? 'application/octet-stream';
    }

    /**
     * Register additional MIME type
     */
    public static function registerMimeType(string $extension, string $mimeType): void
    {
        self::$mimeTypes[strtolower($extension)] = $mimeType;
    }

    /**
     * Render error page
     */
    protected function renderError(): never
    {
        http_response_code($this->status);

        $error_file = NTDST_Template_Loader::locate('error');

        if ($error_file) {
            $error = $this->error;
            $status = $this->status;
            include $error_file;
        } else {
            echo $this->getErrorHtml();
        }

        exit;
    }

    /**
     * Get error HTML
     */
    protected function getErrorHtml(): string
    {
        return sprintf(
            '<div style="padding:20px;background:#fee;border:1px solid #c33;border-radius:4px;"><strong>Error %d:</strong> %s</div>',
            $this->status,
            esc_html($this->error),
        );
    }
}

// =============================================================================
// GLOBAL HELPERS
// =============================================================================

/**
 * Create response instance
 */
if (!function_exists('ntdst_response')) {
    function ntdst_response(): NTDST_Response
    {
        return new NTDST_Response();
    }
}

/**
 * Read what a route passed to NTDST_Template_Loader::page(), from inside the
 * template WordPress included.
 *
 * This is the template-side half of the stash and it is LIVE — a
 * `template_include`d file runs at the top level of WordPress's template
 * loader, where no caller-supplied variables are in scope, so this helper is
 * the only way it can see the model the route already built.
 *
 * Reads the loader directly. It used to hop through NTDST_Response::pageData(),
 * a forwarder onto exactly this call; S9 removed the hop along with the
 * forwarder.
 *
 * @example $viewModel = ntdst_page_data('viewModel', []);
 */
if (!function_exists('ntdst_page_data')) {
    function ntdst_page_data(?string $key = null, mixed $default = null): mixed
    {
        $data = NTDST_Template_Loader::pageData();

        return $key === null ? $data : ($data[$key] ?? $default);
    }
}

/**
 * Quick redirect
 *
 * @example ntdst_redirect(home_url('/dashboard'));
 */
if (!function_exists('ntdst_redirect')) {
    function ntdst_redirect(string $url, int $status = 302): never
    {
        wp_safe_redirect($url, $status);
        exit;
    }
}

/**
 * Quick file download
 *
 * @example ntdst_download($content, 'file.pdf');
 */
if (!function_exists('ntdst_download')) {
    function ntdst_download(string $content, string $filename, ?string $contentType = null): never
    {
        ntdst_response()->download($content, $filename, $contentType);
    }
}

/**
 * Quick inline file display
 *
 * @example ntdst_inline($pdf, 'document.pdf');
 */
if (!function_exists('ntdst_inline')) {
    function ntdst_inline(string $content, string $filename, ?string $contentType = null): never
    {
        ntdst_response()->inline($content, $filename, $contentType);
    }
}

