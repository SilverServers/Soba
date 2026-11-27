<?php

declare(strict_types=1);

namespace Cms\Controllers\Api;

use Cms\Auth\Auth;
use Cms\Content\Presence;
use Cms\Helpers\Dates;
use Cms\Http\Cache;
use Cms\Security\Csrf;
use Cms\Ui\KeepWriting;

/**
 * STILL SIGNED IN? — `GET /api/v1/session`, asked by the page and entry
 * editors while somebody writes (`keep-writing.js`).
 *
 * A sign-in ends after a while with no request (`Auth`'s idle window), and
 * typing is not a request, so an evening's writing outlived it and was lost
 * at Save. Asking is itself a request: it keeps the sign-in, as any page
 * would. The editors ask only while keys are being pressed, so a screen left
 * open and unattended still signs out on time.
 *
 * Asked by an editor, it also says who else has that page or entry open and
 * whether somebody else saved it since the screen was drawn
 * (`Content\Presence`).
 *
 * Signed out, the answer is the API's own 401, which the editor reads as "the
 * sign-in has ended" and says so before Save is pressed. Signed in again in
 * another tab, the answer carries this session's form token, so the editor's
 * Save works without the page being drawn again.
 */
final class SessionController
{
    public function show(array $vars = []): void
    {
        Auth::requireAuth();

        $data = ['token' => Csrf::token()];

        // AN EDITOR ASKING (`?editing=page:12&opened=…`, `Content\Presence`):
        // the person is writing in it, so they are kept in it, and the answer
        // says who else is, and whether somebody else has saved it since the
        // screen was drawn.
        $object = self::object($_GET['editing'] ?? null);
        if ($object !== null) {
            [$type, $id] = $object;
            $me     = (int) (Auth::user()['id'] ?? 0);
            $opened = is_string($_GET['opened'] ?? null) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $_GET['opened']) === 1
                ? $_GET['opened'] : gmdate('Y-m-d H:i:s');
            Presence::writing($type, $id, $me);
            $saved = Presence::savedSince($type, $id, $me, $opened);
            $data['editing'] = [
                'others' => KeepWriting::told(Presence::others($type, $id, $me)),
                'saved'  => $saved === null ? null : ['name' => $saved['name'], 'at' => Dates::stamp($saved['at'])],
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/v1/session/leave — the editor is being left (`keep-writing.js`
     * sends it as the page goes), so its person is out of it at once.
     */
    public function leave(array $vars = []): void
    {
        Auth::requireAuth();

        $object = self::object($_POST['editing'] ?? null);
        if ($object !== null) {
            Presence::leave($object[0], $object[1], (int) (Auth::user()['id'] ?? 0));
        }
        http_response_code(204);
    }

    /**
     * "page:12" or "post:34", for a page or an entry this person may change;
     * null for anything else.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function object(mixed $raw): ?array
    {
        if (!is_string($raw) || preg_match('/^(page|post):(\d{1,10})$/', $raw, $m) !== 1) {
            return null;
        }
        $id = (int) $m[2];
        if ($m[1] === 'page') {
            return Auth::hasRole('editor') && \Cms\Models\Page::find($id) !== null ? ['page', $id] : null;
        }
        $post = \Cms\Models\Post::find($id);
        return $post !== null && Auth::canChange($post) ? ['post', $id] : null;
    }
}
