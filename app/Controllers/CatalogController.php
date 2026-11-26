<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Catalog;
use App\Services\Images;
use App\Support\Request;

final class CatalogController
{
    public static function shops(Request $q): array
    {
        [$page, $per, $offset] = $q->page(20);
        [$rows, $total] = Catalog::shops(mb_substr($q->query('q'), 0, 100), mb_substr($q->query('category'), 0, 100), $q->query('open') === '1', $per, $offset, max(0, (int) $q->query('food_court_id')));
        return [$rows, 200, ['page' => $page, 'per_page' => $per, 'total' => $total]];
    }

    public static function foodCourts(Request $q): array
    {
        return [Catalog::foodCourts(), 200];
    }

    public static function categories(Request $q): array
    {
        return [Catalog::categories(), 200];
    }

    public static function shop(Request $q, int $id): array
    {
        return [Catalog::shop($id), 200];
    }

    public static function menus(Request $q, int $id): array
    {
        Catalog::shop($id);
        return [Catalog::menus($id), 200];
    }

    public static function image(Request $q, string $key): ?array
    {
        Images::output($key);
        return null;
    }
}
