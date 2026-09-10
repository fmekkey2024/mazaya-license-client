<?php

declare(strict_types=1);

namespace Mazaya\License\Fingerprint;

use Throwable;

/**
 * Describes the machine this copy is installed on.
 *
 * Every component is hashed before it leaves the building: the licence server
 * only needs to know whether the hardware is the same, never what it is. That
 * distinction is what makes the whole scheme acceptable to a security team
 * reviewing outbound traffic.
 *
 * Production installations are Linux, but developers are not, so each component
 * has macOS and Windows sources too. Components that cannot be read are
 * omitted rather than sent empty — the server rejects empty ones, and an
 * activation from a developer's laptop failing validation is a confusing way to
 * discover that.
 */
final class Collector
{
    /** @return array<string, string> */
    public function collect(): array
    {
        $components = array_filter([
            'machine' => $this->hash($this->machineId()),
            'mac'     => $this->hash($this->primaryMac()),
            'cpu'     => $this->hash($this->cpu()),
            'host'    => $this->hash(gethostname() ?: ''),
        ], static fn (string $value): bool => $value !== '');

        // A machine that cannot describe itself at all would be
        // indistinguishable from every other one, so fall back to an id
        // generated once and kept alongside the licence.
        if ($components === []) {
            $components['machine'] = $this->hash($this->fallbackId());
        }

        return $components;
    }

    private function machineId(): string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
            if (is_readable($path)) {
                $value = trim((string) @file_get_contents($path));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', $this->run('ioreg -rd1 -c IOPlatformExpertDevice'), $m)) {
            return $m[1];   // macOS
        }

        if (preg_match('/MachineGuid\s+REG_SZ\s+(\S+)/i', $this->run('reg query "HKLM\SOFTWARE\Microsoft\Cryptography" /v MachineGuid'), $m)) {
            return $m[1];   // Windows
        }

        return '';
    }

    private function primaryMac(): string
    {
        try {
            foreach (glob('/sys/class/net/*/address') ?: [] as $path) {
                $interface = basename(dirname($path));

                if ($interface === 'lo' || str_starts_with($interface, 'docker') || str_starts_with($interface, 'br-')) {
                    continue;
                }

                $mac = trim((string) @file_get_contents($path));

                if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
                    return $mac;
                }
            }
        } catch (Throwable) {
            // fall through to the portable probes
        }

        // macOS and any BSD
        if (preg_match('/\bether\s+([0-9a-f:]{17})/i', $this->run('ifconfig'), $m)) {
            return strtolower($m[1]);
        }

        // Windows
        if (preg_match('/\b([0-9A-F]{2}(?:-[0-9A-F]{2}){5})\b/i', $this->run('getmac'), $m)) {
            return strtolower(str_replace('-', ':', $m[1]));
        }

        return '';
    }

    private function cpu(): string
    {
        if (is_readable('/proc/cpuinfo')) {
            $contents = (string) @file_get_contents('/proc/cpuinfo');
            preg_match('/^model name\s*:\s*(.+)$/m', $contents, $model);

            return trim($model[1] ?? '').'|'.substr_count($contents, 'processor');
        }

        $brand = trim($this->run('sysctl -n machdep.cpu.brand_string'));   // macOS

        if ($brand !== '') {
            return $brand;
        }

        $identifier = (string) getenv('PROCESSOR_IDENTIFIER');             // Windows

        if ($identifier !== '') {
            return $identifier.'|'.getenv('NUMBER_OF_PROCESSORS');
        }

        return trim(php_uname('m').' '.php_uname('s'));
    }

    /** Last resort, kept next to the licence so it survives a cache clear. */
    private function fallbackId(): string
    {
        $path = storage_path('app/license/machine');

        if (is_readable($path)) {
            $value = trim((string) @file_get_contents($path));

            if ($value !== '') {
                return $value;
            }
        }

        $value = bin2hex(random_bytes(16));

        @mkdir(dirname($path), 0750, true);
        @file_put_contents($path, $value);

        return $value;
    }

    /** Shelling out is optional: plenty of hosts disable it, and that is fine. */
    private function run(string $command): string
    {
        if (! function_exists('shell_exec')) {
            return '';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('shell_exec', $disabled, true)) {
            return '';
        }

        try {
            return (string) @shell_exec($command.' 2>/dev/null');
        } catch (Throwable) {
            return '';
        }
    }

    /** An unreadable component hashes to an empty string and is dropped. */
    private function hash(string $value): string
    {
        return $value === '' ? '' : 'sha256:'.hash('sha256', $value);
    }
}
