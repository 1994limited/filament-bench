<?php

/**
 * Fills the migrated database with a seeded shop, straight through PDO: no
 * models, no Filament, so the code under test cannot shape its own fixture.
 * The same seed and size give the same rows, and a fingerprint of them is
 * written to the manifest so two targets can be shown to hold the same data.
 *
 *   php bench/generate.php [--size=small] [--seed=1001] [--customers=N --orders=N --products=N --tags=N ...]
 *
 * Also writes the files the `media` disk serves and bench/manifest.json:
 * the counts, the ids the scenarios use, and the scenarios themselves.
 */

const SIZES = [
    'micro' => ['countries' => 30, 'categories' => 20, 'customers' => 200, 'products' => 60, 'orders' => 600, 'tags' => 50, 'notifications' => 20],
    'small' => ['countries' => 60, 'categories' => 60, 'customers' => 2000, 'products' => 300, 'orders' => 10000, 'tags' => 2000, 'notifications' => 60],
    'medium' => ['countries' => 120, 'categories' => 120, 'customers' => 20000, 'products' => 2000, 'orders' => 100000, 'tags' => 20000, 'notifications' => 200],
    'large' => ['countries' => 200, 'categories' => 200, 'customers' => 100000, 'products' => 5000, 'orders' => 500000, 'tags' => 50000, 'notifications' => 500],
];

// the items in the showcase order, which the edit page renders as Repeater items
const SHOWCASE_ITEMS = 20;
// a bcrypt hash of "password", fixed so the bytes are the same every time
const PASSWORD = '$2y$04$7IoV4tUTsU5fr0omcF9IWucHVG2.KlFBkWkdl/f/BztIeJi6Ojj7q';
const EPOCH = 1767225600; // 2026-01-01 00:00:00 UTC

$opts = getopt('', ['size:', 'seed:', 'countries:', 'categories:', 'customers:', 'products:', 'orders:', 'tags:', 'notifications:']);
$size = $opts['size'] ?? 'small';
isset(SIZES[$size]) || exit("generate: unknown size $size (".implode(', ', array_keys(SIZES)).")\n");
$seed = (int) ($opts['seed'] ?? 1001);
$n = SIZES[$size];
foreach ($n as $k => $_) {
    isset($opts[$k]) && $n[$k] = max(1, (int) $opts[$k]);
}
$n['products'] = max($n['products'], SHOWCASE_ITEMS);
mt_srand($seed, MT_RAND_MT19937);

$root = dirname(__DIR__);
$pdo = new PDO('sqlite:'.$root.'/database/database.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$fingerprint = hash_init('sha256');

/** Insert rows in one statement per table, and fold every value into the fingerprint. */
$insert = function (string $table, array $rows) use ($pdo, $fingerprint) {
    if (! $rows) {
        return;
    }
    $cols = array_keys($rows[0]);
    $stmt = $pdo->prepare("insert into \"$table\" (\"".implode('","', $cols).'") values ('.implode(',', array_fill(0, count($cols), '?')).')');
    foreach ($rows as $row) {
        $stmt->execute(array_values($row));
        hash_update($fingerprint, $table."\0".json_encode(array_values($row))."\n");
    }
};
$pick = fn (array $xs) => $xs[mt_rand(0, count($xs) - 1)];
$date = fn (int $daysAgoMax) => gmdate('Y-m-d H:i:s', EPOCH - mt_rand(0, $daysAgoMax * 86400));
$words = explode(' ', 'amber birch cedar delta ember fable garnet harbor indigo juniper kestrel lumen maple nimbus onyx pebble quartz raven sable tundra umber velvet willow xenon yarrow zephyr');
$title = fn (int $count) => ucfirst(implode(' ', array_map(fn () => $pick($words), range(1, $count))));
$first = ['Ada', 'Ben', 'Cleo', 'Dev', 'Eli', 'Fay', 'Gus', 'Hana', 'Ivo', 'Jade', 'Kai', 'Lena', 'Milo', 'Nora', 'Otto', 'Pia', 'Quin', 'Rosa', 'Sami', 'Tess'];
$last = ['Abbott', 'Barros', 'Chen', 'Dubois', 'Eze', 'Fischer', 'Garcia', 'Haddad', 'Ito', 'Jensen', 'Kowalski', 'Larsen', 'Moreau', 'Novak', 'Okafor', 'Petrov', 'Quist', 'Rossi', 'Silva', 'Tanaka'];

$pdo->beginTransaction();

$insert('users', [['id' => 1, 'name' => 'Bench Admin', 'email' => 'admin@bench.test', 'password' => PASSWORD, 'created_at' => gmdate('Y-m-d H:i:s', EPOCH), 'updated_at' => gmdate('Y-m-d H:i:s', EPOCH)]]);

$rows = [];
for ($i = 1; $i <= $n['countries']; $i++) {
    $rows[] = ['id' => $i, 'name' => 'Country '.$title(1).' '.$i, 'code' => chr(65 + intdiv($i - 1, 26) % 26).chr(65 + ($i - 1) % 26)];
}
$insert('countries', $rows);

$rows = [];
for ($i = 1; $i <= $n['categories']; $i++) {
    $name = $title(2).' '.$i;
    $rows[] = ['id' => $i, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))];
}
$insert('categories', $rows);

