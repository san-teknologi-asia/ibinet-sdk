<?php

namespace Ibinet\Services\Dashboard;

use Illuminate\Support\Facades\Cache;

/**
 * Every card one app can put on a dashboard, and how to fill it.
 *
 * Each app (IDC, IFAS, IBOS) extends this with its own definitions. A
 * definition describes the card, not its data:
 *
 *   'key' => [
 *       'label'       => 'Open tickets',
 *       'description' => 'Tickets still waiting on someone.',   // picker text
 *       'group'       => 'operations',                          // key into groups()
 *       'icon'        => 'mdi mdi-ticket-outline',
 *       'color'       => 'primary',                             // bootstrap colour name
 *       'type'        => 'stat' | 'chart' | 'table',
 *       'w' => 3, 'h' => 2,                                     // starting size, 12-col grid
 *       'min_w' => 2, 'min_h' => 2,
 *       'permission'  => 'ticket.index' | ['a', 'b'] | null,    // any-of; null = everyone
 *       'default'     => true,                                  // on the starter layout
 *       'ttl'         => 60,                                    // cache seconds; default CACHE_TTL
 *   ]
 *
 * payload() turns a key into data. The shapes the shared board renders are:
 *
 *   stat:  ['value' => 12, 'format' => 'number|idr|percent|hours',
 *           'context' => 'of 40 total', 'tone' => null|'danger']
 *   chart: ['chart' => 'line|bar|hbar', 'stacked' => bool, 'ordinal' => bool,
 *           'format' => 'number|idr|hours', 'labels' => [...],
 *           'series' => [['label' => 'Raised', 'data' => [...]], ...],
 *           'readout' => [['label' => .., 'value' => .., 'series' => 0|null]],
 *           'subtitle' => 'overrides the description', 'empty' => 'No data yet']
 *   table: ['columns' => [['label' => 'Stage', 'align' => 'start|end', 'format' => 'text|number|idr']],
 *           'rows' => [[...], ...], 'links' => [url|null, ...],
 *           'footnote' => '...', 'empty' => 'Nothing here']
 *
 * Widget visibility follows the signed-in user's role permissions through
 * has(). A widget the role cannot see is left out of the picker, dropped from
 * a saved layout when it is read, and refused by the data endpoint - so
 * revoking a permission in IDC takes effect on the next page load.
 */
abstract class WidgetCatalog
{
    public const COLUMNS = 12;

    /** Seconds a widget's figures stay cached. */
    public const CACHE_TTL = 600;

    /** Application code the layouts are stored under: idc, ifas, ibos. */
    abstract public function application(): string;

    /**
     * Picker sections, in display order.
     *
     * @return array<string, string>
     */
    abstract protected function groups(): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    abstract protected function definitions(): array;

    /**
     * The figures for one widget. Only ever called for a permitted key.
     */
    abstract protected function payload(string $key): array;

    /**
     * What the cached figures are keyed by. Defaults to the user, which is
     * right for any app whose figures are scoped to the user's projects. An
     * app whose figures are the same for everyone can return a constant.
     */
    protected function cacheScope(): string
    {
        return (string) auth()->id();
    }

    /** @var array<string, array<string, mixed>>|null */
    private ?array $catalog = null;

    /** @var array<string, mixed> */
    private array $memo = [];

    private bool $fresh = false;

