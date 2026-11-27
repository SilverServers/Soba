<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Assistant\AssistantRunner;
use Cms\Assistant\Budget;
use Cms\Assistant\ChangeSet;
use Cms\Assistant\ChangeSetApplier;
use Cms\Assistant\ShopOps;
use Cms\Assistant\ToolContext;
use Cms\Assistant\TurnProgress;
use Cms\Assistant\Transport\ClaudeTransport;
use Cms\Assistant\Transport\StubTransport;
use Cms\Assistant\Transport\Transport;
use Cms\Auth\Auth;
use Cms\ContentType\ContentTypeSchema;
use Cms\Models\AssistantSession;
use Cms\Models\Meta;
use Cms\Models\Post;
use Cms\Models\Revision;
use Cms\Http\Cache;

/**
 * The CMS Assistant — a chat surface over the read-only tool layer.
 *
 * Conversations are persisted per user (see migration 032) rather than kept in
 * $_SESSION: a chat people are expected to return to should survive a logout,
 * and a proposal belongs in the transcript that produced it.
 *
 * Proposals are stored on the message and re-validated at apply time. Nothing the
 * browser sends decides what gets applied — only the message id, which is looked
 * up server-side and scoped to the acting user.
 */
class AssistantController
{
    /**
     * Human labels for page columns in a diff.
     *
     * Pages have no field schema to read labels from, unlike content type entries,
     * so the reviewer would otherwise be shown `meta_description`.
     */
    private const PAGE_FIELD_LABELS = [
        'title'            => 'Title',
        'content'          => 'Content',
        'slug'             => 'URL slug',
        'status'           => 'Status',
        'seo_title'        => 'SEO title',
        'meta_description' => 'Meta description',
    ];

    public function index(array $vars = []): void
    {
        Auth::requireAuth();

        $user      = Auth::user();
        $userId    = (int) $user['id'];
        $sessions  = AssistantSession::allFor($userId);

        // Explicit session, else the most recent, else none yet.
        $sessionId = isset($vars['id']) ? (int) $vars['id'] : 0;
        $session   = $sessionId > 0
            ? AssistantSession::find($sessionId, $userId)
            : AssistantSession::latestFor($userId);

        // A deleted conversation is a dead bookmark, not a broken site — and the
        // public 404 template used to render here, complete with the site's own
        // masthead and footer, inside the admin. Say what happened and go back to
        // the assistant.
        if ($sessionId > 0 && $session === null) {
            $_SESSION['_assistant_error'] = 'That conversation no longer exists.';
            header('Location: ' . ADMIN_URL . '/assistant');
            exit;
        }

        $messages  = $session !== null ? AssistantSession::messages((int) $session['id']) : [];
        $transport = self::transport();
        $usingStub = $transport instanceof StubTransport;
        $model     = ClaudeTransport::DEFAULT_MODEL;
        $spent     = Budget::spentThisMonth($model);
        $limit     = Budget::limit();

        // Resolve each stored proposal into reviewable rows.
        $diffs = [];
        foreach ($messages as $message) {
            $changeSet = AssistantSession::changeSetOf($message);
            if ($changeSet !== null) {
                $diffs[(int) $message['id']] = [
                    'summary' => $changeSet['summary'] ?? '',
                    'rows'    => self::buildDiff($changeSet['operations'] ?? [], ($message['change_set_status'] ?? 'pending') !== 'pending'),
                    'errors'  => AssistantSession::errorsOf($message),
                    'status'  => $message['change_set_status'] ?? 'pending',
                ];
            }
        }

        $error   = $_SESSION['_assistant_error'] ?? null;
        $done    = $_SESSION['_assistant_outcome'] ?? null;
        unset($_SESSION['_assistant_error'], $_SESSION['_assistant_outcome']);

        // ── WHAT APPLYING OR UNDOING DID, IN WORDS ─────────────────────────
        // `apply()` and `undo()` leave what happened as an array, and the
        // screen printed it as a string: "Array", and a warning in the log.
        $outcome       = null;
        $outcomeErrors = is_array($done) ? array_values(array_map('strval', (array) ($done['errors'] ?? []))) : [];
        $outcomeKept   = is_array($done) ? array_values(array_map('strval', (array) ($done['kept'] ?? []))) : [];
        if (is_array($done) && !empty($done['undone'])) {
            $n       = (int) ($done['count'] ?? 0);
            $outcome = 'Undone: ' . $n . ' change' . ($n === 1 ? '' : 's') . ' put back.';
        } elseif (is_array($done) && !empty($done['applied'])) {
            $n       = count((array) ($done['results'] ?? []));
            $outcome = 'Applied: ' . $n . ' change' . ($n === 1 ? '' : 's') . '.';
        }

        $pageTitle    = 'Assistant';
        $contentClass = 'l-admin__content--full';

        require ROOT_PATH . '/admin/templates/assistant/index.php';
    }

