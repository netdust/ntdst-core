<?php

declare(strict_types=1);

/**
 * NTDST Template Loader — the ONE template path resolver.
 *
 * Moved out of api/Response.php at 5.0.0 (FR-10, INV-6): resolution is its own
 * concern with its own registry, and Response is the wire shape. Loaded before
 * api/Response.php, which calls locate() from page()/html()/error().
 */

defined('ABSPATH') || exit;

final class NTDST_Template_Loader
{
    /** The ONE template path registry. Read live on every locate(). */
    protected static array $custom_paths = [];

    /** Resolved-template cache (shared). Positive hits only — see locate(). */
    protected static array $template_cache = [];

    /** @var array<string, mixed> Data page() hands to the template WordPress includes later. */
    protected static array $page_data = [];

    public static function addPath(string $path): void
    {
        // One live registry: locate() reads $custom_paths on every call, so a
        // path registered after an earlier resolution is seen with no cache
        // reset — the ordering hazard the old resetCachedPaths() papered over.
        self::$custom_paths[] = rtrim($path, '/');
    }

    public static function getCustomPaths(): array
    {
        return self::$custom_paths;
    }

    /**
     * Serve a WordPress-rendered page from a route.
     *
     * Resolves $template through locate() and returns its path so
     * `template_include` includes it and wp_head()/wp_footer() still fire —
     * the one thing render() (echoes and exits) and html() (returns a string)
     * cannot do — while stashing $data for the template to read back with
     * ntdst_page_data(). Null when nothing resolves, so a route can fail closed
     * instead of returning a guessed path.
     *
     * @param array<string, mixed> $data       Carried across to the include.
     * @param list<string>         $extraPaths Per-call priority dirs (a Response's addPath).
     */
    public static function page(string $template, array $data = [], array $extraPaths = []): ?string
    {
        $file = self::locate($template, $extraPaths);

        if ($file === null) {
            return null;
        }

        self::$page_data = $data;

        return $file;
    }

    /**
     * The data page() stashed. A request includes exactly one template_include
     * template, so one stash is the whole mechanism — no keying by path.
     */
    public static function pageData(): array
    {
        return self::$page_data;
    }

    /**
     * Locate a template file across the single registry (+ the theme's
     * templates dirs), optionally preceded by per-call $extraPaths.
     *
     * Defense-in-depth: isInside() ensures the resolved file lives within the
     * base it matched, so a user-influenced name cannot traverse out
     * (`../../../../etc/passwd`). The registry is read LIVE here, which is what
     * eliminates the old seed-once ordering hazard.
     *
     * @param list<string> $extraPaths Searched first, never cached.
     */
    public static function locate(string $template, array $extraPaths = []): ?string
    {
        if (!str_ends_with($template, '.php')) {
            $template .= '.php';
        }

        if (isset(self::$template_cache[$template])) {
            return self::$template_cache[$template];
        }

        // Per-call directories (a Response's addPath) resolve for this call
        // only and never populate the shared cache — a private template must
        // not hijack another caller's lookup of the same name.
        foreach ($extraPaths as $path) {
            $path = rtrim($path, '/');
            $file = $path . '/' . $template;
            if (file_exists($file) && self::isInside($file, $path)) {
                return $file;
            }
        }

        foreach (self::searchPaths() as $path) {
            $file = $path . '/' . $template;
            if (file_exists($file) && self::isInside($file, $path)) {
                self::$template_cache[$template] = $file;
                return $file;
            }
        }

        $located = locate_template([$template]);

        // Cache HITS only. A cached negative poisons the whole request: one
        // early "not found" (e.g. before a path registration) makes the
        // template unresolvable for every later caller — page-dependent
        // breakage that is miserable to diagnose (2026-06-12, questionnaire
        // field rendering).
        if ($located) {
            self::$template_cache[$template] = $located;
            return $located;
        }

        return null;
    }

    /**
     * The live registry, plus the theme's templates directories (lowest
     * priority). Read fresh on every locate() — no snapshot.
     *
     * @return list<string>
     */
    private static function searchPaths(): array
    {
        return array_merge(self::$custom_paths, [
            get_stylesheet_directory() . '/templates',
            get_template_directory() . '/templates',
        ]);
    }

    /**
     * Check that a resolved file lives within an allowed base directory.
     */
    private static function isInside(string $file, string $base): bool
    {
        $realFile = realpath($file);
        $realBase = realpath($base);
        if ($realFile === false || $realBase === false) {
            return false;
        }
        return str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR);
    }

    public static function init(): void
    {
        // WordPress computes the hierarchy; core picks from it. The old
        // `template_include` callback hand-listed single-{type}-{slug},
        // single-{type}, single, archive-{type} and archive — a PARTIAL copy
        // (no singular, no page, no taxonomy, no decoded slug) of a list
        // WordPress already builds and hands over as the filter's THIRD
        // argument. Hence 3 accepted args: with fewer, the callback never sees
        // that list and has to guess names again (INV-5).
        //
        // PRIORITY 5, and it is load-bearing: NTDST_Pages::template() mounts a
        // consumer's own `{$type}_template` handler at WordPress's default 10
        // (core/Pages.php:152), and a handler a theme wrote by hand must be
        // able to override what the registry picked. Core fills the gap first;
        // the consumer decides last. The old callback sat on `template_include`
        // at 99 — after every `{$type}_template` filter — so it overrode those
        // handlers instead.
        // The five names are HOOK types, not template names, and they are
        // written inline rather than as a class constant on purpose: a
        // `private const` list in core is the INV-5 shape, and this is a hook
        // subscription, not a registry. `index` is WordPress's last resort and
        // `singular` covers both halves of it, so the five together answer for
        // every request a template registry can serve.
        foreach (['index', 'singular', 'single', 'page', 'archive'] as $type) {
            add_filter("{$type}_template", [self::class, 'pickFromCandidates'], 5, 3);
        }

        add_filter('theme_file_path', [self::class, 'locateInCustomPaths'], 10, 2);
    }

    /**
     * Answer a `{$type}_template` filter from the registry.
     *
     * $templates is WordPress's own candidate list, ordered most-specific
     * first. Each name goes through locate(), so the registry is searched with
     * the traversal guard and the hit-only cache that every other resolution
     * uses (INV-6) — and the ORDER is WordPress's, never core's. Nothing
     * registered answers for any candidate: hand WordPress's own choice back
     * untouched.
     *
     * @param string       $template  What WordPress resolved from the theme.
     * @param string       $type      The query type WordPress is asking about.
     * @param list<string> $templates WordPress's ordered candidate list.
     */
    public static function pickFromCandidates(string $template, string $type, array $templates): string
    {
        foreach ($templates as $candidate) {
            $file = self::locate($candidate);

            if ($file !== null) {
                return $file;
            }
        }

        return $template;
    }

    /**
     * Answer WordPress's `theme_file_path` from the registry.
     *
     * One line, and that is the point: this looped $custom_paths itself before
     * 5.0.0 — a second search with no traversal guard and no cache beside the
     * one locate() owns (INV-6). It returns whatever locate() returns, and
     * WordPress's own path when locate() finds nothing.
     */
    public static function locateInCustomPaths(string $path, string $file): string
    {
        return self::locate($file) ?? $path;
    }
}

NTDST_Template_Loader::init();