    /**
     * Bypass the cache for the rest of this request.
     */
    public function fresh(bool $fresh = true): static
    {
        $this->fresh = $fresh;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $this->catalog = [];

        foreach ($this->definitions() as $key => $definition) {
            $this->catalog[$key] = array_merge([
                'description' => '',
                'group' => array_key_first($this->groups()),
                'icon' => 'mdi mdi-view-dashboard-outline',
                'color' => 'primary',
                'type' => 'stat',
                'w' => 3,
                'h' => 2,
                'min_w' => 2,
                'min_h' => 2,
                'permission' => null,
                'default' => false,
                'ttl' => static::CACHE_TTL,
            ], $definition);
        }

        return $this->catalog;
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function isPermitted(string $key): bool
    {
        $definition = $this->find($key);

        if ($definition === null) {
            return false;
        }

        $permission = $definition['permission'];

        if ($permission === null) {
            return true;
        }

        foreach ((array) $permission as $required) {
            if (has($required)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The catalog trimmed to the signed-in user's role.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(): array
    {
        return array_filter(
            $this->all(),
            fn ($key) => $this->isPermitted($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Picker sections with the widgets this user may add, empty ones dropped.
     *
     * @return array<int, array{label: string, widgets: array<int, array<string, mixed>>}>
     */
    public function picker(): array
    {
        $sections = [];

        foreach ($this->groups() as $groupKey => $label) {
            $sections[$groupKey] = ['label' => $label, 'widgets' => []];
        }

        foreach ($this->available() as $key => $definition) {
            $group = isset($sections[$definition['group']]) ? $definition['group'] : array_key_first($sections);
            $sections[$group]['widgets'][] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'color' => $definition['color'],
                'type' => $definition['type'],
                'w' => $definition['w'],
                'h' => $definition['h'],
                'min_w' => $definition['min_w'],
                'min_h' => $definition['min_h'],
            ];
        }

        return array_values(array_filter($sections, fn ($section) => $section['widgets'] !== []));
    }

    /**
     * The starter dashboard: every permitted default widget, packed left to
     * right in catalog order and wrapped at 12 columns.
     *
     * @return array<int, array{key: string, x: int, y: int, w: int, h: int}>
     */
    public function defaultLayout(): array
    {
        $layout = [];
        $x = 0;
        $y = 0;
        $rowHeight = 0;

        foreach ($this->available() as $key => $definition) {
            if (!$definition['default']) {
                continue;
            }

            $w = min(self::COLUMNS, (int) $definition['w']);
            $h = (int) $definition['h'];

            if ($x + $w > self::COLUMNS) {
                $x = 0;
                $y += $rowHeight;
                $rowHeight = 0;
            }

            $layout[] = ['key' => $key, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];

            $x += $w;
            $rowHeight = max($rowHeight, $h);
        }

        return $layout;
    }

    /**
     * Clean a layout from storage or from the browser: unknown keys, widgets
     * this role may not see, duplicates and out-of-grid positions are dropped
     * or clamped. Nothing posted by the client is trusted as-is.
     *
     * @param  array<int, mixed>  $layout
     * @return array<int, array{key: string, x: int, y: int, w: int, h: int}>
     */
    public function sanitize(array $layout): array
    {
        $clean = [];
        $seen = [];

        foreach ($layout as $item) {
            if (!is_array($item) || !isset($item['key']) || !is_string($item['key'])) {
                continue;
            }

            $key = $item['key'];

            if (isset($seen[$key]) || !$this->isPermitted($key)) {
                continue;
            }

            $definition = $this->find($key);
            $w = max((int) $definition['min_w'], min(self::COLUMNS, (int) ($item['w'] ?? $definition['w'])));
            $h = max((int) $definition['min_h'], min(12, (int) ($item['h'] ?? $definition['h'])));
            $x = max(0, min(self::COLUMNS - $w, (int) ($item['x'] ?? 0)));
            $y = max(0, min(500, (int) ($item['y'] ?? 0)));

            $clean[] = ['key' => $key, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
            $seen[$key] = true;
        }

        return $clean;
    }

    /**
     * Cached figures for one widget. A widget that throws reports the error
     * and comes back as an error payload, so one broken query never takes the
     * rest of the dashboard down with it.
     */
    public function data(string $key): array
    {
        if (!$this->isPermitted($key)) {
            return ['error' => 'You do not have access to this widget.'];
        }

        $cacheKey = $this->cacheKey($key);

        try {
            if ($this->fresh) {
                Cache::forget($cacheKey);
            }

            $ttl = (int) $this->find($key)['ttl'];

            // A zero TTL is for figures that must be live, such as a queue
            // the viewer is working through.
            $payload = $ttl > 0
                ? Cache::remember($cacheKey, $ttl, fn () => $this->payload($key))
                : $this->payload($key);

            return $this->personalise($key, $payload);
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'This widget could not be loaded.'];
        }
    }

    /**
     * Per-viewer touches applied after the cache, e.g. dropping links to pages
     * the viewer's role cannot open. Anything that depends on who is looking
     * belongs here, never in payload(), whose result may be shared.
     */
    protected function personalise(string $key, array $payload): array
    {
        return $payload;
    }

    /**
     * Share one expensive computation between several widgets, e.g. a headline
     * query that feeds four stat cards. Cached like a widget and memoised for
     * the request, so it runs at most once per page load.
     */
    protected function shared(string $name, callable $compute): mixed
    {
        if (array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }

        $cacheKey = $this->cacheKey('__' . $name);

        if ($this->fresh) {
            Cache::forget($cacheKey);
        }

        return $this->memo[$name] = Cache::remember($cacheKey, static::CACHE_TTL, $compute);
    }

    private function cacheKey(string $key): string
    {
        return 'dashboard.' . $this->application() . '.' . $this->cacheScope() . '.' . $key;
    }
}