    public function create(array $vars = []): void
    {
        Auth::requireAuth();
        $id = AssistantSession::create((int) Auth::user()['id']);
        header('Location: ' . ADMIN_URL . '/assistant/' . $id);
        exit;
    }

    public function rename(array $vars = []): void
    {
        Auth::requireAuth();
        AssistantSession::rename(
            (int) $vars['id'],
            (int) Auth::user()['id'],
            (string) ($_POST['title'] ?? '')
        );
        header('Location: ' . ADMIN_URL . '/assistant/' . (int) $vars['id']);
        exit;
    }

    public function delete(array $vars = []): void
    {
        Auth::requireAuth();
        AssistantSession::delete((int) $vars['id'], (int) Auth::user()['id']);
        header('Location: ' . ADMIN_URL . '/assistant');
        exit;
    }

    /**
     * Send a message, recording progress for the browser to watch.
     *
     * A turn takes tens of seconds because it is a loop: ask, read content, ask
     * again. Waiting for a page to come back at the end of that means staring at
     * nothing, which people reasonably read as "it's broken" — so they send again.
     *
     * This request still runs the whole turn and answers once at the end. What
     * makes the wait legible is that it also writes each event to TurnProgress as
     * it happens, and the browser reads those with `progress()` in parallel. See
     * that class for why the events are not simply streamed down this response.
     */
    public function send(array $vars = []): void
    {
        Auth::requireAuth();

        $turnId   = self::turnId();
        $userId   = (int) Auth::user()['id'];
        $prepared = self::prepareTurn((int) ($vars['id'] ?? 0));

        // Everything this request needs from the PHP session has been read, so
        // release the lock before the slow part. PHP holds it exclusively for the
        // whole request, and a 30-60s turn would otherwise block every other
        // request from the same browser behind it — including the progress polls.
        session_write_close();

        if (isset($prepared['error'])) {
            self::json(['ok' => false, 'error' => $prepared['error']], 400);
            return;
        }

        if ($prepared['message'] === '') {
            self::json(['ok' => false, 'error' => 'Nothing to send.'], 400);
            return;
        }

        $sessionId = $prepared['session_id'];
        $progress  = TurnProgress::open($userId, $turnId);
        $progress->push(['type' => 'start', 'session_id' => $sessionId]);

        // The call is billed the moment it is made, so finish and persist even if
        // the reader closes the tab.
        ignore_user_abort(true);
        set_time_limit(0);

        try {
            $result = (new AssistantRunner($prepared['transport'], $prepared['context']))->run(
                $prepared['message'],
                $prepared['history'],
                static function (array $event) use ($progress): void {
                    $progress->push($event);
                },
                $prepared['pending']
            );
        } catch (\Throwable $e) {
            error_log('[assistant] ' . $e->getMessage());
            $progress->push(['type' => 'error', 'message' => self::friendlyError($e)]);
            $progress->finish();
            self::json([
                'ok'         => false,
                'error'      => self::friendlyError($e),
                'session_id' => $sessionId,
            ], 500);
            return;
        }

        $messageId = self::storeReply($sessionId, $result);

        // A proposal has to be rendered server-side — it needs entry titles, field
        // labels and the approve form. So does a brand new conversation, which is
        // not in the rail yet and whose URL is still /assistant. Everything else
        // is finished in place, with no reload at all.
        $needsReload = $result['change_set'] instanceof ChangeSet
            || $result['validation_errors'] !== []
            || $prepared['is_new'];

        $progress->finish();

        self::json([
            'ok'         => true,
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'reload'     => $needsReload,
            'url'        => ADMIN_URL . '/assistant/' . $sessionId,
            // Rendered here rather than in JS: Markdown::render escapes first and
            // emits only tags it produced itself, and that guarantee should not be
            // reimplemented in a second language.
            'html'       => \Cms\Helpers\Markdown::render((string) $result['reply']),
            // Owner-facing labels, not the model's tool names — this list is
            // rendered straight into the transcript.
            'tools'      => array_values(array_unique(array_map(
                static fn (string $name): string => \Cms\Assistant\ToolCatalog::label($name),
                $result['tool_calls'] ?? []
            ))),
            'title'      => $prepared['renamed'],
        ]);
    }

