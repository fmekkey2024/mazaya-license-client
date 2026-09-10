<?php

declare(strict_types=1);

namespace Mazaya\License\Fingerprint;

use Throwable;

/**
 * Describes the machine this copy is installed on.
 *
 * Every component is hashed before it leaves the building: the license server
 * only ever needs to know whether the hardware is the same, never what it is.
 * That distinction is what makes the whole scheme acceptable to a security team
 * reviewing outbound traffic.
 */
final class Collector
{
    /** @return array<string, string> */
    public function collect(): array
    {
        return [
            'machine' => $this->hash($this->machineId()),
            'mac'     => $this->hash($this->primaryMac()),
            'cpu'     => $this->hash($this->cpu()),
            'host'    => $this->hash(gethostname() ?: ''),
        ];
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
            // fall through
        }

        return '';
    }

    private function cpu(): string
    {
        if (! is_readable('/proc/cpuinfo')) {
            return '';
        }

        $contents = (string) @file_get_contents('/proc/cpuinfo');

        preg_match('/^model name\s*:\s*(.+)$/m', $contents, $model);

        return trim($model[1] ?? '').'|'.substr_count($contents, 'processor');
    }

    /** An unreadable component hashes to an empty string, which never matches. */
    private function hash(string $value): string
    {
        return $value === '' ? '' : 'sha256:'.hash('sha256', $value);
    }
}
