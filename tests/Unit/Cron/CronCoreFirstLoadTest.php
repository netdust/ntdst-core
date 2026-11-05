<?php
declare(strict_types=1);

namespace Ntdst\Tests\Unit\Cron;

defined('ABSPATH') || exit;

use PHPUnit\Framework\TestCase;

/** A stale copy of the old ntdst-cron plugin is dead, not fatal, when core loads first. */
final class CronCoreFirstLoadTest extends TestCase
{
    public function testTheOldPluginsCopyIsDeadWhenCoreLoadsFirst(): void
    {
        $root = dirname(__DIR__, 3);
        $fixtures = __DIR__ . '/fixtures/old-plugin';
        $script = tempnam(sys_get_temp_dir(), 'cron-first-') . '.php';

        file_put_contents($script, <<<PHP
            <?php
            define('ABSPATH', '/tmp/wordpress/');
            function add_action() {}
            function ntdst_set() {}
            function ntdst_get() {}
            function ntdst_log() {}
            require '{$root}/services/RunLog.php';
            require '{$root}/services/CronRegistry.php';
            require '{$fixtures}/bootstrap.php';
            spl_autoload_register(static function (string \$class) {
                if (str_starts_with(\$class, 'Ntdst\\\\Cron\\\\')) {
                    require '{$fixtures}/' . substr(\$class, strlen('Ntdst\\\\Cron\\\\')) . '.php';
                }
            });
            echo json_encode([
                'registry' => (new ReflectionClass(Ntdst\\Cron\\CronRegistry::class))->getFileName(),
                'helper' => (new ReflectionFunction('ntdst_cron_register'))->getFileName(),
            ]);
            PHP);

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        unlink($script);

        $seen = json_decode($out, true);
        $this->assertIsArray($seen, 'the subprocess must not fatal, got: ' . $out);
        $this->assertStringEndsWith('services/CronRegistry.php', $seen['registry']);
        $this->assertStringEndsWith('services/CronRegistry.php', $seen['helper'], 'the helper that answers is core\'s');
    }
}