// avatars: 40 files exist; customers also point at 10 that don't, so an existence check has something to find
$statuses = ['lead', 'active', 'active', 'active', 'churned', 'vip'];
$rows = [];
for ($i = 1; $i <= $n['customers']; $i++) {
    $name = $pick($first).' '.$pick($last);
    $at = $date(700);
    $rows[] = ['id' => $i, 'country_id' => mt_rand(1, $n['countries']), 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).".$i@example.test",
        'avatar' => mt_rand(1, 10) === 1 ? null : 'avatars/a'.mt_rand(1, 50).'.png', 'status' => $pick($statuses), 'balance' => mt_rand(-50000, 500000),
        'is_active' => (int) (mt_rand(1, 10) <= 7), 'notes' => mt_rand(0, 1) ? "**{$title(2)}** — ".$title(8).'.' : null, 'created_at' => $at, 'updated_at' => $at];
    if (count($rows) === 1000) {
        $insert('customers', $rows);
        $rows = [];
    }
}
$insert('customers', $rows);

$rows = [];
for ($i = 1; $i <= $n['products']; $i++) {
    $at = $date(900);
    $images = $i === 1 ? array_map(fn ($k) => "products/p$k.png", range(1, 5)) : [];
    $description = '<p>'.$title(30).'.</p>';
    if ($i === 1) {
        // the showcase product: a rich text body with ten embedded images on the media disk
        foreach (range(1, 10) as $k) {
            $description .= '<p>'.$title(12).'.</p><img src="https://media.bench.test/attachments/r'.$k.'.png" data-id="attachments/r'.$k.'.png">';
        }
    }
    $rows[] = ['id' => $i, 'category_id' => mt_rand(1, $n['categories']), 'name' => $title(2).' '.$i, 'sku' => sprintf('SKU-%06d', $i), 'price' => mt_rand(100, 99999),
        'stock' => mt_rand(0, 200), 'is_visible' => (int) (mt_rand(1, 10) <= 8), 'image' => 'products/p'.mt_rand(1, 40).'.png', 'images' => json_encode($images),
        'summary' => '**'.$title(2).'** '.$title(10).' _'.$title(2).'_', 'description' => $description,
        'specs' => json_encode(['weight' => mt_rand(1, 5000), 'colour' => $pick($words), 'dimensions' => [mt_rand(1, 99), mt_rand(1, 99), mt_rand(1, 99)]], JSON_PRETTY_PRINT),
        'created_at' => $at, 'updated_at' => $at];
}
$insert('products', $rows);

