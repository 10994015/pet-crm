<?php

namespace App\Support;

/**
 * Turns a list of numbers into smooth SVG paths, drawn on the server so the dashboard
 * needs no chart script. Points come back as percentages so dots and labels can be
 * placed with plain HTML and stay round at any width.
 */
final class SmoothChart
{
    /**
     * @param  list<int|float>  $values
     * @return array{line: string, area: string, points: list<array{0: float, 1: float, 2: int|float}>, max: float}
     */
    public static function make(array $values, float $width = 1000, float $height = 300, float $top = 20, float $bottom = 10, ?float $max = null): array
    {
        $count = count($values);
        $max = $max ?? self::niceMax((float) max([1, ...$values]));

        if ($count === 0) {
            return ['line' => '', 'area' => '', 'points' => [], 'max' => $max];
        }

        $points = [];

        foreach (array_values($values) as $index => $value) {
            $x = $count === 1 ? $width / 2 : $index * $width / ($count - 1);
            $points[] = [$x, self::y((float) $value, $max, $height, $top, $bottom), $value];
        }

        $line = sprintf('M%.1f %.1f', $points[0][0], $points[0][1]);

        // Catmull-Rom through every point, written as cubic Bézier curves.
        for ($i = 0; $i < $count - 1; $i++) {
            [$x0, $y0] = $points[max(0, $i - 1)];
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[$i + 1];
            [$x3, $y3] = $points[min($count - 1, $i + 2)];

            $line .= sprintf(
                ' C%.1f %.1f %.1f %.1f %.1f %.1f',
                $x1 + ($x2 - $x0) / 6, min($height - $bottom, $y1 + ($y2 - $y0) / 6),
                $x2 - ($x3 - $x1) / 6, min($height - $bottom, $y2 - ($y3 - $y1) / 6),
                $x2, $y2,
            );
        }

        $area = $line.sprintf(' L%.1f %.1f L%.1f %.1f Z', $points[$count - 1][0], $height, $points[0][0], $height);

        return [
            'line' => $line,
            'area' => $area,
            'points' => array_map(fn (array $point) => [
                round($point[0] / $width * 100, 2),
                round($point[1] / $height * 100, 2),
                $point[2],
            ], $points),
            'max' => $max,
        ];
    }

    /** Where a value sits, as a percentage from the top of the drawing. */
    public static function yPercent(float $value, float $max, float $height = 300, float $top = 20, float $bottom = 10): float
    {
        return round(self::y($value, $max, $height, $top, $bottom) / $height * 100, 2);
    }

    private static function y(float $value, float $max, float $height, float $top, float $bottom): float
    {
        return $top + (1 - $value / max($max, 1)) * ($height - $top - $bottom);
    }

    /** Rounds up to a tidy axis maximum such as 50, 200 or 250,000. */
    public static function niceMax(float $max): float
    {
        $magnitude = 10 ** floor(log10(max($max, 1)));

        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($max <= $step * $magnitude) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }
}
