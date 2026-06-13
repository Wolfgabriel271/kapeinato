<?php
// ============================================================
// helpers.php — Shared utilities (Fix #7, Fix #9)
// ============================================================

if (!defined('KAPE_BOOTSTRAPPED')) {
    define('KAPE_BOOTSTRAPPED', true);
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/**
 * Fix #7 — Remote fallback URLs per category (used when no local upload exists).
 */
function get_category_default_urls(): array
{
    return [
        'Pizza'      => 'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=600&q=80',
        'Pasta'      => 'https://images.unsplash.com/photo-1621996346565-e3dbc646d9a9?w=600&q=80',
        'Drinks'     => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=600&q=80',
        'Appetizers' => 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=600&q=80',
    ];
}

/**
 * Fix #7 — Local default filename stored in DB when no custom image is uploaded.
 */
function get_category_default_filename(string $category): string
{
    $map = [
        'Pizza'      => 'default_pizza.jpg',
        'Pasta'      => 'default_pasta.jpg',
        'Drinks'     => 'default_drinks.jpg',
        'Appetizers' => 'default_appetizers.jpg',
    ];

    return $map[$category] ?? 'default.jpg';
}

/**
 * True for shared placeholder filenames — never delete these from uploads/.
 */
function is_default_menu_image(string $image_path): bool
{
    if ($image_path === 'default.jpg') {
        return true;
    }

    return (bool) preg_match('/^default(_[a-z]+)?\.(jpg|jpeg|png|webp|svg)$/i', $image_path);
}

/**
 * Fix #7 — Returns a display-ready image path/URL for a category default.
 */
function getCategoryDefaultImg(string $category): string
{
    $local = get_category_default_filename($category);
    $local_path = __DIR__ . '/uploads/' . $local;

    if (file_exists($local_path)) {
        return 'uploads/' . $local;
    }

    $global = __DIR__ . '/uploads/default.jpg';
    if (file_exists($global)) {
        return 'uploads/default.jpg';
    }

    $urls = get_category_default_urls();

    return $urls[$category]
        ?? 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=600&q=80';
}

/**
 * Fix #7 — Resolve the image to show for a menu item row.
 */
function resolveMenuItemImage(string $image_path, string $category, ?string $item_name = null, array $overrides = []): string
{
    if ($item_name !== null && isset($overrides[$item_name])) {
        return $overrides[$item_name];
    }

    if (str_starts_with($image_path, 'http://') || str_starts_with($image_path, 'https://')) {
        return $image_path;
    }

    $upload_path = __DIR__ . '/uploads/' . $image_path;
    if (file_exists($upload_path) && !is_default_menu_image($image_path)) {
        return 'uploads/' . $image_path;
    }

    return getCategoryDefaultImg($category);
}

/**
 * Fix #9 — Log DB errors server-side; return a safe user-facing message.
 */
function db_error_message(mysqli $conn, string $context = 'operation'): string
{
    error_log('[Kape Inato] DB error during ' . $context . ': ' . $conn->error);

    return 'Something went wrong. Please try again in a moment.';
}