$prices = array_column($rows, 'price', 'id');
$orderStatuses = ['new', 'processing', 'shipped', 'delivered', 'delivered', 'delivered', 'cancelled'];
[$orders, $items, $itemId] = [[], [], 0];
for ($i = 1; $i <= $n['orders']; $i++) {
    $placed = $date(730);
    $status = $i === 1 ? 'processing' : $pick($orderStatuses);
    $count = $i === 1 ? SHOWCASE_ITEMS : mt_rand(1, 5);
    // the showcase order's items are distinct products, so disabling options picked in sibling items has work to do
    $products = $i === 1 ? range(1, SHOWCASE_ITEMS) : array_map(fn () => mt_rand(1, $n['products']), range(1, $count));
    $total = 0;
    foreach ($products as $sort => $product) {
        $qty = mt_rand(1, 4);
        $total += $qty * $prices[$product];
        $items[] = ['id' => ++$itemId, 'order_id' => $i, 'product_id' => $product, 'sort' => $sort + 1, 'qty' => $qty, 'unit_price' => $prices[$product]];
    }
    $orders[] = ['id' => $i, 'customer_id' => mt_rand(1, $n['customers']), 'number' => sprintf('ORD-%07d', $i), 'status' => $status, 'total' => $total, 'currency' => 'USD',
        'notes' => mt_rand(1, 4) === 1 ? $title(6) : null, 'placed_at' => $placed,
        'shipped_at' => in_array($status, ['shipped', 'delivered']) ? gmdate('Y-m-d H:i:s', strtotime($placed.' UTC') + mt_rand(1, 6) * 86400) : null, 'created_at' => $placed, 'updated_at' => $placed];
    if (count($orders) >= 1000) {
        $insert('orders', $orders);
        $insert('order_items', $items);
        [$orders, $items] = [[], []];
    }
}
$insert('orders', $orders);
$insert('order_items', $items);

$rows = [];
for ($i = 1; $i <= $n['tags']; $i++) {
    $name = $title(1).'-'.$i;
    $rows[] = ['id' => $i, 'name' => json_encode(['en' => $name]), 'slug' => json_encode(['en' => strtolower($name)]), 'type' => null, 'order_column' => $i,
        'created_at' => gmdate('Y-m-d H:i:s', EPOCH), 'updated_at' => gmdate('Y-m-d H:i:s', EPOCH)];
}
$insert('tags', $rows);

$rows = [];
for ($p = 1; $p <= $n['products']; $p++) {
    $tags = $p === 1 ? range(1, min(20, $n['tags'])) : array_unique(array_map(fn () => mt_rand(1, min(200, $n['tags'])), range(1, mt_rand(0, 4))));
    foreach ($tags as $tag) {
        $rows[] = ['tag_id' => $tag, 'taggable_type' => 'App\\Models\\Product', 'taggable_id' => $p];
    }
}
$insert('taggables', $rows);

// Filament database notifications for the admin, half of them unread
$rows = [];
for ($i = 1; $i <= $n['notifications']; $i++) {
    $at = gmdate('Y-m-d H:i:s', EPOCH - $i * 3600);
    $rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'type' => 'Filament\\Notifications\\DatabaseNotification', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1,
        'data' => json_encode(['actions' => [], 'body' => $title(10), 'color' => null, 'duration' => 'persistent', 'icon' => 'heroicon-o-bell', 'iconColor' => 'gray', 'status' => null,
            'title' => 'Notification '.$i, 'view' => 'filament-notifications::notification', 'viewData' => [], 'format' => 'filament']),
        'read_at' => $i % 2 ? null : $at, 'created_at' => $at, 'updated_at' => $at];
}
$insert('notifications', $rows);

$pdo->commit();
$pdo->exec('VACUUM');

// the files the media disk serves (a 1×1 PNG; nothing reads more than its existence and type)
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
foreach (['avatars' => ['a', 40], 'products' => ['p', 40], 'attachments' => ['r', 10]] as $dir => [$prefix, $count]) {
    @mkdir("$root/storage/app/media/$dir", 0777, true);
    for ($k = 1; $k <= $count; $k++) {
        file_put_contents("$root/storage/app/media/$dir/$prefix$k.png", $png);
    }
}

