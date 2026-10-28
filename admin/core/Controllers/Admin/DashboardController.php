<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\FormSubmission;
use PDO;

class DashboardController
{
    public function index(array $vars = []): void
    {
        Auth::requireAuth();

        $user = Auth::user();
        $pdo  = Database::getInstance();

        // Live entry counts per type. Trash is excluded — a card reading "12
        // News" that counts three deleted ones is a screen telling its owner
        // something untrue about their own site.
        $countRows = $pdo->query(
            "SELECT post_type, COUNT(*) AS cnt
             FROM posts WHERE status != 'trash' GROUP BY post_type"
        )->fetchAll(PDO::FETCH_KEY_PAIR);  // ['news' => 3, 'event' => 1, ...]

        // ONE CARD PER REGISTERED CONTENT TYPE, FROM THE REGISTRY.
        // Not from `ContentTypeDefinition::all()` — that answers the narrower
        // question "what is in the content_types table", and handing that to a
        // surface labelled "what is in this install" would tell the owner they
        // have no content types while five are working in the sidebar.
        $stats = [];
        foreach (Registry::all() as $key => $cpt) {
            $stats[] = [
                'label'        => $cpt['label'],
                'label_single' => $cpt['label_single'],
                'admin_slug'   => $cpt['admin_slug'],
                'menu_icon'    => $cpt['menu_icon'],
                'count'        => (int) ($countRows[$key] ?? 0),
            ];
        }

        // Pages are hierarchical and standalone — not a Content Type, so they do
        // not come from the Registry and get their own count.
        $pageCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM pages WHERE status != 'trash'"
        )->fetchColumn();

        // Unread form submissions — the one thing on this screen that is asking
        // for something back. Someone filled in a form and nobody has looked.
        // None when the forms module is switched off: the alert's one action
        // is the inbox, which is not there (`Helpers\Modules`).
        $unreadSubmissions = \Cms\Helpers\Modules::enabled('forms') ? FormSubmission::countAllUnread() : 0;

        // Recent activity, trash excluded for the same reason as the counts.
        // `updated_at`, not `created_at`: this answers "what was I last working
        // on", and an entry edited this morning that was created in March
        // belongs at the top.
        $recentPosts = $pdo->query(
            "SELECT p.id, p.title, p.post_type, p.status, p.slug, p.updated_at,
                    " . \Cms\Models\User::nameSql('u') . " AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.status != 'trash'
             ORDER BY p.updated_at DESC
             LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);

        require ROOT_PATH . '/admin/templates/dashboard.php';
    }
}
