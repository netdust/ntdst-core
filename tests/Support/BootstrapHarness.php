<?php // tests/Support/BootstrapHarness.php
// The two things every Bootstrap suite in this package was carrying its own
// copy of: the refusal recorder, and the planted-service harness.
//
// WHY THEY ARE SHARED. Four files asked the same two questions —
// "what did _doing_it_wrong() say" and "did core execute a file on disk" — and
// each answered them with its own copy of the same twenty lines. Copies drift:
// one of them already recorded a third bucket the others did not, and a fix to
// the cleanup order would have had to land four times. The bucket NAMES stay
// per-file, because each suite's assertions are written against its own globals
// and a shared bucket would let one file's leftovers answer another file's
// question.
//
// The planted file is the strong form of "core read no file": the recorder is
// the FIRST statement of the body, so the file counts as read however core
// reached it — a glob, a derived path, an autoloader core installed, a stream
// wrapper — and not merely by the one function today's code happens to call.
// `glob()` and `file_get_contents()` cannot be mocked here at all: they are
// internal PHP functions and Patchwork refuses to redefine an internal without
// a `redefinable-internals` entry this package does not ship.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

/**
 * Record every `_doing_it_wrong()` a boot provokes, and print them back.
 *
 * Recorded through `Functions\when()->alias()` rather than counted by a Mockery
 * `->times(1)`: a refusal is judged on WHAT IT SAYS — the site owner reading it
 * has to learn which class and which config key — so the message has to be
 * readable back, and a count failure has to be able to print the refusals that
 * did fire.
 */
trait NtdstRecordsRefusals
{
    /**
     * Every `_doing_it_wrong()` call the boot provoked: [function, message, version].
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private array $wrongs = [];

    /** Install the recorder. Call from setUp(), after Monkey\setUp(). */
    private function recordRefusals(): void
    {
        $this->wrongs = [];

        \Brain\Monkey\Functions\when('_doing_it_wrong')
            ->alias(function ($function = '', $message = '', $version = '') {
                $this->wrongs[] = [(string) $function, (string) $message, (string) $version];
            });
    }

    /** The refusals that fired, as one readable line. */
    private function wrongsText(): string
    {
        if ($this->wrongs === []) {
            return '(no _doing_it_wrong call)';
        }

        return implode(' | ', array_map(static fn(array $w) => $w[0] . ': ' . $w[1], $this->wrongs));
    }
}

/**
 * Plant PHP files that record their own execution, and sweep them up after.
 *
 * The globals each planted file writes to are named by the using suite, so its
 * assertions keep reading the buckets they already read.
 */
trait NtdstPlantsServiceFiles
{
    /** Throwaway tree for the planted files. */
    private string $root = '';

    /** Deepest-first cleanup list. */
    private array $litter = [];

    /** The global a planted file records its own execution in. */
    private string $includedBucket = '_ntdst_planted_included';

    /** The global a planted service records its construction in. */
    private string $constructedBucket = '_ntdst_planted_constructed';

    /** The global a planted service records the instance itself in, if any. */
    private ?string $instancesBucket = null;

    /**
     * Open a throwaway tree and name the buckets. Call from setUp().
     *
     * The tag keeps two suites running in one process out of each other's tree;
     * the pid and uniqid keep two processes apart.
     */
    private function plantingRoot(
        string $tag,
        string $includedBucket,
        string $constructedBucket,
        ?string $instancesBucket = null,
    ): void {
        $this->root = sys_get_temp_dir() . '/ntdst-' . $tag . '-' . getmypid() . '-' . uniqid();
        $this->litter = [];
        $this->includedBucket = $includedBucket;
        $this->constructedBucket = $constructedBucket;
        $this->instancesBucket = $instancesBucket;
    }

    /**
     * Write a PHP file that records its own execution and then declares a
     * service, and register it for cleanup.
     */
    private function plant(string $dir, string $file, string $namespace, string $class): void
    {
        $instances = $this->instancesBucket === null
            ? ''
            : "        \$GLOBALS['{$this->instancesBucket}'][] = \$this;\n";

        $this->plantFile(
            $dir,
            $file,
            "<?php namespace {$namespace};\n"
                . "\$GLOBALS['{$this->includedBucket}'][] = __FILE__;\n"
                . "class {$class} {\n"
                . "    public function __construct() {\n"
                . "        \$GLOBALS['{$this->constructedBucket}'][] = static::class;\n"
                . $instances
                . "    }\n"
                . "    public static function metadata(): array { return []; }\n"
                . "}\n",
        );
    }

    /**
     * Write an arbitrary PHP file into the throwaway tree and register it for
     * cleanup. Returns its full path.
     */
    private function plantFile(string $dir, string $file, string $code): string
    {
        $path = $this->root . $dir;

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path . '/' . $file, $code);

        // The file, and every directory this call may have created back up to
        // $this->root. sweepLitter() removes them deepest-first.
        $this->litter[] = $path . '/' . $file;
        for ($walk = $path; str_starts_with($walk, $this->root); $walk = dirname($walk)) {
            $this->litter[] = $walk;
        }

        return $path . '/' . $file;
    }

    /**
     * Remove everything planted. Call from tearDown().
     *
     * Deepest path first, so a directory is empty by the time rmdir() sees it.
     * Every entry lives under $this->root, so a longer string is a deeper path.
     */
    private function sweepLitter(): void
    {
        $litter = array_unique($this->litter);
        usort($litter, static fn(string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($litter as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        $this->litter = [];
    }
}