$panel = '/admin';
$manifest = [
    'config' => ['size' => $size, 'seed' => $seed] + array_intersect_key($opts, $n),
    'counts' => $n + ['order_items' => $itemId],
    'fingerprint' => hash_final($fingerprint),
    'user' => 'admin@bench.test',
    'ids' => ['order' => 1, 'product' => 1, 'customer' => 1],
    // name => [url, expected status, signed in]
    'pages' => [
        'ping' => ['/ping', 200, false],
        'login' => ["$panel/login", 200, false],
        'dashboard' => [$panel, 200, true],
        'customers_index' => ["$panel/customers", 200, true],
        'products_index' => ["$panel/products", 200, true],
        'orders_index' => ["$panel/orders", 200, true],
        'customer_edit' => ["$panel/customers/1/edit", 200, true],
        'order_edit' => ["$panel/orders/1/edit", 200, true],
        'order_view' => ["$panel/orders/1", 200, true],
        'product_edit' => ["$panel/products/1/edit", 200, true],
        'big_form' => ["$panel/big-form", 200, true],
        'not_found' => ["$panel/orders/999999999/edit", 404, true],
    ],
    // name => [page the component is on, component name, updates, calls]: one Livewire round trip after the page has loaded
    'livewire' => [
        'customers_search' => ["$panel/customers", 'App\\Filament\\Resources\\Customers\\Pages\\ListCustomers', ['tableSearch' => 'ada'], []],
        'customers_search_words' => ["$panel/customers", 'App\\Filament\\Resources\\Customers\\Pages\\ListCustomers', ['tableSearch' => 'ada chen silva'], []],
        'customers_filter_country' => ["$panel/customers", 'App\\Filament\\Resources\\Customers\\Pages\\ListCustomers', ['tableDeferredFilters.country.value' => '1'], [['method' => 'applyTableFilters', 'params' => []]]],
        'customers_page_2' => ["$panel/customers", 'App\\Filament\\Resources\\Customers\\Pages\\ListCustomers', [], [['method' => 'gotoPage', 'params' => [2, 'page']]]],
        'customers_sort' => ["$panel/customers", 'App\\Filament\\Resources\\Customers\\Pages\\ListCustomers', [], [['method' => 'sortTable', 'params' => ['balance']]]],
        'orders_page_2' => ["$panel/orders", 'App\\Filament\\Resources\\Orders\\Pages\\ListOrders', [], [['method' => 'gotoPage', 'params' => [2, 'page']]]],
        'order_edit_live' => ["$panel/orders/1/edit", 'App\\Filament\\Resources\\Orders\\Pages\\EditOrder', ['data.status' => 'shipped'], []],
        'big_form_live' => ["$panel/big-form", 'App\\Filament\\Pages\\BigForm', ['data.mode' => 'most'], []],
        'big_form_live_first' => ["$panel/big-form", 'App\\Filament\\Pages\\BigForm', ['data.field_1' => 'changed'], []],
        'big_form_live_last' => ["$panel/big-form", 'App\\Filament\\Pages\\BigForm', ['data.field_200' => 'changed'], []],
        // the requests every dashboard visit makes after the page, one per lazy component
        'stats_lazy_load' => [$panel, 'App\\Filament\\Widgets\\ShopStats', [], [['method' => '__lazyLoad', 'params' => []]]],
        'chart_lazy_load' => [$panel, 'App\\Filament\\Widgets\\OrdersChart', [], [['method' => '__lazyLoad', 'params' => []]]],
        'notifications_lazy_load' => [$panel, 'Filament\\Livewire\\DatabaseNotifications', [], [['method' => '__lazyLoad', 'params' => []]]],
        'stats_poll' => [$panel, 'App\\Filament\\Widgets\\ShopStats', [], [['method' => '$refresh', 'params' => []]]],
        'chart_poll' => [$panel, 'App\\Filament\\Widgets\\OrdersChart', [], [['method' => 'updateChartData', 'params' => []]]],
        'notifications_poll' => [$panel, 'Filament\\Livewire\\DatabaseNotifications', [], [['method' => '$refresh', 'params' => []]]],
    ],
];
file_put_contents("$root/bench/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['counts' => $manifest['counts'], 'fingerprint' => $manifest['fingerprint']])."\n";