    /**
     * What has happened so far in a turn this user started.
     *
     * Deliberately tiny and fast: it opens no API connection and touches no
     * content. The turn id only namespaces the record — the file it reads is
     * keyed by the *session's* user id, so this can never surface another user's
     * turn however the id is guessed.
     */
    public function progress(array $vars = []): void
    {
        Auth::requireAuth();

        $turnId = (string) ($_GET['turn'] ?? '');
        $from   = (int) ($_GET['from'] ?? 0);

        if ($turnId === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $turnId)) {
            self::json(['ok' => false, 'error' => 'Bad turn id.'], 400);
            return;
        }

        // Nothing below writes to the session, and holding its lock here would
        // serialise the polls against each other.
        session_write_close();

        self::json(['ok' => true] + TurnProgress::read((int) Auth::user()['id'], $turnId, $from));
    }

    /**
     * Send a message and wait for the whole turn, with no progress at all — the
     * no-JavaScript path.
     *
     * The composer is a real form and must still work with JS off, which is the
     * project's progressive-enhancement rule. Same turn, silent, ends in a
     * redirect rather than JSON.
     */
    public function sendBlocking(array $vars = []): void
    {
        Auth::requireAuth();

        $prepared = self::prepareTurn((int) ($vars['id'] ?? 0));

        if (isset($prepared['error'])) {
            $_SESSION['_assistant_error'] = $prepared['error'];
            header('Location: ' . ADMIN_URL . '/assistant' . ($prepared['session_id'] > 0 ? '/' . $prepared['session_id'] : ''));
            exit;
        }

        if ($prepared['message'] === '') {
            header('Location: ' . ADMIN_URL . '/assistant');
            exit;
        }

        session_write_close();

        try {
            $result = (new AssistantRunner($prepared['transport'], $prepared['context']))
                ->run($prepared['message'], $prepared['history'], null, $prepared['pending']);
        } catch (\Throwable $e) {
            error_log('[assistant] ' . $e->getMessage());
            session_start();
            $_SESSION['_assistant_error'] = self::friendlyError($e);
            header('Location: ' . ADMIN_URL . '/assistant/' . $prepared['session_id']);
            exit;
        }

        self::storeReply($prepared['session_id'], $result);

        header('Location: ' . ADMIN_URL . '/assistant/' . $prepared['session_id']);
        exit;
    }

    // ----------------------------------------------------------------
    // Turn plumbing, shared by send() and stream()
    // ----------------------------------------------------------------

    /**
     * Do everything that needs the PHP session, before the lock is released.
     *
     * Budget is checked before a conversation is created, so a refused turn does
     * not leave an empty conversation in the rail.
     *
     * @return array{
     *     error?: string,
     *     session_id: int,
     *     message?: string,
     *     history?: array<int, array<string, mixed>>,
     *     context?: ToolContext,
     *     transport?: Transport,
     *     is_new?: bool
     * }
     */
    private static function prepareTurn(int $sessionId): array
    {
        $userId  = (int) Auth::user()['id'];
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($sessionId > 0 && AssistantSession::find($sessionId, $userId) === null) {
            return ['error' => 'That conversation no longer exists.', 'session_id' => 0];
        }

        $transport = self::transport();

        // Only real transports cost money, so the stub is exempt — otherwise the
        // screen could not be explored offline.
        if (!$transport instanceof StubTransport) {
            $budget = Budget::check();
            if (!$budget['allowed']) {
                return ['error' => (string) $budget['reason'], 'session_id' => $sessionId];
            }
        }

        if ($message === '') {
            return ['session_id' => $sessionId, 'message' => ''];
        }

        $isNew   = $sessionId === 0;
        $renamed = null;

        if ($isNew) {
            $sessionId = AssistantSession::create($userId, AssistantSession::titleFromMessage($message));
        } else {
            // A conversation still on its default name takes its title from the
            // opening line. Report it back so a turn that finishes without a
            // reload can correct the rail, which still reads "New conversation".
            $session = AssistantSession::find($sessionId, $userId);
            if (($session['title'] ?? '') === 'New conversation') {
                $renamed = AssistantSession::titleFromMessage($message);
                AssistantSession::rename($sessionId, $userId, $renamed);
            }
        }

        $history = AssistantSession::historyFor($sessionId);

        // Read before the new message is stored, so "make Team $189 instead" can
        // amend the proposal on screen rather than open a competing one.
        $pending = AssistantSession::pendingProposal($sessionId);

        AssistantSession::addMessage($sessionId, 'user', $message);

        return [
            'session_id' => $sessionId,
            'message'    => $message,
            'history'    => $history,
            'pending'    => $pending,
            'context'    => ToolContext::fromSession(),
            'transport'  => $transport,
            'is_new'     => $isNew,
            'renamed'    => $renamed,
        ];
    }

    /**
     * Persist the assistant's turn. Returns the new message id.
     *
     * @param array<string, mixed> $result
     */
    private static function storeReply(int $sessionId, array $result): int
    {
        $changeSet = $result['change_set'] instanceof ChangeSet
            ? [
                'id'         => $result['change_set']->id,
                'summary'    => $result['change_set']->summary,
                'operations' => $result['change_set']->operations,
            ]
            : null;

        $messageId = (int) AssistantSession::addMessage(
            $sessionId,
            'assistant',
            (string) $result['reply'],
            $result['tool_calls'] ?: null,
            $changeSet,
            $result['validation_errors'],
            $result['usage'] ?? []
        );

        // A new proposal replaces whatever was still awaiting review. Two pending
        // diffs in one conversation would leave the owner no way to tell which one
        // the assistant currently means — and both would be approvable.
        if ($changeSet !== null) {
            AssistantSession::supersedePending($sessionId, $messageId);
        }

        return $messageId;
    }

    private static function rowsSummary(mixed $rows): string
    {
        if (!is_array($rows)) {
            $decoded = json_decode((string) $rows, true);
            $rows    = is_array($decoded) ? $decoded : [];
        }

        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $row   = (array) $row;
            $first = '';

            // Whatever the row's first non-empty text is — 'title', 'label',
            // 'value', anything. Repeater sub-fields are author-defined, so this
            // cannot assume a key name.
            foreach ($row as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $first = trim((string) $value);
                    break;
                }
            }

            $lines[] = ($index + 1) . '. ' . ($first === '' ? '(empty)' : $first);
        }

        return implode("\n", $lines);
    }

    /**
     * A block's content as one readable line for the diff.
     *
     * The reviewer needs to see the words that will appear on their site, not a
     * JSON dump of every setting — the whole point of the diff is that it is
     * readable at a glance before someone approves it.
     *
     * @param array<string, mixed> $data
     */
    private static function blockSummary(array $data): string
    {
        $parts = [];

        foreach (['heading', 'text'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $label = trim((string) ($data['button_label'] ?? $data['link_label'] ?? ''));
        if ($label !== '') {
            $parts[] = '[' . $label . ']';
        }

        $style = trim((string) ($data['style'] ?? ''));
        if ($style !== '' && $style !== 'info') {
            $parts[] = '(' . $style . ')';
        }

        return implode(' ', $parts);
    }

    /**
     * The client-supplied id naming this turn's progress record.
     *
     * It is a namespace, never a credential: TurnProgress keys the file by the
     * acting user as well, so a guessed id reaches nothing. Falling back to a
     * server-generated one keeps the turn working when the client omits it.
     */
    private static function turnId(): string
    {
        $id = (string) ($_POST['turn_id'] ?? '');

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) === 1 ? $id : bin2hex(random_bytes(16));
    }

    private static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        Cache::noStore();
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A message worth showing someone who is not holding the API docs.
     *
     * The raw exception still goes to the error log; what reaches the screen
     * should say what to do about it.
     */
    private static function friendlyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, '401')  => 'The Anthropic API key was rejected. Check anthropic_api_key in admin/config/config.php.',
            str_contains($message, '403')  => 'The Anthropic account has no credit available.',
            str_contains($message, '429')  => 'Rate limited by the API. Wait a moment and try again.',
            str_contains($message, 'cURL') => 'Could not reach the Claude API from this server.',
            default                        => $message,
        };
    }

    /** Approve the proposal attached to a message. */
    public function apply(array $vars = []): void
    {
        Auth::requireAuth();

        $userId    = (int) Auth::user()['id'];
        $messageId = (int) $vars['id'];
        $message   = AssistantSession::findMessage($messageId, $userId);

        if ($message === null || ($message['change_set_status'] ?? null) !== 'pending') {
            header('Location: ' . ADMIN_URL . '/assistant');
            exit;
        }

        $stored    = AssistantSession::changeSetOf($message) ?? [];
        $changeSet = new ChangeSet(
            (string) ($stored['id'] ?? ''),
            (string) ($stored['summary'] ?? ''),
            (array) ($stored['operations'] ?? [])
        );

        $outcome = ChangeSetApplier::apply($changeSet, ToolContext::fromSession());

        if (!empty($outcome['applied'])) {
            AssistantSession::setChangeSetStatus($messageId, 'applied');
            // What actually happened, not what was asked for — undo needs the
            // ids of rows that were created and the previous contents of files
            // that were rewritten, neither of which exist in the proposal.
            AssistantSession::recordApplyResults($messageId, $outcome['results'] ?? []);
        }

        $_SESSION['_assistant_outcome'] = $outcome;

        header('Location: ' . ADMIN_URL . '/assistant/' . (int) $message['session_id']);
        exit;
    }

    /**
     * Undo an applied change set, from the proposal that caused it.
     *
     * The alternative is the site owner opening each touched entry and picking the
     * right snapshot out of its Revisions card — which is not something the person
     * this feature is for is going to do. One button, in the place where the change
     * was approved.
     */
    public function undo(array $vars = []): void
    {
        Auth::requireAuth();

        $userId    = (int) Auth::user()['id'];
        $messageId = (int) $vars['id'];
        $message   = AssistantSession::findMessage($messageId, $userId);

        if ($message === null || ($message['change_set_status'] ?? null) !== 'applied') {
            header('Location: ' . ADMIN_URL . '/assistant');
            exit;
        }

        $changeSetId = (string) ($message['change_set_id'] ?? '');
        $outcome     = Revision::restoreChangeSet($changeSetId, $userId);
        // THE SHOP'S PART, from what applying it did: counts, orders, codes
        // and options are not in the revisions table (`ShopOps::reverse()`).
        $shop        = ShopOps::reverse(AssistantSession::applyResultsOf($message), ToolContext::fromSession());
        $count       = (int) $outcome['restored'] + $shop['reversed'];

        // Template edits are not in the revisions table — that models posts and
        // pages — so they are reversed by swapping the replacement back. Doing it
        // this way rather than storing a copy of the whole file means an edit made
        // in between is caught by the same uniqueness check instead of clobbered.
        $stored = AssistantSession::changeSetOf($message) ?? [];

        if ($count > 0) {
            AssistantSession::setChangeSetStatus($messageId, 'undone');
            $_SESSION['_assistant_outcome'] = [
                'undone'  => true,
                'applied' => false,
                'results' => [],
                'errors'  => [],
                'kept'    => $shop['kept'],
                'count'   => $count,
            ];
        } else {
            $_SESSION['_assistant_outcome'] = [
                'applied' => false,
                'results' => [],
                'errors'  => $shop['kept'] !== [] ? $shop['kept']
                    : ['Nothing was undone — the snapshots for this change are no longer available.'],
            ];
        }

        header('Location: ' . ADMIN_URL . '/assistant/' . (int) $message['session_id']);
        exit;
    }

    /** Dismiss a proposal without applying it. */
    public function discard(array $vars = []): void
    {
        Auth::requireAuth();

        $userId    = (int) Auth::user()['id'];
        $messageId = (int) $vars['id'];
        $message   = AssistantSession::findMessage($messageId, $userId);

        if ($message !== null) {
            AssistantSession::setChangeSetStatus($messageId, 'discarded');
            header('Location: ' . ADMIN_URL . '/assistant/' . (int) $message['session_id']);
            exit;
        }

        header('Location: ' . ADMIN_URL . '/assistant');
        exit;
    }

    // ----------------------------------------------------------------

    /**
     * Real transport when a key is configured, stub otherwise, so the screen is
     * explorable before anyone has paid for a key.
     */
    private static function transport(): Transport
    {
        $config = \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php');
        $claude = ClaudeTransport::fromConfig(is_array($config) ? $config : []);

        return $claude->isConfigured() ? $claude : new StubTransport();
    }

    /**
     * Turn operations into reviewable rows.
     *
     * The reviewer needs the entry's title and the field's label — not
     * `entry_id 2, field product_price`. Resolving that here keeps the template dumb.
     *
     * @param array<int, array<string, mixed>> $operations
     * @return array<int, array<string, mixed>>
     */
    /**
     * A last-resort display name for something whose label is missing.
     *
     * Nothing in the diff should ever read `product_sale_price` or `header_below`
     * to the person whose website it is. Every branch below has a proper label
     * first — from the field group, the registry, the block type — but a field
     * group saved without a label, or a region key from an older proposal, used to
     * fall through to the raw identifier and print it in the review table.
     *
     * This is not a substitute for a real label. It is the difference between
     * "Product sale price" and something that looks like a bug.
     */
    private static function humanKey(string $key): string
    {
        $words = trim(str_replace(['_', '-'], ' ', $key));

        return $words === '' ? 'Value' : ucfirst($words);
    }

    private static function buildDiff(array $operations, bool $settled = false): array
    {
        $rows = [];

        // ── WHAT IT WAS, ONCE IT IS NO LONGER WAITING ───────────────────────
        // While a change can still be approved, `from` is the value stored now,
        // because that is what approving replaces. Applied, the stored value is
        // the `to`, and an applied change read "$179 → $179": what it was
        // proposed from is the answer then.
        $was = static fn (array $op, mixed $stored): mixed => $settled && array_key_exists('from', $op) ? $op['from'] : $stored;

        foreach ($operations as $op) {
            $type    = (string) ($op['op'] ?? '');
            if (in_array($type, ShopOps::OPS, true)) {
                $rows[] = ShopOps::row($op, $settled);
                continue;
            }
            $entryId = (int) ($op['entry_id'] ?? 0);
            $entry   = $entryId > 0 ? Post::find($entryId) : null;

            $row = [
                'op'      => $type,
                'entry'   => $entry['title'] ?? null,
                'type'    => $entry['post_type'] ?? ($op['content_type'] ?? null),
                'label'   => null,
                'from'    => null,
                'to'      => null,
                'details' => [],
            ];

            if ($type === 'update_field' && $entry !== null) {
                $field        = ContentTypeSchema::field((string) $entry['post_type'], (string) $op['field']);
                $row['label'] = $field['label'] ?? self::humanKey((string) $op['field']);
                $row['from']  = $was($op, Meta::get($entryId, (string) $op['field']));
                $row['to']    = $op['to'] ?? null;
            } elseif ($type === 'update_core' && $entry !== null) {
                $row['label'] = self::humanKey((string) $op['field']);
                $row['from']  = $was($op, $entry[$op['field']] ?? null);
                $row['to']    = $op['to'] ?? null;
            } elseif ($type === 'create_entry') {
                // Show the human label ("Team"), not the registry key ("team_member").
                $definition   = \Cms\ContentType\Registry::get((string) ($op['content_type'] ?? ''));
                $row['type']  = $definition['label_single'] ?? self::humanKey((string) ($op['content_type'] ?? ''));
                $row['label'] = 'New entry';
                $row['to']    = $op['values']['title'] ?? '(untitled)';
                foreach ((array) ($op['values'] ?? []) as $key => $value) {
                    if ($key !== 'title' && is_scalar($value)) {
                        $row['details'][self::humanKey((string) $key)] = (string) $value;
                    }
                }
            } elseif ($type === 'trash_entry') {
                $row['label'] = 'Move to trash';
                $row['from']  = $entry['title'] ?? "entry {$entryId}";
            } elseif ($type === 'update_page') {
                // Pages come from their own table, so entry_id was never resolved.
                $pageId       = (int) ($op['page_id'] ?? 0);
                $page         = $pageId > 0 ? \Cms\Models\Page::find($pageId) : null;
                $field        = (string) ($op['field'] ?? '');
                $row['entry'] = $page['title'] ?? "page {$pageId}";
                $row['type']  = 'Page';
                $row['label'] = self::PAGE_FIELD_LABELS[$field] ?? self::humanKey($field);
                $row['from']  = $was($op, $page[$field] ?? null);
                $row['to']    = $op['to'] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
