<?php

declare(strict_types=1);

/**
 * NTDST Response — what WordPress has no word for.
 *
 * Three things, and nothing else: the file-download HEADER POLICY (so a caller
 * that streams its own bytes does not re-derive four headers by hand), a
 * redirect that carries an error message across it, and html() — a named
 * template rendered into a string.
 *
 * What WordPress says itself, and this class therefore does not (5.0.0, FR-11):
 * the JSON envelope is wp_send_json_success() / wp_send_json_error() or a REST
 * route through ntdst_rest(); the MIME table is wp_get_mime_types() and
 * wp_check_filetype() (INV-5); a page renders because a route callback returned
 * a PATH and WordPress included it (INV-6), never because this class echoed and
 * exited.
 */

defined('ABSPATH') || exit;

class NTDST_Response
{
    protected array $data = [];
    protected ?string $error = null;
    protected int $status = 200;
    protected ?string $template = null;

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
     * A route callback returns this to say "no", and WordPress renders its own
     * 404 template. Reads cleaner at the call site than error('', 404) and sets
     * no error message — this is a refusal that defers to WordPress's 404 page,
     * not an error body to render.
     *
     * It CALLS $wp_query->set_404() rather than recording a flag for somebody
     * else to honour: since 5.0.0 nothing downstream inspects this object, and
     * WP::handle_404() has already run by the time a route answers.
     *
     * T11-I1: set_404() alone left a soft 404 — WP::handle_404() has already
     * queued status_header(200) by the time a route answers, so outside a
     * Pages route the 404 template rendered at HTTP 200. This now writes the
     * same three lines core/Pages.php's own notFound() writes. The two are
     * one contract in two places; longer-term one should call the other, but
     * that refactor is out of scope here.
     */
    public function notFound(): self
    {
        $this->status = 404;

        // WordPress's own three lines, because the FLAG alone is not a
        // refusal: WP::handle_404() has already decided the request was fine
        // by the time a route answers, so a 404 nothing tells WordPress about
        // leaves a 200 on the wire (INV-6).
        global $wp_query;

        if (is_object($wp_query) && method_exists($wp_query, 'set_404')) {
            $wp_query->set_404();
        }

        status_header(404);
        nocache_headers();

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
     * The HTTP status this response carries.
     *
     * A reader for the caller that set it — error() and notFound() act on
     * WordPress themselves now, so nothing in core reads this back.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    // =========================================================================
    // OUTPUT METHODS
    // =========================================================================

    /**
     * Redirect to URL, with the status the caller means.
     *
     * Goes through wp_safe_redirect(), so an EXTERNAL host is allowed only
     * when WordPress's own `allowed_redirect_hosts` filter says so — that
     * filter is the sanctioned way to open one, not a raw wp_redirect().
     * If error is set, appends ?error= query param to the URL.
     *
     * Fails SAFE on a status that is not a redirect: it says so through
     * _doing_it_wrong() and hops with 302 anyway, because a caller's typo
     * must not strand the visitor on a blank page.
     *
     * @param int $status Any 3xx (300-308). Anything else warns and becomes 302.
     *
     * @example ntdst_response()->redirect(home_url('/dashboard'));
     * @example ntdst_response()->redirect(home_url('/new-home'), 301);
     * @example ntdst_response()->error('Invalid token.')->redirect(home_url('/login'));
     */
    public function redirect(string $url, int $status = 302): never
    {
        if ($status < 300 || $status > 308) {
            _doing_it_wrong(
                __METHOD__,
                sprintf('%d is not a redirect status. Pass a 3xx (300-308); falling back to 302.', $status),
                '5.0.0'
            );

            $status = 302;
        }

        if ($this->error) {
            $url = add_query_arg('error', $this->error, $url);
        }

        wp_safe_redirect($url, $status);
        exit;
    }

    /**
     * Render a named template into a string.
     *
     * WordPress includes the file — `load_template()` — inside our buffer, so
     * the template runs in WordPress's own template scope with the globals it
     * expects. Core neither includes a template nor unpacks a caller array
     * into one (INV-6): the merged data is load_template()'s third argument,
     * which WordPress puts in scope as `$args`.
     *
     * Fails CLOSED. A name that resolves to nothing returns an empty string and
     * says so in the log; it used to return a red error <div> of core's own
     * markup, echoed into the middle of somebody's page.
     *
     * @param array<string, mixed> $data Merged over with(): the template reads
     *                                   it back as `$args['key']`.
     */
    public function html(string $template, array $data = []): string
    {
        $file = NTDST_Template_Loader::locate($template);

        if (!$file) {
            ntdst_log('response')->warning("html(): no template resolved for \"{$template}\"", [
                'template' => $template,
            ]);

            return '';
        }

        ob_start();

        try {
            load_template($file, false, array_merge($this->data, $data));
        } finally {
            // A template that throws must not leave the buffer open: everything
            // the page echoes after it would vanish into a buffer nobody closes.
            $html = (string) ob_get_clean();
        }

        return $html;
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
     *
     * A download is always 200 (R-S7): `$this->status` is not sent here, so a
     * status set earlier in the chain has no effect on the bytes that leave.
     * A response that has to refuse refuses before it reaches this method.
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
     * `X-Content-Type-Options: nosniff` is always sent, and this method sends
     * it: a body whose bytes look like HTML/SVG (e.g. a user-uploaded "proof",
     * or an asset inside a press kit) must never be sniffed into executing
     * markup in the site origin. It goes out through WordPress's own
     * send_nosniff_header() instead of riding in the returned list, so a
     * borrowing caller cannot drop it by emitting only some of these lines.
     * The list below is therefore the three headers that describe THIS body.
     *
     * @param int    $length      Byte length of the body the caller will emit.
     * @param string $filename    Download filename; sanitized here.
     * @param string $disposition `attachment` or `inline`.
     *
     * @return list<string> Content-Type, Content-Disposition, Content-Length —
     *                       nosniff is already sent.
     */
    public static function downloadHeaders(
        int $length,
        string $filename,
        ?string $contentType = null,
        string $disposition = 'attachment',
    ): array {
        // basename strips paths; the regex strips header-injection chars
        // and quotes that would break the Content-Disposition value.
        $safe = preg_replace('/[\r\n"]/', '', basename($filename)) ?? '';

        if ($contentType === null) {
            // WordPress's table, read WordPress's way — extensions are
            // alternation keys ('jpg|jpeg|jpe'), which only wp_check_filetype()
            // knows how to match (INV-5). The four types that table lacks are
            // added by mimeTypes() on the `mime_types` filter, which
            // wp_get_mime_types() applies for us.
            $contentType = wp_check_filetype($safe, wp_get_mime_types())['type']
                ?: 'application/octet-stream';

            // The one thing WordPress's table does NOT carry, and a download
            // needs: a text/* body saved or displayed without a charset is read
            // as latin-1 by some browsers, which mangles every accent in a
            // vCard or an .ics. This is header POLICY, not a second MIME table
            // — it adds no type and overrides none.
            if (str_starts_with($contentType, 'text/')) {
                $contentType .= '; charset=utf-8';
            }
        }

        // WordPress's own emitter, so the header a hand-rolled block forgets is
        // sent even by a caller that only borrows the lines below.
        send_nosniff_header();
        // ASCII fallback for `filename=`
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $safe) ?? $safe;
        $utf8 = "filename*=UTF-8''" . rawurlencode($safe);

        return [
            'Content-Type: ' . $contentType,
            'Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; ' . $utf8,
            'Content-Length: ' . $length,
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
     * The four MIME types WordPress's table lacks.
     *
     * Mounted on `mime_types`, so `wp_get_mime_types()` answers for them and
     * core keeps no table of its own (INV-5). It ADDS and never overrides: a
     * key WordPress or the site already spells wins, because a filter that
     * overwrites is the second table coming back through the front door.
     *
     * @param array<string, string> $types
     *
     * @return array<string, string>
     */
    public static function mimeTypes(array $types): array
    {
        return $types + [
            'json' => 'application/json',
            'xml' => 'application/xml',
            'vcf' => 'text/vcard',
            'svg' => 'image/svg+xml',
        ];
    }

    /**
     * ...and none of them on the upload allow-list.
     *
     * `mime_types` is also the BASE of get_allowed_mime_types(), so the filter
     * above would quietly make every uploader an SVG uploader — and an SVG is
     * markup that executes in the site origin. WordPress strips html/js/css
     * there for exactly this reason and does not know about ours, so core takes
     * its own four back off the list. A site that wants SVG uploads still says
     * so itself, with its own `upload_mimes` filter.
     *
     * Reads the four off mimeTypes() rather than listing them again: one
     * declaration, two readers.
     *
     * @param array<string, string> $mimes
     *
     * @return array<string, string>
     */
    public static function uploadMimes(array $mimes): array
    {
        return array_diff_key($mimes, self::mimeTypes([]));
    }

    /**
     * Mount the two filters, once, at load time.
     *
     * A named method rather than two bare add_filter() calls at the foot of the
     * file, for the reason NTDST_Template_Loader::init() is one: it is the
     * single place that says which WordPress tables core has an opinion about,
     * and it can be re-run.
     */
    public static function init(): void
    {
        add_filter('mime_types', [self::class, 'mimeTypes']);
        add_filter('upload_mimes', [self::class, 'uploadMimes']);
    }

}

NTDST_Response::init();

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

