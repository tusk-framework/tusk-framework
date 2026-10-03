<?php

namespace Tusk\Cloud\Metric;

class Prometheus
{
    private static array $counters = [];

    private static array $gauges = [];

    public static function gauge(string $name, string $help): void
    {
        if (! isset(self::$gauges[$name])) {
            self::$gauges[$name] = ['help' => $help, 'value' => 0];
        }
    }

    public static function set(string $name, int|float $value): void
    {
        if (isset(self::$gauges[$name])) {
            self::$gauges[$name]['value'] = $value;
        }
    }

    public static function counter(string $name, string $help): void
    {
        if (! isset(self::$counters[$name])) {
            self::$counters[$name] = ['help' => $help, 'value' => 0];
        }
    }

    public static function inc(string $name, int $amount = 1): void
    {
        if (isset(self::$counters[$name])) {
            self::$counters[$name]['value'] += $amount;
        }
    }

    public static function render(): string
    {
        $output = '';
        foreach (self::$counters as $name => $data) {
            $output .= "# HELP $name {$data['help']}\n";
            $output .= "# TYPE $name counter\n";
            $output .= "$name {$data['value']}\n";
        }

        foreach (self::$gauges as $name => $data) {
            $output .= "# HELP $name {$data['help']}\n";
            $output .= "# TYPE $name gauge\n";
            $output .= "$name {$data['value']}\n";
        }

        return $output;
    }
}
