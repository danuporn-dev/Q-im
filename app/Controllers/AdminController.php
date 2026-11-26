<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Admin;
use App\Services\Auth;
use App\Services\OnlinePayments;
use App\Services\Reports;
use App\Services\Reviews;
use App\Support\Input;
use App\Support\Request;
use App\Support\Router;

final class AdminController
{
    public static function routes(Router $r): void
    {
        $r->add('GET', '/admin/overview', self::read(static fn (): array => Admin::overview()));
        $r->add('GET', '/admin/settings', self::read(static fn (): array => Admin::settings()));
        $r->add('GET', '/admin/food-courts', self::read(static fn (): array => Admin::foodCourts()));
        $r->add('GET', '/admin/orders/{id}', self::read(static fn (Request $q, int $id): array => Admin::order($id)));
        $r->add('GET', '/admin/reports', self::read(static function (Request $q): array {
            $summary = Reports::summary($q->query('from'), $q->query('to'));
            return $summary + Reports::breakdown($summary['from'], $summary['to']);
        }));

        $r->add('GET', '/admin/shops', self::listing(static fn (Request $q, int $n, int $o): array => Admin::shops($q->query('q'), $n, $o)));
        $r->add('GET', '/admin/users', self::listing(static fn (Request $q, int $n, int $o): array => Admin::users($q->query('q'), $q->query('role'), $n, $o)));
        $r->add('GET', '/admin/orders', self::listing(static fn (Request $q, int $n, int $o): array => Admin::orders($q->query('q'), $q->query('status'), (int) $q->query('shop_id'), $n, $o)));
        $r->add('GET', '/admin/audit-logs', self::listing(static fn (Request $q, int $n, int $o): array => Admin::auditLogs($q->query('q'), mb_substr($q->query('action'), 0, 100), $n, $o)));

        $r->add('GET', '/admin/reviews', self::listing(static fn (Request $q, int $n, int $o): array => Reviews::all('%' . addcslashes(mb_substr($q->query('q'), 0, 100), '\\%_') . '%', (int) $q->query('shop_id'), $n, $o)));
        $r->add('PATCH', '/admin/reviews/{id}', self::write(static fn (int $me, array $in, int $id) => Reviews::setHidden($me, $id, $in)));
        // Online payments to give back by hand, and payments that did not match their order (D42)
        $r->add('GET', '/admin/refunds', self::read(static fn (): array => OnlinePayments::refunds(null, true)));
        $r->add('POST', '/admin/refunds/{id}/done', self::write(static function (int $me, array $in, int $id): array {
            OnlinePayments::markRefunded($me, $id, null, Input::optionalText($in, 'note', 'บันทึก', 300));
            return OnlinePayments::refunds(null, true);
        }));

        $r->add('POST', '/admin/food-courts', self::write(static fn (int $me, array $in): array => Admin::saveFoodCourt($me, null, $in), 201));
        $r->add('PATCH', '/admin/food-courts/{id}', self::write(static fn (int $me, array $in, int $id): array => Admin::saveFoodCourt($me, $id, $in)));
        $r->add('POST', '/admin/shops', self::write(static fn (int $me, array $in): array => Admin::saveShop($me, null, $in), 201));
        $r->add('PATCH', '/admin/shops/{id}', self::write(static fn (int $me, array $in, int $id): array => Admin::saveShop($me, $id, $in)));
        $r->add('POST', '/admin/shops/{id}/members', self::write(static fn (int $me, array $in, int $id) => Admin::addMember($me, $id, $in)));
        $r->add('DELETE', '/admin/shops/{id}/members/{sub}', self::write(static fn (int $me, array $in, int $id, int $user) => Admin::removeMember($me, $id, $user)));
        $r->add('POST', '/admin/users', self::write(static fn (int $me, array $in): array => Admin::createStaff($me, $in), 201));
        $r->add('PATCH', '/admin/users/{id}', self::write(static fn (int $me, array $in, int $id) => Admin::updateUser($me, $id, $in)));
    }

    private static function read(callable $work): callable
    {
        return static function (Request $q, int ...$ids) use ($work): array {
            Auth::user($q, 'admin');
            return [$work($q, ...$ids), 200];
        };
    }

    private static function listing(callable $work): callable
    {
        return static function (Request $q) use ($work): array {
            Auth::user($q, 'admin');
            [$page, $per, $offset] = $q->page(25);
            [$rows, $total] = $work($q, $per, $offset);
            return [$rows, 200, ['page' => $page, 'per_page' => $per, 'total' => $total]];
        };
    }

    private static function write(callable $work, int $status = 200): callable
    {
        return static function (Request $q, int ...$ids) use ($work, $status): array {
            $admin = Auth::user($q, 'admin');
            return [$work($admin['id'], $q->json(), ...$ids) ?? ['ok' => true], $status];
        };
    }
}
