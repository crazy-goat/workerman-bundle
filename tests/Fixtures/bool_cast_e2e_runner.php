<?php

declare(strict_types=1);

/** @var array<int, string> $argv */

require $argv[1];

$assertTrue = static function (bool $condition, string $message): void {
    if (!$condition) {
        fprintf(STDERR, "FAIL: %s\n", $message);
        exit(1);
    }
};

$testCases = [
    ['input' => true,  'expected' => true],
    ['input' => false, 'expected' => false],
    ['input' => 1,     'expected' => true],
    ['input' => 0,     'expected' => false],
    ['input' => '1',   'expected' => true],
    ['input' => '',    'expected' => false],
    ['input' => null,  'expected' => false],
];

foreach ($testCases as $case) {
    $input = $case['input'];
    $expected = $case['expected'];
    $result = (bool) $input;
    $assertTrue($result === $expected, sprintf('(bool) cast: expected %s for input %s, got %s', var_export($expected, true), var_export($input, true), var_export($result, true)));
}

foreach ($testCases as $case) {
    $input = $case['input'];
    $expected = $case['expected'];
    $result = boolval($input);
    $assertTrue($result === $expected, sprintf('boolval: expected %s for input %s, got %s', var_export($expected, true), var_export($input, true), var_export($result, true)));
}

fprintf(STDERR, "OK\n");
exit(0);
