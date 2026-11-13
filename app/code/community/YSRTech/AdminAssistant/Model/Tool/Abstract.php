<?php
/**
 * A group of look-ups the assistant can make, all behind one ACL node.
 *
 * Every tool here reads and nothing more. The registry runs them inside a
 * read-only database transaction as well, so a tool cannot write even by
 * mistake, and nothing a tool returns can lead to a write: there are no
 * tools that write. (Phase 2 actions will go through a confirm card the
 * person clicks, never through a tool result.)
 */
abstract class YSRTech_AdminAssistant_Model_Tool_Abstract
{
    /** The node under ysrtech_adminassistant/tools/ */
    protected string $_acl = '';

    /** Rows returned by a list, unless the model asks for fewer */
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /**
     * @return array<string, array{description:string, parameters:array}>
     */
    abstract public function definitions(): array;

    /**
     * @return array{data:mixed, rows:int}
     */
    abstract public function run(string $name, array $args): array;

    public function aclResource(): string
    {
        return 'ysrtech_adminassistant/tools/' . $this->_acl;
    }

    /**
     * Whether this group applies to this store at all (Email Campaigns'
     * tools need that module).
     */
    public function available(): bool
    {
        return true;
    }

    protected function _read(): Varien_Db_Adapter_Interface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    protected function _table(string $name): string
    {
        return Mage::getSingleton('core/resource')->getTableName($name);
    }

    protected function _limit(array $args, int $default = self::DEFAULT_LIMIT): int
    {
        $limit = (int) ($args['limit'] ?? $default);

        return max(1, min(self::MAX_LIMIT, $limit ?: $default));
    }

    protected function _h(): YSRTech_AdminAssistant_Helper_Data
    {
        return Mage::helper('ysrtech_adminassistant');
    }

