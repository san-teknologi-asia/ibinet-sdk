<?php

namespace Ibinet\Services\Dashboard;

use Ibinet\Models\UserDashboardLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The endpoints behind the shared, per-user dashboard board.
 *
 * Mix into an app's DashboardController and implement dashboardCatalog().
 * The app keeps its own routes and page gate; the board itself lives in the
 * `ibinet::dashboard.board` view.
 *
 * Routes the board expects, under the app's own name prefix:
 *   GET    dashboard/widgets  -> dashboardWidgets      {prefix}dashboard.widgets
 *   PUT    dashboard/layout   -> dashboardSaveLayout   {prefix}dashboard.layout
 *   DELETE dashboard/layout   -> dashboardResetLayout  {prefix}dashboard.layout.reset
 */
trait InteractsWithDashboard
{
    abstract protected function dashboardCatalog(): WidgetCatalog;

    /**
     * Page-level gate shared by the page and every endpoint. Override to
     * abort when the role may not open the dashboard at all.
     */
    protected function authorizeDashboard(): void
    {
        //
    }

    /**
     * Everything the board view needs: the user's layout, the widgets their
     * role can add, and where to send changes.
     *
     * @param  string  $routePrefix  The app's route name prefix, e.g. 'secure.'.
     */
    protected function dashboardBoard(string $routePrefix = ''): array
    {
        $catalog = $this->dashboardCatalog();
        $saved = $this->savedLayout($catalog);

        return [
            'application' => $catalog->application(),
            'layout' => $saved ?? $catalog->defaultLayout(),
            'customised' => $saved !== null,
            'picker' => $catalog->picker(),
            'urls' => [
                'widgets' => route($routePrefix . 'dashboard.widgets'),
                'layout' => route($routePrefix . 'dashboard.layout'),
                'reset' => route($routePrefix . 'dashboard.layout.reset'),
            ],
            'csrf' => csrf_token(),
        ];
    }

    /**
     * Figures for the requested widgets, keyed by widget. Keys the role may
     * not see come back as an error payload rather than data.
     */
    public function dashboardWidgets(Request $request): JsonResponse
    {
        $this->authorizeDashboard();

        $request->validate([
            'keys' => 'required|array|max:50',
            'keys.*' => 'string|max:100',
        ]);

        $catalog = $this->dashboardCatalog()->fresh($request->boolean('refresh'));

        $data = [];
        foreach (array_unique($request->input('keys')) as $key) {
            $data[$key] = $catalog->data($key);
        }

        return response()->json([
            'data' => $data,
            'generated_at' => now()->format('d M Y H:i'),
        ]);
    }

    /**
     * Store the signed-in user's layout. Only ever touches their own row.
     */
    public function dashboardSaveLayout(Request $request): JsonResponse
    {
        $this->authorizeDashboard();

        $request->validate([
            'layout' => 'present|array|max:50',
        ]);

        $catalog = $this->dashboardCatalog();
        $layout = $catalog->sanitize($request->input('layout', []));

        UserDashboardLayout::updateOrCreate(
            ['user_id' => auth()->id(), 'application' => $catalog->application()],
            ['layout' => $layout]
        );

        return response()->json(['message' => 'Layout saved', 'layout' => $layout]);
    }

    /**
     * Forget the user's layout so they fall back to the default for their role.
     */
    public function dashboardResetLayout(): JsonResponse
    {
        $this->authorizeDashboard();

        $catalog = $this->dashboardCatalog();

        UserDashboardLayout::where('user_id', auth()->id())
            ->where('application', $catalog->application())
            ->delete();

        return response()->json(['message' => 'Layout reset', 'layout' => $catalog->defaultLayout()]);
    }

    /**
     * The user's saved layout, cleaned against the catalog and their current
     * permissions. Null when they have never customised this dashboard.
     */
    private function savedLayout(WidgetCatalog $catalog): ?array
    {
        $row = UserDashboardLayout::where('user_id', auth()->id())
            ->where('application', $catalog->application())
            ->first();

        if (!$row) {
            return null;
        }

        return $catalog->sanitize(is_array($row->layout) ? $row->layout : []);
    }
}
