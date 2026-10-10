# paCker for PHP

Fast 3D cuboid packing: boxes, products, stock, weight limits, rotations and stable
support. The native C engine is bundled for Windows x64 and Linux x64; no PHP extension,
FFI or compiler is needed. PHP 8.1+ with `ext-json` and `proc_open`.

```sh
composer require davidewastaken/packer
```

```php
require 'vendor/autoload.php';

$packer = new \paCker\Packer();
$packer->addBox(['id' => 'carton', 'width' => 300, 'length' => 200, 'height' => 150, 'max_weight' => 5000]);
$packer->addItem(['id' => 'book', 'width' => 240, 'length' => 170, 'height' => 30, 'weight' => 400], 6);
$packer->setOptions(['time_limit_ms' => 1000]);
$result = $packer->pack();   // bins with positions, unpacked products, stats
```

Options include `support_mode` (`stable` by default, `contact`, `full_base`, `geometric`), `improve` (keep
searching until the time limit) and `gap`. On other platforms pass your own build:
`new \paCker\Packer('/path/to/packer')` or set `PACKER_BINARY`.

Engine 0.5.0. Source, documentation and the C library: https://github.com/DavideWasTaken/paCker

MIT licensed; the bundled engine includes cJSON (MIT, see LICENSE-cJSON).
