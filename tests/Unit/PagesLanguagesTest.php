<?php // tests/Unit/PagesLanguagesTest.php
// 5.4.0 — a page route has a URL per language. Two filters say which languages
// exist and which one this request is in; core never learns who answers them
// (ntdst-baseline's polylang module does). Without an answer, path() and url()
// are what they were — NtdstPagesTest is that pin.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Pages.php';

final class PagesLanguagesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const LANGUAGES = [
        ['code' => 'nl', 'prefix' => '', 'query' => '', 'default' => true],
        ['code' => 'fr', 'prefix' => 'fr', 'query' => 'lang=fr'],
        ['code' => 'en', 'prefix' => 'en', 'query' => 'lang=en'],
    ];

    private const CALENDAR = ['nl' => '/speellijst.ics', 'fr' => '/calendrier.ics'];

    /** @var list<array{0:string,1:string,2:string}> */
    private array $rules = [];
    /** @var array<string, mixed> what each filter answers; an absent hook keeps its default */
    private array $filters = [];
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<string, mixed> */
    private array $queryVars = [];
    /** @var list<bool> */
    private array $flushes = [];
    /** @var list<array{0:string,1:string}> */
    private array $wrong = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->rules = [];
        $this->filters = [];
        $this->options = [];
        $this->queryVars = [];
        $this->flushes = [];
        $this->wrong = [];

        unset(
            $GLOBALS['_ntdst_test_filters'],
            $GLOBALS['_ntdst_test_filters_at'],
            $GLOBALS['_ntdst_test_filter_args'],
            $GLOBALS['wp_query'],
            $GLOBALS['wp_rewrite'],
            $GLOBALS['wp'],
        );

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_rewrite_rule')->alias(function (string $regex, string $query, string $after = 'bottom'): void {
            $this->rules[] = [$regex, $query, $after];
        });
        Functions\when('apply_filters')->alias(
            fn (string $hook, $value = null) => array_key_exists($hook, $this->filters) ? $this->filters[$hook] : $value,
        );
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://laika.test' . $path);
        Functions\when('get_query_var')->alias(fn (string $var, $default = '') => $this->queryVars[$var] ?? $default);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('flush_rewrite_rules')->alias(function (bool $hard = true): void {
            $this->flushes[] = $hard;
        });
        Functions\when('status_header')->justReturn(null);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('_doing_it_wrong')->alias(function (string $fn, string $message, $version = ''): void {
            $this->wrong[] = [$fn, $message];
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $GLOBALS['wp_query'], $GLOBALS['wp_rewrite'], $GLOBALS['wp']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function withLanguages(?string $current = null): void
    {
        $this->filters['ntdst/pages/languages'] = self::LANGUAGES;
        $this->filters['ntdst/pages/current_language'] = $current;
    }

    public function testStringPatternRegistersEveryLanguage(): void
    {
        $this->withLanguages();

        (new NTDST_Pages())->path('/speellijst.ics', fn (): string => __FILE__);

        $this->assertSame(
            [
                ['^speellijst\.ics/?$', 'index.php?ntdst_page=0', 'top'],
                ['^fr/speellijst\.ics/?$', 'index.php?ntdst_page=1&lang=fr', 'top'],
                ['^en/speellijst\.ics/?$', 'index.php?ntdst_page=2&lang=en', 'top'],
            ],
            $this->rules,
            'one rule per language: the default unprefixed and without a query of its own.',
        );
    }

    public function testArrayPatternGivesEachLanguageItsWords(): void
    {
        $this->withLanguages();

        (new NTDST_Pages())->path(
            ['nl' => '/speellijst/:show', 'fr' => '/calendrier/:show'],
            fn (): string => __FILE__,
        );

        $this->assertSame(
            [
                ['^speellijst/([^/]+)/?$', 'index.php?ntdst_page=0&ntdst_p_show=$matches[1]', 'top'],
                ['^fr/calendrier/([^/]+)/?$', 'index.php?ntdst_page=1&ntdst_p_show=$matches[1]&lang=fr', 'top'],
                ['^en/speellijst/([^/]+)/?$', 'index.php?ntdst_page=2&ntdst_p_show=$matches[1]&lang=en', 'top'],
            ],
            $this->rules,
            'a language the array does not name uses the default language\'s words.',
        );
    }

    public function testArrayPatternWithoutLanguagesUsesItsFirstEntry(): void
    {
        $pages = new NTDST_Pages();
        $pages->path(self::CALENDAR, fn (): string => __FILE__);

        $this->assertSame([['^speellijst\.ics/?$', 'index.php?ntdst_page=0', 'top']], $this->rules);
        $this->assertSame('https://laika.test/speellijst.ics', $pages->url(self::CALENDAR));
    }

    public function testPlaceholderMismatchIsRefused(): void
    {
        $this->withLanguages();

        (new NTDST_Pages())->path(['nl' => '/voorstelling/:slug', 'fr' => '/spectacle/:id'], fn (): string => __FILE__);

        $this->assertSame([], $this->rules, 'variants that disagree on their placeholders register no rule at all.');
        $this->assertCount(1, $this->wrong, 'the refusal says so once, not once per language.');
    }

    public function testDispatchRunsTheCallbackForALanguageVariant(): void
    {
        $this->withLanguages('fr');
        $ran = 0;

        $pages = new NTDST_Pages();
        $pages->path(self::CALENDAR, function () use (&$ran): string {
            $ran++;

            return __FILE__;
        });

        $this->queryVars['ntdst_page'] = '1';
        $GLOBALS['wp'] = (object) ['matched_rule' => '^fr/calendrier\.ics/?$'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $pages->dispatch();

        $this->assertSame(1, $ran, 'the French rule is its own route, and it runs the shared callback.');
    }

    public function testUrlUsesCurrentLanguage(): void
    {
        $this->withLanguages('fr');
        $pages = new NTDST_Pages();

        $this->assertSame('https://laika.test/fr/calendrier.ics', $pages->url(self::CALENDAR));
        $this->assertSame('https://laika.test/fr/show/hamlet', $pages->url('/show/:slug', ['slug' => 'hamlet']));
    }

    public function testUrlExplicitLanguageWins(): void
    {
        $this->withLanguages('fr');

        $this->assertSame('https://laika.test/en/speellijst.ics', (new NTDST_Pages())->url(self::CALENDAR, [], 'en'));
    }

    public function testUrlDefaultLanguageHasNoPrefix(): void
    {
        $this->withLanguages();
        $pages = new NTDST_Pages();

        $this->assertSame('https://laika.test/speellijst.ics', $pages->url(self::CALENDAR));
        $this->assertSame('https://laika.test/speellijst.ics', $pages->url(self::CALENDAR, [], 'nl'));
        $this->assertSame('https://laika.test/speellijst.ics', $pages->url(self::CALENDAR, [], 'xx'));
    }

    public function testLanguageAccessorReturnsTheFilterAnswer(): void
    {
        $pages = new NTDST_Pages();
        $this->assertNull($pages->language(), 'without languages there is no current one.');

        $this->withLanguages('fr');
        $this->assertSame('fr', $pages->language());
    }

    public function testLanguageChangeFlushesOnce(): void
    {
        $before = new NTDST_Pages();
        $before->path('/speellijst.ics', fn (): string => __FILE__);
        $before->flushWhenRulesChanged();
        $this->assertSame([false], $this->flushes);

        $this->withLanguages();
        $after = new NTDST_Pages();
        $after->path('/speellijst.ics', fn (): string => __FILE__);
        $after->flushWhenRulesChanged();
        $after->flushWhenRulesChanged();

        $this->assertSame([false, false], $this->flushes, 'the language variants change the rule set once.');
    }
}
