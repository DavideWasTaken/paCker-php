<?php
declare(strict_types=1);

namespace paCker;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** PHP 8.1+ facade for the standalone paCker JSON executable. */
final class Packer
{
    private const MAX_INPUT_BYTES = 1048576;
    private const MAX_OUTPUT_BYTES = 16777216;
    private string $binary;
    private ?float $timeoutSeconds;
    private array $boxes = [];
    private array $items = [];
    private array $options = [];

    /** A null timeout allows the solver's time_limit_ms plus five seconds. */
    public function __construct(?string $binary = null, ?float $timeoutSeconds = null)
    {
        if ($timeoutSeconds !== null && (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0)) {
            throw new InvalidArgumentException('Process timeout must be positive and finite.');
        }
        $this->binary = $binary ?? self::defaultBinary();
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * PACKER_BINARY, else the executable bundled with the Composer package
     * (bin/ next to src/, Windows or Linux x64), else build/ of a source checkout.
     */
    public static function defaultBinary(): string
    {
        $configured = getenv('PACKER_BINARY');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $windows = PHP_OS_FAMILY === 'Windows';
        if (in_array(strtolower(php_uname('m')), ['x86_64', 'amd64'], true)) {
            $bundled = dirname(__DIR__) . '/bin/packer-' . ($windows ? 'windows-x64.exe' : (PHP_OS_FAMILY === 'Linux' ? 'linux-x64' : 'none'));
            if (is_file($bundled)) {
                return $bundled;
            }
        }
        return dirname(__DIR__, 2) . '/build/packer' . ($windows ? '.exe' : '');
    }

    public function addBox(array $box): self
    {
        $this->boxes[] = $box;
        return $this;
    }

    /** $quantity is authoritative, including when $item contains quantity. */
    public function addItem(array $item, int $quantity = 1): self
    {
        if ($quantity < 1 || $quantity > 256) {
            throw new InvalidArgumentException('Item quantity must be between 1 and 256.');
        }
        $item['quantity'] = $quantity;
        $this->items[] = $item;
        return $this;
    }

    /** Successive calls merge options. The native CLI validates their domain. */
    public function setOptions(array $options): self
    {
        $this->options = array_replace($this->options, $options);
        return $this;
    }

    public function pack(): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('PHP proc_open is required to run the packing binary.');
        }
        $binary = realpath($this->binary);
        if ($binary !== false && is_file($binary) && PHP_OS_FAMILY !== 'Windows' && !is_executable($binary)) {
            // Archives and Git checkouts made on Windows may drop the executable bit.
            @chmod($binary, 0755);
            clearstatcache(true, $binary);
        }
        if ($binary === false || !is_file($binary) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($binary))) {
            throw new RuntimeException('Packing binary is missing or not executable: ' . $this->binary);
        }
        // Geometric metadata is compared exactly with the submitted doubles.
        // Preserve their round trips even when an application lowers its PHP
        // JSON precision; restore that setting immediately after encoding.
        $precision = ($this->options['support_mode'] ?? null) === 'geometric'
            ? ini_set('serialize_precision', '-1') : false;
        try {
            $input = json_encode(['boxes' => $this->boxes, 'items' => $this->items,
                'options' => (object) $this->options], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Cannot encode JSON request: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($precision !== false) { ini_set('serialize_precision', $precision); }
        }
        if (strlen($input) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('JSON request exceeds the 1 MiB input limit.');
        }
        $solverMs = $this->options['time_limit_ms'] ?? 1000;
        $timeout = $this->timeoutSeconds ?? ((is_numeric($solverMs) && is_finite((float) $solverMs)
            ? min(60000, max(1, (float) $solverMs)) : 1000) / 1000 + 5);
        $files = [];
        $process = null;
        try {
            // Regular files work on Windows too: anonymous pipe reads can block
            // even after stream_set_blocking(false). No pipe can fill and deadlock.
            for ($i = 0; $i < 3; ++$i) {
                $file = tempnam(sys_get_temp_dir(), 'packer-');
                if ($file === false) {
                    throw new RuntimeException('Cannot create temporary process files.');
                }
                $files[] = $file;
            }
            if (file_put_contents($files[0], $input) !== strlen($input)) {
                throw new RuntimeException('Cannot write temporary JSON request.');
            }
            $descriptors = [0 => ['file', $files[0], 'r'], 1 => ['file', $files[1], 'w'],
                2 => ['file', $files[2], 'w']];
            $pipes = [];
            $start = hrtime(true);
            // An argument array bypasses shell parsing, including paths with spaces.
            $process = @proc_open([$binary], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start packing binary: ' . $binary);
            }
            do {
                $status = proc_get_status($process);
                $this->checkOutputSize($files);
                if (!$status['running']) {
                    break;
                }
                if ((hrtime(true) - $start) / 1e9 >= $timeout) {
                    throw new RuntimeException('Packing process timeout after ' . $timeout . ' seconds.');
                }
                usleep(10000);
            } while (true);
            $exitCode = $status['exitcode'];
            $closedCode = proc_close($process);
            $process = null;
            if ($exitCode < 0) {
                $exitCode = $closedCode;
            }
            $this->checkOutputSize($files);
            $output = file_get_contents($files[1]);
            $stderr = file_get_contents($files[2]);
            if ($output === false || $stderr === false) {
                throw new RuntimeException('Cannot read packing process output.');
            }
            if ($exitCode !== 0) {
                $detail = trim(substr($output !== '' ? $output : $stderr, 0, 2048));
                throw new RuntimeException('Packing binary error (exit ' . $exitCode . '): ' . $detail);
            }
            try {
                $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('Invalid JSON output from packing binary: ' . $e->getMessage(), 0, $e);
            }
            self::checkJsonMembers($output);
            $this->validateResult($result);
            return $result;
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    private function checkOutputSize(array $files): void
    {
        $size = 0;
        foreach ([$files[1], $files[2]] as $file) {
            clearstatcache(true, $file);
            $bytes = filesize($file);
            if ($bytes === false) {
                throw new RuntimeException('Cannot inspect packing process output.');
            }
            $size += $bytes;
        }
        if ($size > self::MAX_OUTPUT_BYTES) {
            throw new RuntimeException('Packing process output exceeds the 16 MiB limit.');
        }
    }

    private static function numberInRange(mixed $value, float $minimum, float $maximum, bool $integer = false): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && (!$integer || is_int($value)) && $value >= $minimum && $value <= $maximum;
    }

    private static function numericFields(array $value, array $ranges): bool
    {
        foreach ($ranges as $key => [$minimum, $maximum]) {
            if (!self::numberInRange($value[$key] ?? null, $minimum, $maximum)) {
                return false;
            }
        }
        return true;
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id) && strlen($id) >= 1 && strlen($id) <= 63 && !str_contains($id, "\0");
    }

    private static function approximately(float $a, float $b): bool
    {
        return abs($a - $b) <= 1e-7 + 1e-12 * max(abs($a), abs($b));
    }

    private static function identityValid(mixed $value): bool
    {
        return is_array($value) && self::validId($value['id'] ?? null)
            && self::numberInRange($value['instance'] ?? null, 0, 255, true);
    }

    /** json_decode rejects syntax/UTF-8/depth errors; this second pass also
     * rejects duplicate decoded object keys, including escaped spellings. */
    private static function checkJsonMembers(string $json): void
    {
        // Consume complete strings so braces and colons in values stay opaque.
        // json_decode has already checked syntax, UTF-8 and nesting depth.
        $matched = preg_match_all('~"(?:[^"\\\\]++|\\\\.)*+"(\s*:)?|[{}]~s', $json, $tokens, PREG_SET_ORDER);
        if ($matched !== false) {
            $objects = []; $depth = -1;
            foreach ($tokens as $token) {
                if ($token[0] === '{') { $objects[++$depth] = []; }
                elseif ($token[0] === '}') { unset($objects[$depth--]); }
                elseif (isset($token[1]) && $token[1] !== '') {
                    $literal = substr($token[0], 0, strlen($token[0])-strlen($token[1]));
                    $key = str_contains($literal, '\\')
                        ? json_decode($literal, true, 2, JSON_THROW_ON_ERROR) : substr($literal, 1, -1);
                    if (array_key_exists($key, $objects[$depth])) {
                        throw new RuntimeException('Duplicate output JSON member.');
                    }
                    $objects[$depth][$key] = true;
                }
            }
            return;
        }
        // Application PCRE resource limits must not change protocol validity.
        // Discard partial matches and retain the original recursive scan.
        unset($tokens);
        $offset = 0;
        $space = static function () use ($json, &$offset): void {
            while (isset($json[$offset]) && str_contains(" \r\n\t", $json[$offset])) { ++$offset; }
        };
        $string = static function () use ($json, &$offset): string {
            $start = $offset++;
            while (isset($json[$offset])) {
                $c = $json[$offset++];
                if ($c === '\\') { ++$offset; }
                elseif ($c === '"') { break; }
            }
            return json_decode(substr($json, $start, $offset-$start), true, 2, JSON_THROW_ON_ERROR);
        };
        $value = static function () use (&$value, $space, $string, $json, &$offset): void {
            $space(); $c = $json[$offset];
            if ($c === '"') { $string(); return; }
            if ($c === '{' || $c === '[') {
                ++$offset; $space(); $close = $c === '{' ? '}' : ']'; $seen = [];
                if ($json[$offset] === $close) { ++$offset; return; }
                do {
                    $space();
                    if ($c === '{') {
                        $key = $string();
                        if (array_key_exists($key, $seen)) { throw new RuntimeException('Duplicate output JSON member.'); }
                        $seen[$key] = true; $space(); ++$offset;
                    }
                    $value(); $space();
                    $delimiter = $json[$offset++];
                } while ($delimiter === ',');
                return;
            }
            while (isset($json[$offset]) && !str_contains(",]} \r\n\t", $json[$offset])) { ++$offset; }
        };
        $value();
    }

    private static function onlyFields(array $object, array $fields): bool
    {
        return count($object) === count($fields) && array_diff(array_keys($object), $fields) === [];
    }

    private static function aggregateClose(float $a, float $b, int $terms): bool
    {
        return abs($a-$b) <= 2*($terms+2)*PHP_FLOAT_EPSILON*max(abs($a),abs($b));
    }

    private function validateGeometricMetadata(array $result): void
    {
        $options = array_replace(['angle_step'=>15, 'restarts'=>8, 'max_candidates'=>200000,
            'time_limit_ms'=>1000, 'seed'=>42, 'gap'=>0, 'support_mode'=>'geometric', 'clearance'=>0], $this->options);
        $stats = $result['stats'];
        if (!self::onlyFields($result, ['version','schema_version','support_mode','clearance','status','bins','unpacked','stats'])
            || !self::numberInRange($result['clearance'] ?? null, 0, 1e6)
            || (float) $result['clearance'] !== (float) $options['clearance']
            || !is_array($stats) || !self::onlyFields($stats, ['elapsed_ms','candidates','restarts','box_count','total_cost',
                'utilization','timed_out','budget_exhausted','stop_reason','seed','xy_candidates','xy_elapsed_ms','options'])
            || !is_array($stats['options'] ?? null) || !self::onlyFields($stats['options'], array_keys($options))) {
            throw new RuntimeException('Invalid output geometric metadata.');
        }
        foreach ($options as $field => $expected) {
            $actual = $stats['options'][$field];
            if ($field === 'support_mode' ? $actual !== $expected
                : (!self::numberInRange($actual, 0, 1e12) || (float) $actual !== (float) $expected)) {
                throw new RuntimeException('Output effective options differ from request.');
            }
        }
        $reason = $stats['stop_reason'] ?? null;
        if (!self::numberInRange($stats['seed'] ?? null, 0, 4294967295, true) || $stats['seed'] != $options['seed']
            || !self::numberInRange($stats['candidates'] ?? null, 0, $options['max_candidates'], true)
            || !self::numberInRange($stats['restarts'] ?? null, 0, $options['restarts'], true)
            || !self::numberInRange($stats['xy_candidates'] ?? null, 0, $stats['candidates'], true)
            || !self::numberInRange($stats['elapsed_ms'] ?? null, 0, PHP_FLOAT_MAX)
            || !self::numberInRange($stats['xy_elapsed_ms'] ?? null, 0, $stats['elapsed_ms'])
            || !in_array($reason, ['search_exhausted','completed','time_limit','candidate_limit'], true)
            || ($stats['timed_out'] ?? null) !== ($reason === 'time_limit')
            || ($stats['budget_exhausted'] ?? null) !== ($reason === 'candidate_limit')
            || ($reason === 'time_limit' && $stats['elapsed_ms'] < $options['time_limit_ms'])
            || ($reason === 'candidate_limit' && $stats['candidates'] != $options['max_candidates'])
            || (in_array($reason, ['search_exhausted','completed'], true)
                && ($reason === 'completed') !== ($result['unpacked'] === []))) {
            throw new RuntimeException('Invalid output geometric stop statistics.');
        }
    }

    /** Active local-to-container unit quaternion, returned as basis columns. */
    private static function geometricAxes(array $pose): array
    {
        [$x,$y,$z,$w] = $pose['orientation'];
        $n = $x*$x+$y*$y+$z*$z+$w*$w;
        // The accepted quaternion denotes its exactly normalized rotation.
        $s = 2/$n;
        return [[1-$s*($y*$y+$z*$z), $s*($x*$y+$z*$w), $s*($x*$z-$y*$w)],
            [$s*($x*$y-$z*$w), 1-$s*($x*$x+$z*$z), $s*($y*$z+$x*$w)],
            [$s*($x*$z+$y*$w), $s*($y*$z-$x*$w), 1-$s*($x*$x+$y*$y)]];
    }

    private static function geometricVertices(array $pose): array
    {
        $axes = self::geometricAxes($pose);
        $half = [$pose['width']/2, $pose['length']/2, $pose['height']/2];
        $vertices = [];
        for ($i=0; $i<8; ++$i) {
            $vertex = $pose['center'];
            for ($j=0; $j<3; ++$j) {
                for ($k=0; $k<3; ++$k) { $vertex[$k] += (($i & (1 << $j)) ? 1 : -1)*$half[$j]*$axes[$j][$k]; }
            }
            $vertices[] = $vertex;
        }
        return $vertices;
    }

    private static function validateGeometricPlacement(array $item, array $source, array $bin, float $margin): void
    {
        if (!self::onlyFields($item, ['id','instance','width','length','height','weight','center','orientation'])
            || !self::numericFields($item, ['width'=>[1e-4,1e6], 'length'=>[1e-4,1e6], 'height'=>[1e-4,1e6], 'weight'=>[0,1e12]])
            || !is_array($item['center'] ?? null) || !array_is_list($item['center']) || count($item['center']) !== 3
            || !is_array($item['orientation'] ?? null) || !array_is_list($item['orientation']) || count($item['orientation']) !== 4) {
            throw new RuntimeException('Invalid output geometric placement.');
        }
        foreach (array_merge($item['center'], $item['orientation']) as $value) {
            if (!self::numberInRange($value, -PHP_FLOAT_MAX, PHP_FLOAT_MAX)) {
                throw new RuntimeException('Invalid output nonfinite pose.');
            }
        }
        foreach (['width','length','height','weight'] as $field) {
            if ((float) $item[$field] !== (float) ($source[$field] ?? 0)) {
                throw new RuntimeException('Output geometric item differs from request.');
            }
        }
        $q = $item['orientation'];
        $norm2 = array_sum(array_map(static fn($x) => $x*$x, $q));
        $first = 0;
        foreach ([$q[3],$q[0],$q[1],$q[2]] as $component) { if ($component != 0) { $first=$component; break; } }
        if (!is_finite($norm2) || abs($norm2-1) > 1e-12 || $first <= 0) {
            throw new RuntimeException('Invalid output quaternion norm or canonical sign.');
        }
        $axes = self::geometricAxes($item);
        $near = static fn(float $a, float $b): bool => abs($a-$b) <= 8*PHP_FLOAT_EPSILON;
        $vertical = static fn(array $v): bool => $near($v[0],0) && $near($v[1],0) && $near(abs($v[2]),1);
        $rotation = $source['rotation'] ?? 'flat';
        if ($rotation === 'none') {
            for ($i=0; $i<3; ++$i) { for ($j=0; $j<3; ++$j) {
                if (!$near($axes[$i][$j], (float) (int) ($i===$j))) { throw new RuntimeException('Output violates none rotation.'); }
            } }
        } elseif (($rotation === 'flat' && (!$vertical($axes[2]) || $axes[2][2] <= 0))
            || ($rotation === 'any' && !array_filter($axes, $vertical))) {
            throw new RuntimeException('Output violates geometric rotation policy.');
        }
        if (($source['diagonal'] ?? true) === false) {
            foreach ($axes as $axis) {
                $units = 0;
                foreach ($axis as $value) {
                    if ($near(abs($value),1)) { ++$units; }
                    elseif (!$near($value,0)) { throw new RuntimeException('Output violates diagonal permission.'); }
                }
                if ($units !== 1) { throw new RuntimeException('Invalid output orthogonal rotation.'); }
            }
        }
        $epsilon = min(1e-9, 1e-9*min($item['width'],$item['length'],$item['height'], $bin['width'],$bin['length'],$bin['height']));
        foreach (self::geometricVertices($item) as $vertex) {
            foreach (['width','length','height'] as $i=>$dimension) {
                if ($vertex[$i] < $margin-$epsilon || $vertex[$i] > $bin[$dimension]-$margin+$epsilon) {
                    throw new RuntimeException('Output violates wall containment or clearance.');
                }
            }
        }
    }

    private static function dot(array $a, array $b): float { return $a[0]*$b[0]+$a[1]*$b[1]+$a[2]*$b[2]; }

    private static function geometricIntersects(array $a, array $b, float $margin): bool
    {
        $aa=self::geometricAxes($a); $bb=self::geometricAxes($b); $axes=array_merge($aa,$bb);
        foreach ($aa as $u) { foreach ($bb as $v) {
            $axes[]=[$u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]];
        } }
        $ha=[]; $hb=[];
        foreach (['width','length','height'] as $d) { $ha[]=($a[$d]+$margin)/2; $hb[]=($b[$d]+$margin)/2; }
        $epsilon=min(1e-9,1e-9*min($a['width'],$a['length'],$a['height'],$b['width'],$b['length'],$b['height']));
        $delta=[$b['center'][0]-$a['center'][0],$b['center'][1]-$a['center'][1],$b['center'][2]-$a['center'][2]];
        foreach ($axes as $axis) {
            $length=sqrt(self::dot($axis,$axis));
            if ($length == 0) { continue; }
            $axis=array_map(static fn($x)=>$x/$length,$axis); $radius=0.0;
            for ($i=0;$i<3;++$i) { $radius += $ha[$i]*abs(self::dot($aa[$i],$axis))+$hb[$i]*abs(self::dot($bb[$i],$axis)); }
            if (abs(self::dot($delta,$axis)) >= $radius-$epsilon) { return false; }
        }
        return true;
    }

    private function validateResult(mixed $result): void
    {
        $geometric = ($this->options['support_mode'] ?? 'full_base') === 'geometric';
        $versions = $geometric ? ['0.5.1-free3d-dev'] : ['0.1.0', '0.2.0', '0.3.0', '0.3.1', '0.3.2', '0.3.3', '0.4.0', '0.4.1', '0.5.0', '0.5.1'];
        $valid = is_array($result) && in_array($result['version'] ?? null, $versions, true)
            && ($geometric ? (($result['schema_version'] ?? null) === 2
                && ($result['support_mode'] ?? null) === 'geometric') : !array_key_exists('schema_version', $result))
            && in_array($result['status'] ?? null, ['complete', 'partial'], true)
            && isset($result['bins'], $result['unpacked'], $result['stats'])
            && is_array($result['bins']) && array_is_list($result['bins'])
            && is_array($result['unpacked']) && array_is_list($result['unpacked'])
            && is_array($result['stats']);
        if (!$valid) {
            throw new RuntimeException('Invalid output schema from packing binary.');
        }
        if ($geometric) { $this->validateGeometricMetadata($result); }
        $stats = $result['stats'];
        if (!self::numericFields($stats, ['elapsed_ms' => [0, PHP_FLOAT_MAX], 'total_cost' => [0, 256e12],
                'utilization' => [0, 1 + 1e-7]])
            || !self::numberInRange($stats['candidates'] ?? null, 0, 100000000, true)
            || !self::numberInRange($stats['restarts'] ?? null, 0, 64, true)
            || !self::numberInRange($stats['box_count'] ?? null, 0, 256, true)
            || !is_bool($stats['timed_out'] ?? null) || !is_bool($stats['budget_exhausted'] ?? null)
            || $stats['box_count'] !== count($result['bins'])
            || (($result['status'] === 'complete') !== ($result['unpacked'] === []))) {
            throw new RuntimeException('Invalid output statistics from packing binary.');
        }
        $identities = [];
        $requestedItems = [];
        foreach ($this->items as $item) {
            for ($i = 0; $i < $item['quantity']; ++$i) {
                $key = json_encode([$item['id'] ?? null, $i]);
                $identities[$key] = false;
                $requestedItems[$key] = $item;
            }
        }
        $boxTypes = [];
        foreach ($this->boxes as $box) {
            if (self::validId($box['id'] ?? null)) {
                $boxTypes[$box['id']] = $box;
            }
        }
        $stockUsed = [];
        $totalCost = 0.0;
        $totalBinVolume = 0.0;
        $totalItemVolume = 0.0;
        $allItems = $result['unpacked'];
        foreach ($result['bins'] as $index => $bin) {
            if (!is_array($bin) || ($bin['id'] ?? null) !== 'bin-' . ($index + 1)
                || !self::validId($bin['type_id'] ?? null) || !isset($boxTypes[$bin['type_id']])
                || !self::numericFields($bin, ['width' => [0.0001, 1e6], 'length' => [0.0001, 1e6],
                    'height' => [0.0001, 1e6], 'max_weight' => [0, 1e12], 'cost' => [0, 1e12],
                    'weight' => [0, $geometric ? 256e12 : 1e12]])
                || !is_array($bin['items'] ?? null) || !array_is_list($bin['items']) || $bin['items'] === []) {
                throw new RuntimeException('Invalid output bin from packing binary.');
            }
            $sourceBox = $boxTypes[$bin['type_id']];
            foreach (['width', 'length', 'height', 'cost', 'max_weight'] as $field) {
                $expected = $sourceBox[$field] ?? ($field === 'cost' ? 1 : ($field === 'max_weight' ? 1e12 : null));
                if (!is_numeric($expected) || ($geometric ? (float) $bin[$field] !== (float) $expected
                    : !self::approximately((float) $bin[$field], (float) $expected))) {
                    throw new RuntimeException('Output bin differs from its requested box type.');
                }
            }
            $stockUsed[$bin['type_id']] = ($stockUsed[$bin['type_id']] ?? 0) + 1;
            $stock = $sourceBox['quantity'] ?? -1;
            if (!self::numberInRange($stock, -1, 256) || floor((float) $stock) !== (float) $stock) {
                throw new RuntimeException('Output uses invalid requested box stock.');
            }
            // JSON has one numeric type: PHP -1.0 encodes as the valid sentinel -1.
            $stock = (int) $stock;
            if (($stock !== -1 && $stockUsed[$bin['type_id']] > $stock)
                || (!$geometric && $bin['weight'] > $bin['max_weight'] + 1e-7)) {
                throw new RuntimeException('Output exceeds requested box stock or weight capacity.');
            }
            if ($geometric && !self::onlyFields($bin, ['id','type_id','width','length','height','max_weight','cost','weight','items'])) {
                throw new RuntimeException('Invalid output bin members.');
            }
            $binWeight = 0.0;
            $geometricItems = [];
            foreach ($bin['items'] as $item) {
                if ($geometric) {
                    $sourceItem = self::identityValid($item)
                        ? ($requestedItems[json_encode([$item['id'], $item['instance']])] ?? null) : null;
                    if ($sourceItem === null) { throw new RuntimeException('Unexpected output item identity.'); }
                    self::validateGeometricPlacement($item, $sourceItem, $bin, (float) $result['clearance']);
                    foreach ($geometricItems as $other) {
                        if (self::geometricIntersects($item, $other, (float) $result['clearance'])) {
                            throw new RuntimeException('Output intersection or clearance violation.');
                        }
                    }
                    $geometricItems[] = $item;
                } else {
                    if (!self::identityValid($item)
                        || !self::numericFields($item, ['x' => [-1e-7, $bin['width'] + 1e-7],
                            'y' => [-1e-7, $bin['length'] + 1e-7], 'z' => [-1e-7, $bin['height'] + 1e-7],
                            'width' => [0.0001, 1e6], 'length' => [0.0001, 1e6], 'height' => [0.0001, 1e6],
                            'angle' => [0, 180], 'weight' => [0, 1e12]]) || $item['angle'] >= 180
                        || $item['z'] + $item['height'] > $bin['height'] + 1e-7) {
                        throw new RuntimeException('Invalid output placement from packing binary.');
                    }
                    $sourceItem = $requestedItems[json_encode([$item['id'], $item['instance']])] ?? null;
                    if ($sourceItem === null || !self::approximately((float) $item['weight'], (float) ($sourceItem['weight'] ?? 0))) {
                        throw new RuntimeException('Output placement differs from its requested item.');
                    }
                    $dimensions = [$item['width'], $item['length'], $item['height']];
                    $expectedDimensions = [$sourceItem['width'] ?? 0, $sourceItem['length'] ?? 0, $sourceItem['height'] ?? 0];
                    $rotation = $sourceItem['rotation'] ?? 'flat';
                    if ($rotation === 'any') {
                        sort($dimensions, SORT_NUMERIC);
                        sort($expectedDimensions, SORT_NUMERIC);
                    }
                    for ($i = 0; $i < 3; ++$i) {
                        if (!is_numeric($expectedDimensions[$i])
                            || !self::approximately((float) $dimensions[$i], (float) $expectedDimensions[$i])) {
                            throw new RuntimeException('Output item dimensions differ from the request.');
                        }
                    }
                    if (($rotation === 'none' && $item['angle'] != 0)
                        || (($sourceItem['diagonal'] ?? true) === false && $item['angle'] != 0 && $item['angle'] != 90)) {
                        throw new RuntimeException('Output angle violates the requested rotation policy.');
                    }
                }
                $binWeight += $item['weight'];
                $totalItemVolume += $item['width'] * $item['length'] * $item['height'];
                $allItems[] = $item;
            }
            if (!($geometric ? self::aggregateClose($binWeight, (float) $bin['weight'], count($bin['items']))
                : self::approximately($binWeight, (float) $bin['weight']))) {
                throw new RuntimeException('Invalid output bin weight total.');
            }
            if ($geometric && ($binWeight > $bin['max_weight'])
                && ($bin['max_weight'] == 0 || !self::aggregateClose($binWeight, (float) $bin['max_weight'], count($bin['items'])))) {
                throw new RuntimeException('Output exceeds geometric weight capacity.');
            }
            $totalCost += $bin['cost'];
            $totalBinVolume += $bin['width'] * $bin['length'] * $bin['height'];
        }
        if ($geometric) {
            foreach ($result['unpacked'] as $item) {
                if (!is_array($item) || !self::onlyFields($item, ['id','instance'])) {
                    throw new RuntimeException('Invalid output unpacked members.');
                }
            }
        }
        foreach ($allItems as $item) {
            if (!self::identityValid($item)) {
                throw new RuntimeException('Invalid output item identity from packing binary.');
            }
            $key = json_encode([$item['id'], $item['instance']]);
            if (!array_key_exists($key, $identities) || $identities[$key]) {
                throw new RuntimeException('Unexpected or duplicate output item from packing binary.');
            }
            $identities[$key] = true;
        }
        if (in_array(false, $identities, true)) {
            throw new RuntimeException('Packing output omitted requested items.');
        }
        $utilization = $totalBinVolume > 0 ? $totalItemVolume / $totalBinVolume : 0;
        if (!($geometric ? self::aggregateClose((float) $stats['total_cost'], $totalCost, count($result['bins']))
                && self::aggregateClose((float) $stats['utilization'], $utilization, count($allItems)*4)
            : self::approximately((float) $stats['total_cost'], $totalCost)
                && self::approximately((float) $stats['utilization'], $utilization))) {
            throw new RuntimeException('Invalid output cost or utilization total.');
        }
    }
}

// Keep applications using the previous namespace working, including direct requires.
class_alias(Packer::class, 'ObliquePacker\\Packer');