    /**
     * The JSON-schema fragment for a period, shared by every tool that takes one.
     */
    public static function periodSchema(): array
    {
        return [
            'period' => [
                'type'        => 'string',
                'enum'        => ['today', 'yesterday', 'this_week', 'last_week', 'last_7_days', 'this_month', 'last_month', 'last_30_days', 'last_90_days', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'last_365_days', 'all_time'],
                'description' => 'A named period in the store\'s timezone. Weeks start on Monday. Ignored when from/to are given.',
            ],
            'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD, store timezone, inclusive'],
            'to'   => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD, store timezone, inclusive'],
        ];
    }

    public static function timezone(): DateTimeZone
    {
        $name = (string) Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE);

        try {
            return new DateTimeZone($name ?: 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * A period as UTC bounds for a created_at column, plus the local dates it
     * covers, for the answer to state.
     *
     * @param  string|null $now For tests: "now", in the store's timezone
     * @return array{from_utc:?string, to_utc:?string, label:string, first_day:?string, last_day:?string}
     */
    public static function range(array $args, string $default = 'last_30_days', ?string $now = null): array
    {
        $tz    = self::timezone();
        $today = new DateTimeImmutable($now ?? 'now', $tz);
        $today = $today->setTime(0, 0);
        $valid = static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && DateTimeImmutable::createFromFormat('!Y-m-d', $d, $tz);

        if ($valid($args['from'] ?? null) || $valid($args['to'] ?? null)) {
            $start = $valid($args['from'] ?? null) ? new DateTimeImmutable($args['from'], $tz) : null;
            $last  = $valid($args['to'] ?? null) ? new DateTimeImmutable($args['to'], $tz) : $today;
            $label = ($start ? $start->format('Y-m-d') : 'the beginning') . ' to ' . $last->format('Y-m-d');

            return self::_bounds($start, $last->modify('+1 day'), $label);
        }

        $period = (string) ($args['period'] ?? $default);
        $dow    = (int) $today->format('N'); // 1 = Monday
        $month  = $today->modify('first day of this month');
        $qStart = $today->setDate((int) $today->format('Y'), (int) (floor(((int) $today->format('n') - 1) / 3) * 3 + 1), 1);
        $year   = $today->setDate((int) $today->format('Y'), 1, 1);

        switch ($period) {
            case 'today':         [$s, $e] = [$today, $today->modify('+1 day')]; break;
            case 'yesterday':     [$s, $e] = [$today->modify('-1 day'), $today]; break;
            case 'this_week':     [$s, $e] = [$today->modify('-' . ($dow - 1) . ' days'), $today->modify('+1 day')]; break;
            case 'last_week':     $s = $today->modify('-' . ($dow + 6) . ' days'); $e = $s->modify('+7 days'); break;
            case 'last_7_days':   [$s, $e] = [$today->modify('-6 days'), $today->modify('+1 day')]; break;
            case 'this_month':    [$s, $e] = [$month, $today->modify('+1 day')]; break;
            case 'last_month':    [$s, $e] = [$month->modify('-1 month'), $month]; break;
            case 'last_90_days':  [$s, $e] = [$today->modify('-89 days'), $today->modify('+1 day')]; break;
            case 'this_quarter':  [$s, $e] = [$qStart, $today->modify('+1 day')]; break;
            case 'last_quarter':  [$s, $e] = [$qStart->modify('-3 months'), $qStart]; break;
            case 'this_year':     [$s, $e] = [$year, $today->modify('+1 day')]; break;
            case 'last_year':     [$s, $e] = [$year->modify('-1 year'), $year]; break;
            case 'last_365_days': [$s, $e] = [$today->modify('-364 days'), $today->modify('+1 day')]; break;
            case 'all_time':      return ['from_utc' => null, 'to_utc' => null, 'label' => 'all time', 'first_day' => null, 'last_day' => null];
            default:              [$s, $e] = [$today->modify('-29 days'), $today->modify('+1 day')]; $period = 'last_30_days';
        }

        return self::_bounds($s, $e, str_replace('_', ' ', $period));
    }

    private static function _bounds(?DateTimeImmutable $start, DateTimeImmutable $endExclusive, string $label): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'from_utc'  => $start ? $start->setTimezone($utc)->format('Y-m-d H:i:s') : null,
            'to_utc'    => $endExclusive->setTimezone($utc)->format('Y-m-d H:i:s'),
            'label'     => $label,
            'first_day' => $start ? $start->format('Y-m-d') : null,
            'last_day'  => $endExclusive->modify('-1 day')->format('Y-m-d'),
        ];
    }

    protected function _applyRange(Varien_Db_Select $select, string $column, array $range): void
    {
        if ($range['from_utc'] !== null) {
            $select->where("{$column} >= ?", $range['from_utc']);
        }

        if ($range['to_utc'] !== null) {
            $select->where("{$column} < ?", $range['to_utc']);
        }
    }

    /**
     * A UTC datetime from the database, shown in the store's timezone.
     */
    public static function local(?string $utc): ?string
    {
        if ($utc === null || $utc === '' || strncmp($utc, '0000', 4) === 0) {
            return null;
        }

        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::timezone())->format('Y-m-d H:i');
    }

    /**
     * The SQL expression for a UTC column's local calendar day, using the
     * store timezone's offset now. (Good enough for grouping; a day either
     * side of a clock change can shift by an hour.)
     */
    protected function _localDayExpr(string $column, string $format = '%Y-%m-%d'): Zend_Db_Expr
    {
        $offset = (new DateTime('now', self::timezone()))->getOffset();

        return new Zend_Db_Expr(sprintf("DATE_FORMAT(DATE_ADD(%s, INTERVAL %d SECOND), '%s')", $column, $offset, $format));
    }

    protected function _money($amount): float
    {
        return round((float) $amount, 2);
    }

    protected function _like(string $text): string
    {
        return '%' . addcslashes($text, '%_\\') . '%';
    }

    protected function _adminUrl(string $route, array $params): string
    {
        // An admin URL needs the admin session; a command-line run has none
        if (PHP_SAPI === 'cli') {
            return '';
        }

        try {
            return Mage::helper('adminhtml')->getUrl($route, $params);
        } catch (Throwable $e) {
            return '';
        }
    }
}
