<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
// ── CONTENT IS SANITISED HERE, AT THE MODEL, AND NOT IN THE CONTROLLER ──────
// `templates/post.php` emits content UNESCAPED — deliberately, because a body is
// meant to contain markup — so whatever is in this column reaches every
// visitor's browser verbatim.
//
// Several paths write it — the admin's own screens, the assistant's applier,
// import. Sanitising in the controllers would mean the guarantee holds for the
// paths somebody remembered, and a new writer added next year is a hole nobody
// notices — this is "one path in" applied to safety rather than to management.
//
// `Sanitizer::clean()` is idempotent, so cleaning content that is already
// clean costs a parse and changes nothing.
use Cms\Html\Sanitizer;
use PDO;

class Post
{
    /**
     * A SAMPLE ENTRY stands in for content still to come, so a layout can be
     * seen before the real content exists (migration 132). On a copy of the
     * site that is not production it is published, and in production it is
     * private: off every list, its address 404 to a visitor, and listed by
     * System health with a way to move it to the Trash. See `samplesShown()`.
     */
    public const SAMPLE = 'sample';

    /**
     * Return all posts of a given type, joined with author username.
     *
     * ── ORDER ───────────────────────────────────────────────────────────────
     * Newest first, unless the type is one people arrange by hand — a roster, a
     * price list — in which case `menu_order` decides and the date is only the
     * tiebreaker. `$archetypes` is what says which: pass the Content Type's
     * archetypes and `ArchetypeDefaults::isHandOrdered()` answers it — the same
     * call the public archive and the entry form's Position panel make. The
     * three must agree, or the owner arranges something on one and sees nothing
     * change on the other.
     *
     * Defaults to the old behaviour for every caller that does not care.
     *
     * @param array<int, string> $archetypes
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $postType = 'post', ?int $authorId = null, array $archetypes = []): array
    {
        $pdo = Database::getInstance();

        $sql    = 'SELECT p.*, ' . \Cms\Models\User::nameSql('u') . ' AS author_name
                   FROM posts p
                   JOIN users u ON u.id = p.author_id
                   WHERE p.post_type = :post_type AND p.status != \'trash\'';
        $params = [':post_type' => $postType];

        if ($authorId !== null) {
            $sql              .= ' AND p.author_id = :author_id';
            $params[':author_id'] = $authorId;
        }

        // Two literals chosen by a boolean. Nothing from a request reaches this.
        // ONE RULE, IN ONE PLACE — this was a written-out `listing || product`,
        // and so were the public archive and the entry form's Position panel.
        // Three copies of the test that decides whether a control is shown AND
        // whether anything reads it is how the owner gets a box that does
        // nothing; see ArchetypeDefaults::isHandOrdered().
        $handOrdered = \Cms\ContentType\ArchetypeDefaults::isHandOrdered($archetypes);

        $sql .= $handOrdered
            ? ' ORDER BY p.menu_order ASC, p.created_at DESC'
            : ' ORDER BY p.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The position a newly created entry of this type should take: the end.
     *
     * Zero would put every new entry at the top of a hand-ordered list, above
     * whatever the owner deliberately put first.
     */
    public static function nextPosition(string $postType): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(menu_order), -1) + 1 FROM posts WHERE post_type = :post_type'
        );
        $stmt->execute([':post_type' => $postType]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Return all trashed posts of a given type, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function allTrashed(string $postType = 'post', ?int $authorId = null): array
    {
        $pdo    = Database::getInstance();
        // `$authorId` narrows the bin to one author's entries — the same filter
        // `all()` takes, for the same reason: an author may only act on their
        // own, so listing anyone else's offers buttons that answer 403.
        $sql    = 'SELECT p.*, ' . \Cms\Models\User::nameSql('u') . ' AS author_name
                   FROM posts p
                   JOIN users u ON u.id = p.author_id
                   WHERE p.post_type = :post_type AND p.status = \'trash\'';
        $params = [':post_type' => $postType];
        if ($authorId !== null) {
            $sql                 .= ' AND p.author_id = :author_id';
            $params[':author_id'] = $authorId;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY p.created_at DESC');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count trashed posts of a given type — one author's, when `$authorId` is
     * given, so the count on the list's Trash link matches what the bin shows.
     */
    public static function countTrashed(string $postType, ?int $authorId = null): int
    {
        $pdo    = Database::getInstance();
        $sql    = 'SELECT COUNT(*) FROM posts WHERE post_type = :post_type AND status = \'trash\'';
        $params = [':post_type' => $postType];
        if ($authorId !== null) {
            $sql                 .= ' AND author_id = :author_id';
            $params[':author_id'] = $authorId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Count LIVE posts of a given type — trash excluded.
     *
     * The Content Types screen puts this on every row, because "is anything
     * using this type?" is the question you ask immediately before deleting
     * one, and the honest answer excludes what is already in the bin. Counting
     * `Post::all()` in PHP would fetch every row of every type to produce a
     * number, on a screen that shows one per type.
     */
    public static function countOfType(string $postType, ?int $authorId = null): int
    {
        $pdo    = Database::getInstance();
        // `$authorId` is the entry list's "of how many": an author's list holds
        // only their own, so "3 of 40" would count entries they cannot see.
        $sql    = 'SELECT COUNT(*) FROM posts WHERE post_type = :post_type AND status <> \'trash\'';
        $params = [':post_type' => $postType];
        if ($authorId !== null) {
            $sql                 .= ' AND author_id = :author_id';
            $params[':author_id'] = $authorId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * THE DATE AN ENTRY'S ROW SHOWS, as SQL — and so the date the entry list
     * sorts on and the month its filter files it under. `$alias` is a literal
     * the caller writes (`'p'`), never request data.
     *
     * A LIVE entry — published or password-protected — dates from its publish,
     * a scheduled one included: the date still to come is what an owner scans a
     * scheduled row for. Anything else has not gone out, so its last edit is the
     * honest fact.
     *
     * ── ONE RULE, BECAUSE THE LIST HAD TWO ──────────────────────────────────
     * The row printed the publish date and the list was ordered by `created_at`,
     * a column no row shows. "Newest first" then read as dates out of order, and
     * a blog imported in one sitting would have every entry created in the same
     * minute. The row prints this, the sort orders by it and the month filter
     * reads it, so the three cannot disagree.
     *
     * The row's own rule had gone stale as well: it dated `published` only, so a
     * password-protected entry showed its last edit — beside a Scheduled chip,
     * on one set to go out next week. Migration 054 gave every live status a
     * date, and "everything else has not got one" stopped being true that day.
     */
    public static function listDateSql(string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        // A sample entry has a publish date as a live one does (liveDate()).
        return "(CASE WHEN {$a}status IN ('published', 'password', 'sample') AND {$a}published_at IS NOT NULL"
             . " THEN {$a}published_at ELSE {$a}updated_at END)";
    }

    /**
     * ONE PAGE OF THE ENTRY LIST — filtered, ordered and counted by the database.
     *
     * Not `all()`: every entry of the type fetched and filtered in the browser by
     * hiding rows is honest at a handful and not at hundreds, and cannot sort,
     * page, or filter by anything the row does not print. This answers the
     * list's whole question in SQL — which entries, in what order, which page
     * of them, and how many matched.
     *
     * Every value in `$q` has been checked by the controller against what can
     * exist: a status the registry knows, the ids of a real term's branch, one of
     * two sort columns. None of it reaches the SQL as text — filters are bound,
     * and the ORDER BY is chosen from literals below.
     *
     * A FIELD'S ORDER, `orderby` = `field:{key}`: the controller has checked
     * the key is a field of the type the list can sort by
     * (`ContentType\ListColumns::sortable()`); its value is joined from
     * post_meta, a number's as a number, and an entry with none comes last
     * either way.
     *
     * A VIEW GIVEN AS IDS, `ids` (an event type's Upcoming or Past,
     * `eventGroups()`): only those entries, in that order when no sort is
     * chosen (`ids_ordered`), else in the chosen one.
     *
     * @param array{
     *   type: string, author_only?: ?int, status?: string, search?: string,
     *   author?: ?int, month?: string, terms?: array<string, list<int>>,
     *   orderby?: string, order?: string, hand_ordered?: bool, sort_numeric?: bool,
     *   ids?: list<int>|null, ids_ordered?: bool,
     *   page?: int, per_page?: int
     * } $q
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function adminList(array $q): array
    {
        [$where, $params] = self::listWhere($q);
        $date = self::listDateSql('p');
        $pdo  = Database::getInstance();

        // Integers produced here, never bound — MySQL will not take a
        // placeholder for LIMIT under every driver setting.
        $perPage = max(1, (int) ($q['per_page'] ?? 20));
        $offset  = (max(1, (int) ($q['page'] ?? 1)) - 1) * $perPage;

        // THE COLUMNS THE ROW DRAWS, not `p.*`: that carries every body on the
        // page along with it, and the list prints none of them.
        $select = "SELECT p.id, p.post_type, p.title, p.slug, p.lang, p.status, p.published_at, p.unpublish_at,
                          p.updated_at, p.created_at, p.author_id, p.parent_id, p.menu_order,
                          $date AS list_date, " . User::nameSql('u') . " AS author_name
                     FROM posts p
                     JOIN users u ON u.id = p.author_id";

        $ids = isset($q['ids']) && is_array($q['ids']) ? array_values(array_map('intval', $q['ids'])) : null;
        if ($ids !== null && !empty($q['ids_ordered'])) {
            // ALREADY FILTERED AND ORDERED: the page is a slice of them, its
            // rows in that order. Integers, never text, so FIELD() takes them.
            $slice = array_slice($ids, $offset, $perPage);
            if ($slice === []) {
                return ['rows' => [], 'total' => count($ids)];
            }
            $list = implode(',', $slice);
            $stmt = $pdo->query("$select WHERE p.id IN ($list) ORDER BY FIELD(p.id, $list)");

            return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => count($ids)];
        }
        if ($ids !== null) {
            $where .= ' AND p.id IN (' . ($ids === [] ? '0' : implode(',', $ids)) . ')';
        }

        // THE SAME WHERE, so the count cannot report a different set than the
        // page it sits under.
        $count = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        // Literals chosen by a match — the request picks WHICH, never WHAT. `id`
        // breaks every tie so two entries with one date keep their places from
        // one page to the next; without it MySQL may return a tie in either
        // order, and a row can appear on page 1 and again on page 2.
        $dir     = (($q['order'] ?? '') === 'asc') ? 'ASC' : 'DESC';
        $orderby = (string) ($q['orderby'] ?? '');
        $join    = '';
        if (str_starts_with($orderby, 'field:')) {
            $join                = ' LEFT JOIN post_meta sm ON sm.post_id = p.id AND sm.meta_key = :sort_key';
            $params[':sort_key'] = substr($orderby, 6);
            $value               = !empty($q['sort_numeric']) ? 'CAST(sm.meta_value AS DECIMAL(20,6))' : 'sm.meta_value';
            $orderby             = 'field';
        }
        $order = match ($orderby) {
            'title' => "p.title $dir, p.id $dir",
            'date'  => "$date $dir, p.id $dir",
            'field' => "(sm.meta_value IS NULL OR sm.meta_value = '') ASC, $value $dir, p.id $dir",
            // Unsorted: the order the public archive uses. A roster the owner
            // arranged by hand reads the same here as on the site.
            default => !empty($q['hand_ordered'])
                ? "p.menu_order ASC, $date DESC, p.id DESC"
                : "$date DESC, p.id DESC",
        };

        $stmt = $pdo->prepare(
            "$select$join
              WHERE $where
           ORDER BY $order
              LIMIT " . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * AN EVENT TYPE'S UPCOMING AND PAST, under the list's filters: the ids of
     * each, in the order its public list follows (`Content\Events::order()`):
     * still to come, soonest first, and those with no date after them; over,
     * newest first.
     *
     * @param array<string, mixed> $q see adminList()
     * @return array{upcoming: list<int>, past: list<int>}
     */
    public static function eventGroups(array $q, ?\DateTimeImmutable $now = null): array
    {
        [$where, $params] = self::listWhere($q);
        $stmt = Database::getInstance()->prepare(
            "SELECT p.id, s.meta_value AS starts, e.meta_value AS ends
               FROM posts p
               LEFT JOIN post_meta s ON s.post_id = p.id AND s.meta_key = 'event_start_date'
               LEFT JOIN post_meta e ON e.post_id = p.id AND e.meta_key = 'event_end_date'
              WHERE $where
           ORDER BY p.published_at DESC, p.id DESC"
        );
        $stmt->execute($params);
        $groups = ['upcoming' => [], 'past' => []];
        foreach (\Cms\Content\Events::order($stmt->fetchAll(PDO::FETCH_ASSOC), $now ?? new \DateTimeImmutable('now')) as $row) {
            $groups[$row['past'] ? 'past' : 'upcoming'][] = (int) $row['id'];
        }

        return $groups;
    }

    /**
     * The list's WHERE clause and its bound values. Shared by the page and its
     * count so the two cannot disagree about what matched.
     *
     * @param  array<string, mixed> $q  see adminList()
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function listWhere(array $q): array
    {
        $where  = ['p.post_type = :type', "p.status <> 'trash'"];
        $params = [':type' => (string) $q['type']];

        // TWO AUTHOR TESTS, NOT ONE. `author_only` is the rule — an author's list
        // holds only their own entries — and `author` is a filter an editor
        // chose. A filter must never be able to widen the rule, so the rule is
        // its own clause rather than a default the filter overwrites.
        if (($q['author_only'] ?? null) !== null) {
            $where[]                = 'p.author_id = :author_only';
            $params[':author_only'] = (int) $q['author_only'];
        }
        if (($q['author'] ?? null) !== null) {
            $where[]           = 'p.author_id = :author';
            $params[':author'] = (int) $q['author'];
        }
        // ONE PARENT'S ENTRIES, on a child type's list filtered by its parent.
        if (($q['parent'] ?? null) !== null) {
            $where[]           = 'p.parent_id = :parent';
            $params[':parent'] = (int) $q['parent'];
        }

        // THE CHIP'S RULE, NOT THE COLUMN'S. Scheduled is shown and not stored
        // (Status::forEntry()), so Published means published AND its date has
        // come, and a password-protected entry with a date still to come is
        // Scheduled rather than Password — the same test isScheduled() makes,
        // so the filter finds exactly the rows wearing that chip.
        // Ended is the same kind of state: published or password-protected,
        // with its unpublish date come (hasEnded()). A scheduled entry is
        // Scheduled first, as its chip says.
        $come  = '(p.published_at IS NULL OR p.published_at <= UTC_TIMESTAMP())';
        $going = self::notEnded('p');
        $status = match ((string) ($q['status'] ?? '')) {
            'published' => "p.status = 'published' AND $come AND $going",
            'password'  => "p.status = 'password' AND $come AND $going",
            'scheduled' => "p.status IN ('published', 'password') AND p.published_at > UTC_TIMESTAMP()",
            'ended'     => "p.status IN ('published', 'password') AND $come AND NOT $going",
            'private'   => "p.status = 'private'",
            // Its chip says Sample whatever its dates (Status::forEntry()).
            'sample'    => "p.status = 'sample'",
            default     => null,
        };
        if ($status !== null) {
            $where[] = $status;
        }

        // The title, the excerpt and the body are searched — plus the slug,
        // which is how an owner holding a URL finds the entry behind it.
        // LIKE's own wildcards in what was typed are searched for, not obeyed.
        // ONE LANGUAGE: `own`, the site's, or the second language's prefix.
        $lang = (string) ($q['lang'] ?? '');
        if ($lang !== '') {
            $where[] = self::inLanguage('p', $lang === 'own' ? '' : $lang);
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $like    = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(p.title LIKE :s1 OR p.slug LIKE :s2 OR p.excerpt LIKE :s3 OR p.content LIKE :s4)';
            $params += [':s1' => $like, ':s2' => $like, ':s3' => $like, ':s4' => $like];
        }

        // ── SOLD OUT, OR RUNNING LOW: a product type's list ────────────────
        // A product with options is sold out, or running low, where any of
        // its options is; one without, where its own count is. Sold out is a
        // count used up or the word set by hand; running low is a count above
        // nothing and at or below the shop's low mark (`Stock::lowAt()`).
        $stock = (string) ($q['stock'] ?? '');
        if ($stock === 'out' || $stock === 'low') {
            $own = "NOT EXISTS (SELECT 1 FROM product_variations v0 WHERE v0.post_id = p.id) AND ";
            $qty = "(SELECT CAST(mq.meta_value AS SIGNED) FROM post_meta mq WHERE mq.post_id = p.id AND mq.meta_key = 'product_stock_qty' LIMIT 1)";
            $managed = "EXISTS (SELECT 1 FROM post_meta mm WHERE mm.post_id = p.id AND mm.meta_key = 'product_manage_stock' AND mm.meta_value = '1')";
            if ($stock === 'out') {
                $where[] = "(($own(($managed AND $qty <= 0) OR EXISTS (SELECT 1 FROM post_meta ms WHERE ms.post_id = p.id
                              AND ms.meta_key = 'product_stock_status' AND ms.meta_value = 'out_of_stock')))
                            OR EXISTS (SELECT 1 FROM product_variations v WHERE v.post_id = p.id
                              AND ((v.manage_stock = 1 AND v.stock_qty <= 0) OR v.stock_status = 'out_of_stock')))";
            } else {
                $where[] = "(($own$managed AND $qty BETWEEN 1 AND :low_a)
                            OR EXISTS (SELECT 1 FROM product_variations v WHERE v.post_id = p.id
                              AND v.manage_stock = 1 AND v.stock_qty BETWEEN 1 AND :low_b))";
                $params += [':low_a' => (int) ($q['low_at'] ?? 2), ':low_b' => (int) ($q['low_at'] ?? 2)];
            }
        }

        $range = self::monthRange((string) ($q['month'] ?? ''));
        if ($range !== null) {
            $date    = self::listDateSql('p');
            $where[] = "$date >= :month_from AND $date < :month_to";
            $params += [':month_from' => $range[0], ':month_to' => $range[1]];
        }

        // ONE CLAUSE PER TAXONOMY, and each is a term's whole branch — what its
        // archive lists, so a pillar finds what is filed under its
        // children. `IN (subquery)` rather than a join, so an entry filed under
        // two terms of one branch is one row, not two.
        $n = 0;
        foreach ((array) ($q['terms'] ?? []) as $ids) {
            $marks = [];
            foreach (array_unique(array_map('intval', (array) $ids)) as $id) {
                if ($id > 0) {
                    $marks[]    = $mark = ':term' . $n++;
                    $params[$mark] = $id;
                }
            }
            if ($marks !== []) {
                $where[] = 'p.id IN (SELECT ptr.post_id FROM post_term_relationships ptr WHERE ptr.term_id IN ('
                         . implode(', ', $marks) . '))';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * A `Y-m` month IN THE SITE'S ZONE as the UTC range storage holds it, or
     * null for anything that is not a month. An entry published at 02:00 UTC
     * on the 1st is still the 31st in Kamloops, and its row says so — the
     * filter has to agree with the row, not with the database's clock.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function monthRange(string $month): ?array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }
        $start = new \DateTimeImmutable($month . '-01 00:00:00', \Cms\Helpers\Dates::zone());
        $utc   = new \DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The months a type's entries fall in, newest first — `Y-m` => "September
     * 2026". Only months that hold something are offered: a month with nothing
     * in it is an option that can only return nothing.
     *
     * Grouped in PHP rather than by `DATE_FORMAT()`, because the month is the
     * SITE'S (see monthRange()) and MySQL's time-zone tables are not something
     * shared hosting can be relied on to have loaded. One date column per entry.
     *
     * @return array<string, string>
     */
    public static function listMonths(string $postType, ?int $authorId = null): array
    {
        $sql    = 'SELECT ' . self::listDateSql() . " AS d FROM posts WHERE post_type = :type AND status <> 'trash'";
        $params = [':type' => $postType];
        if ($authorId !== null) {
            $sql                 .= ' AND author_id = :author_id';
            $params[':author_id'] = $authorId;
        }
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        $months = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $key = \Cms\Helpers\Dates::format((string) $d, 'Y-m');
            if ($key !== '' && !isset($months[$key])) {
                $months[$key] = \Cms\Helpers\Dates::format((string) $d, 'F Y');
            }
        }
        krsort($months);

        return $months;
    }

    /**
     * Everyone who has an entry of this type (the trash aside), by the name
     * their byline prints — the entry list's Author filter. Only people who
     * would return something: a user with nothing here is an option that
     * empties the list.
     *
     * @return array<int, string>  user id => name
     */
    public static function authorsOf(string $postType): array
    {
        $name = User::nameSql('u');
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT u.id, $name AS name
               FROM posts p
               JOIN users u ON u.id = p.author_id
              WHERE p.post_type = :type AND p.status <> 'trash'
           ORDER BY name ASC"
        );
        $stmt->execute([':type' => $postType]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }
        return $out;
    }

    /**
     * Move a post to the trash (sets status = 'trash').
     */
    public static function trash(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE posts SET status = \'trash\' WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Restore a post from the trash (sets status = 'private').
     */
    public static function restore(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE posts SET status = \'private\' WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Find a single post by ID. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT p.*, ' . \Cms\Models\User::nameSql('u') . ' AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a live post by slug for the public frontend.
     * Returns posts with status published, password, private or sample, which
     * the public controller treats as this copy treats it (`siteStatus()`). A
     * slug is unique within its language: the one in `$lang`, or, when it is
     * null, in the page being drawn's (`inLanguage()`).
     *
     * @return array<string, mixed>|null
     */
    public static function findBySlug(string $slug, ?string $lang = null): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT p.*, " . \Cms\Models\User::bylineSql('u') . " AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.slug = :slug AND p.status IN ('published', 'password', 'private', 'sample')
               AND " . self::inLanguage('p', $lang) . "
             LIMIT 1"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new post. Returns the new post's ID.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        // ── `menu_order` IS THE ORDERING MECHANISM ──────────────────────────
        // Migration 030 added the column. Unwritten, every entry would sit at 0
        // and no list could be ordered by hand: an owner who sees two things in
        // the wrong order would have no way to swap them, in the admin OR
        // through the assistant. This is the existing column being used rather
        // than a new store being invented.
        $stmt = $pdo->prepare(
            'INSERT INTO posts
                 (post_type, title, slug, lang, translation_of, content, excerpt, status, password_hash,
                  featured_image_id, published_at, unpublish_at, menu_order, author_id)
             VALUES
                 (:post_type, :title, :slug, :lang, :translation_of, :content, :excerpt, :status, :password_hash,
                  :featured_image_id, :published_at, :unpublish_at, :menu_order, :author_id)'
        );
        $stmt->execute([
            ':post_type'         => $data['post_type']     ?? 'post',
            ':title'             => $data['title'],
            ':slug'              => $data['slug'],
            // '' the site's own language; a translation names its own and the
            // entry it translates.
            ':lang'              => \Cms\Site\Language::checked((string) ($data['lang'] ?? '')),
            ':translation_of'    => !empty($data['translation_of']) ? (int) $data['translation_of'] : null,
            ':content'           => Sanitizer::clean((string) ($data['content'] ?? '')),
            ':excerpt'           => self::cleanExcerpt($data['excerpt'] ?? null),
            ':status'            => $data['status']        ?? 'private',
            ':password_hash'     => $data['password_hash'] ?? null,
            ':featured_image_id' => !empty($data['featured_image_id']) ? (int) $data['featured_image_id'] : null,
            ':published_at'      => self::liveDate((string) ($data['status'] ?? 'private'), $data['published_at'] ?? null),
            ':unpublish_at'      => self::shape('unpublish_at', $data['unpublish_at'] ?? null),
            ':menu_order'        => (int) ($data['menu_order'] ?? 0),
            ':author_id'         => $data['author_id'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * THE ENTRY BEFORE OR AFTER THIS ONE, for a template's Previous and Next.
     *
     * Same type, live, by date: "previous" is the older neighbour, "next" the
     * newer. With `$taxonomyKey`, only entries sharing one of this entry's
     * terms in that taxonomy count — an article's "next in this category".
     *
     * ENTRIES ON THE SAME SECOND ARE NOT EACH OTHER'S NEIGHBOURS — the
     * neighbour is strictly older or strictly newer; among candidates tied on
     * THEIR second, previous is the higher id and next the lower.
     *
     * BY DATE, even on a hand-ordered type: a roster's neighbours by position
     * is a different question, and no template asks it.
     *
     * @param array<string, mixed> $post  a row as find() returns it
     * @return array<string, mixed>|null  the neighbour, with `author_name`
     */
    public static function adjacent(array $post, bool $previous = true, ?string $taxonomyKey = null): ?array
    {
        $at = trim((string) ($post['published_at'] ?? ''));
        if ($at === '' || empty($post['id'])) {
            return null;
        }

        // Two literals chosen by a boolean — nothing from a request reaches
        // the SQL.
        $cmp = $previous ? '<' : '>';
        $dir = $previous ? 'DESC' : 'ASC';

        $params = [
            ':type' => (string) $post['post_type'],
            ':at'   => $at,
        ];
        $sameTerm = '';
        if ($taxonomyKey !== null) {
            $sameTerm = " AND p.id IN (
                SELECT theirs.post_id
                FROM post_term_relationships mine
                JOIN terms t ON t.id = mine.term_id AND t.taxonomy_key = :tax
                JOIN post_term_relationships theirs ON theirs.term_id = mine.term_id
                WHERE mine.post_id = :self)";
            $params[':tax']  = $taxonomyKey;
            $params[':self'] = (int) $post['id'];
        }

        // In the entry's own language: a translation's neighbours are its
        // language's entries.
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*, ' . User::bylineSql('u') . ' AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.post_type = :type AND ' . self::live('p', (string) ($post['lang'] ?? '')) . "
               AND p.published_at {$cmp} :at"
               . $sameTerm . "
             ORDER BY p.published_at {$dir}, p.id {$dir}
             LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * ENTRIES LIKE THIS ONE — the ones sharing the most terms with it, newest
     * first among equals. Same type, live, never itself. With
     * `$taxonomyKey`, only that taxonomy's terms count: "more in this
     * category".
     *
     * EMPTY WHEN NOTHING SHARES A TERM — unless `$fill` asks for the rest from
     * the newest entries (`recent()`), never itself and never twice: a design
     * with three related slots that wants three (an article page, say).
     * Without it, a template decides what to show
     * instead; this does not invent a relation on its own.
     *
     * @param array<string, mixed> $post  a row as find() returns it
     * @return list<array<string, mixed>>  each with `shared` (terms in common;
     *                                     0 for a filled one) and `author_name`
     */
    public static function related(array $post, int $limit = 3, ?string $taxonomyKey = null, bool $fill = false): array
    {
        if (empty($post['id'])) {
            return [];
        }
        $limit  = max(1, min(20, $limit));   // an integer this method made
        $params = [':self' => (int) $post['id'], ':type' => (string) $post['post_type']];
        $taxJoin = '';
        if ($taxonomyKey !== null) {
            $taxJoin = 'JOIN terms t ON t.id = mine.term_id AND t.taxonomy_key = :tax';
            $params[':tax'] = $taxonomyKey;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT p.*, s.shared, ' . User::bylineSql('u') . " AS author_name
             FROM (SELECT theirs.post_id, COUNT(*) AS shared
                   FROM post_term_relationships mine
                   {$taxJoin}
                   JOIN post_term_relationships theirs
                     ON theirs.term_id = mine.term_id AND theirs.post_id <> mine.post_id
                   WHERE mine.post_id = :self
                   GROUP BY theirs.post_id) s
             JOIN posts p ON p.id = s.post_id
             JOIN users u ON u.id = p.author_id
             WHERE p.post_type = :type AND " . self::live('p', (string) ($post['lang'] ?? '')) . "
             ORDER BY s.shared DESC, p.published_at DESC, p.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($fill && count($rows) < $limit) {
            $taken = array_merge([(int) $post['id']], array_map(static fn (array $r): int => (int) $r['id'], $rows));
            foreach (self::recent((string) $post['post_type'], $limit - count($rows), $taken, (string) ($post['lang'] ?? '')) as $row) {
                $row['shared'] = 0;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * BOTH NEIGHBOURS AT ONCE — `['prev' => …, 'next' => …]`, each a row or
     * null — for a template that prints the pair, such as an article or a
     * portfolio page. adjacent()'s rule, both ways.
     *
     * @param array<string, mixed> $post  a row as find() returns it
     * @return array{prev: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    public static function neighbours(array $post, ?string $taxonomyKey = null): array
    {
        return [
            'prev' => self::adjacent($post, true, $taxonomyKey),
            'next' => self::adjacent($post, false, $taxonomyKey),
        ];
    }

    /**
     * THE NEWEST LIVE ENTRIES OF ONE TYPE, the archive's own order (newest
     * first, the higher id first on a tie) — for "latest" panels and
     * related()'s fill. `$excludeIds` are never returned: the entry on the
     * page, or what is already shown beside it. In `$lang`, or the page being
     * drawn's when it is null (`live()`).
     *
     * @param  list<int> $excludeIds
     * @return list<array<string, mixed>>  each with `author_name`
     */
    public static function recent(string $type, int $limit = 3, array $excludeIds = [], ?string $lang = null): array
    {
        $limit   = max(1, min(50, $limit));   // an integer this method made
        $exclude = array_values(array_filter(array_map('intval', $excludeIds), static fn (int $id): bool => $id > 0));
        $params  = [':type' => $type];
        $notIn   = '';
        if ($exclude !== []) {
            $marks = [];
            foreach ($exclude as $i => $id) {
                $marks[]           = ':x' . $i;
                $params[':x' . $i] = $id;
            }
            $notIn = ' AND p.id NOT IN (' . implode(', ', $marks) . ')';
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*, ' . User::bylineSql('u') . ' AS author_name
             FROM posts p
             JOIN users u ON u.id = p.author_id
             WHERE p.post_type = :type AND ' . self::live('p', $lang) . $notIn . "
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * THE ONE RULE for "a visitor can see this entry in a list": published,
     * its date has come, and in the language of the list (`inLanguage()`):
     * the page being drawn's, or `$lang`. As SQL, for a WHERE clause — `$alias`
     * is a literal the caller writes (`'p'`), never request data.
     *
     * ── WHY IT IS A FUNCTION AND NOT A HABIT ────────────────────────────────
     * "Published" is asked by ten queries across the archive, the term
     * archive, the home feed, search and the sitemap. Scheduling changes what
     * published MEANS — a date still to come keeps an entry off the site — and
     * ten copies of `status = 'published'` would have changed nine times. `UTC_TIMESTAMP()` and not `NOW()`: storage is UTC (see
     * Database::init()), and this is the one comparison that must not depend
     * on a session setting. The language is part of the rule for the same
     * reason: a list on a page in one language shows that language's entries,
     * and a list written later asks this and gets it.
     */
    public static function live(string $alias = '', ?string $lang = null): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return self::publishedSql($alias) . " AND {$a}published_at <= UTC_TIMESTAMP() AND " . self::notEnded($alias)
            . ' AND ' . self::inLanguage($alias, $lang);
    }

    /**
     * The stored statuses this copy shows as Published, as SQL: `published`,
     * and `sample` where samples are shown (`samplesShown()`). `$alias` is a
     * literal the caller writes. Not the date or the language: `live()` adds
     * those, for a list a visitor reads.
     */
    public static function publishedSql(string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return self::samplesShown() ? "{$a}status IN ('published', 'sample')" : "{$a}status = 'published'";
    }

    /**
     * WHETHER A SAMPLE ENTRY IS SHOWN, by this copy and this request: on a
     * copy that is not production (`Site\Environment`), and not in a page
     * drawn for a static copy (`Export\StaticCopy`), which is the published
     * site's wherever it is made, as Google's tags take it (`Site\Google`).
     */
    public static function samplesShown(): bool
    {
        return !defined('CMS_EXPORT') && !\Cms\Site\Environment::isProduction();
    }

    /**
     * An entry's status as this copy treats it: a sample entry's is
     * `published` where samples are shown and `private` where they are not;
     * any other status is itself. What a visitor may see is asked of this,
     * never of the column (`onSite()`, the public controller, the shop).
     *
     * @param array<string, mixed> $row
     */
    public static function siteStatus(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        if ($status !== self::SAMPLE) {
            return $status;
        }
        return self::samplesShown() ? 'published' : 'private';
    }

    /**
     * ON THE SITE, for a visitor: published as this copy treats it
     * (`siteStatus()`), its date come and its unpublish date not gone. The
     * PHP twin of `live()`, without its language, for a row already in hand:
     * a linked entry, a form's choice, a translation's other version.
     *
     * @param array<string, mixed> $row
     */
    public static function onSite(array $row): bool
    {
        return self::siteStatus($row) === 'published' && !self::isScheduled($row) && !self::hasEnded($row);
    }

    /**
     * Every sample entry not in the Trash, of every type, for System health:
     * newest first.
     *
     * @return list<array{id: int, post_type: string, title: string}>
     */
    public static function samples(): array
    {
        $rows = Database::getInstance()->query(
            "SELECT id, post_type, title FROM posts WHERE status = 'sample' ORDER BY updated_at DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'post_type' => (string) $r['post_type'], 'title' => (string) $r['title'],
        ], $rows);
    }

    /**
     * Every sample entry to the Trash, in one statement, and how many went.
     * Restored from it, each comes back Private (`restore()`), so a sample
     * never returns to a site by being restored.
     */
    public static function trashSamples(): int
    {
        $stmt = Database::getInstance()->prepare("UPDATE posts SET status = 'trash' WHERE status = 'sample'");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * IN ONE LANGUAGE, as SQL for a WHERE clause: rows in `$lang`, or, when it
     * is null, in the language of the page being drawn
     * (`Site\Language::drawn()`); '' is the site's own. Pages have the same
     * column (`Page::live()` asks this too). `$alias` is a literal the caller
     * writes; the language is checked to be one an address can hold before it
     * is written into the SQL.
     */
    public static function inLanguage(string $alias = '', ?string $lang = null): string
    {
        $lang = \Cms\Site\Language::checked($lang ?? \Cms\Site\Language::drawn());
        $a    = $alias !== '' ? $alias . '.' : '';

        return "{$a}lang = '{$lang}'";
    }

    /**
     * Not taken off the site by its unpublish date, as SQL: none set, or one
     * still to come. Pages have the same column and the same rule
     * (`Page::live()`). `$alias` is a literal the caller writes.
     */
    public static function notEnded(string $alias = ''): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return "({$a}unpublish_at IS NULL OR {$a}unpublish_at > UTC_TIMESTAMP())";
    }

    /**
     * Published or password-protected, with an unpublish date that has come —
     * the state the admin shows as Ended. Derived like Scheduled: nothing runs
     * at that moment, and from it on the entry or page answers a visitor as a
     * private one does. Any row with `status` and `unpublish_at`, a page's
     * too. A sample entry is asked as this copy treats it (`siteStatus()`).
     *
     * @param array<string, mixed> $row
     */
    public static function hasEnded(array $row): bool
    {
        if (!in_array(self::siteStatus($row), ['published', 'password'], true)) {
            return false;
        }
        $at = trim((string) ($row['unpublish_at'] ?? ''));
        return $at !== '' && ($ts = strtotime($at . ' UTC')) !== false && $ts <= time();
    }

    /**
     * Published or password-protected, with a date still to come — the state
     * the admin shows as Scheduled. Derived, not stored: the entry IS
     * published, it is just not time yet, and nothing has to run at the moment
     * it becomes time (there is no cron on shared hosting to run it). A
     * sample entry is asked as this copy treats it (`siteStatus()`), so where
     * samples are shown one with a date still to come waits for it, as
     * `live()` has it wait; its chip says Sample all the same.
     *
     * @param array<string, mixed> $row
     */
    public static function isScheduled(array $row): bool
    {
        if (!in_array(self::siteStatus($row), ['published', 'password'], true)) {
            return false;
        }
        $at = trim((string) ($row['published_at'] ?? ''));
        return $at !== '' && ($ts = strtotime($at)) !== false && $ts > time();
    }

    /**
     * A live entry always has a date. Given one, it is kept; given none, it
     * is "now" — the moment it went live — so a feed ordered by date never
     * meets a NULL, and "published" never quietly means "not on any list".
     * A sample entry too, since where samples are shown it is listed as a
     * published one is.
     */
    private static function liveDate(string $status, mixed $publishedAt): ?string
    {
        $at = trim((string) ($publishedAt ?? ''));
        if ($at !== '') {
            return $at;
        }
        return in_array($status, ['published', 'password', self::SAMPLE], true) ? gmdate('Y-m-d H:i:s') : null;
    }

    /**
     * The columns `update()` may write, and how each value is shaped on the
     * way in. `post_type`, `author_id` and `parent_id` are deliberately absent:
     * a type never changes, and authorship and belonging are structural facts
     * with their own setters (see setParent()) — a revision restore or an
     * import overwrite must not be able to move an entry to another author.
     */
    private const UPDATABLE = [
        'title', 'slug', 'content', 'excerpt', 'status', 'password_hash',
        'featured_image_id', 'published_at', 'unpublish_at', 'menu_order',
    ];

    /**
     * Update an existing post — ONLY the columns named in `$data`.
     *
     * ── ONLY WHAT IS NAMED, NOT EVERY COLUMN IT KNOWS ───────────────────────
     * Rewriting every column, with an absent key as NULL or 0, would make every
     * caller pass the live value of everything it was not changing — and one
     * that forgot would strip a protected entry's password, or reset its
     * `menu_order`, or, as an import's overwrite with no `featured_image_id` or
     * `published_at`, silently wipe an entry's picture and its date.
     *
     * A present key is written, `null` included; an absent key is left alone.
     * A caller that passes the full set gets exactly that, and a column added
     * later cannot be wiped by a caller written before it existed.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $sets   = [];
        $params = [':id' => $id];

        foreach (self::UPDATABLE as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $sets[]              = $column . ' = :' . $column;
            $params[':' . $column] = self::shape($column, $data[$column]);
        }

        if ($sets === []) {
            return;
        }

        // Column names come from the constant above, never from `$data`'s keys.
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        // A LIVE ENTRY ALWAYS HAS A DATE — liveDate()'s rule, applied after the
        // write because a partial update may set the status and leave the
        // date alone (the assistant publishing an entry it created private).
        if (array_key_exists('status', $data) || array_key_exists('published_at', $data)) {
            $pdo->prepare(
                "UPDATE posts SET published_at = UTC_TIMESTAMP()
                  WHERE id = :id AND status IN ('published', 'password', 'sample') AND published_at IS NULL"
            )->execute([':id' => $id]);
        }
    }

    /**
     * One column's value as the table stores it — the same shaping `create()`
     * applies, in one place, so the two cannot drift.
     */
    private static function shape(string $column, mixed $value): mixed
    {
        return match ($column) {
            'content'           => Sanitizer::clean((string) ($value ?? '')),
            'excerpt'           => self::cleanExcerpt($value),
            'status'            => $value ?? 'private',
            'featured_image_id' => !empty($value) ? (int) $value : null,
            'menu_order'        => (int) ($value ?? 0),
            'unpublish_at'      => is_string($value) && trim($value) !== '' ? trim($value) : null,
            default             => $value,
        };
    }

    /**
     * WHAT AN ENTRY'S PAGE PRINTS AS ITS TEXT: its content with the
     * shortcodes the site expands run (`Helpers\Shortcode::process()`:
     * galleries, forms, the business's details, a [video] an earlier release
     * stored). For a theme that prints an entry it reads through the models;
     * the CMS's own entry template prints this.
     *
     * @param array<string, mixed> $post a row of `posts`, or anything with `content`
     */
    public static function renderedContent(array $post): string
    {
        return \Cms\Helpers\Shortcode::process((string) ($post['content'] ?? ''));
    }

    /**
     * What stands for an entry in a list: its excerpt if it has one, otherwise
     * the start of its text. `cut` says whether anything was cut, so a caller
     * prints an ellipsis only when there is something missing.
     *
     * ── ONE FUNCTION, BECAUSE THERE WERE THREE COPIES ───────────────────────
     * The listing card, the home page and the search page each stripped and
     * cut the body themselves, and had already drifted: the home page appended
     * "…" to text it had not cut (the card had been fixed for exactly that),
     * and only search turned a closing `</p>` into a space, so on the other two
     * the last word of one paragraph ran into the first word of the next. The
     * excerpt would have been a fourth thing each of them had to remember.
     *
     * @param  array<string, mixed> $row  anything with `content`, and `excerpt` if it has one
     * @return array{text: string, cut: bool}
     */
    public static function summary(array $row, int $max = 220): array
    {
        $excerpt = trim((string) ($row['excerpt'] ?? ''));
        if ($excerpt !== '') {
            return ['text' => $excerpt, 'cut' => false];
        }

        // A block's end is a word boundary; stripping the tag alone glued
        // "…end of one paragraph" to "Start of the next…".
        // The business's [phone] and the rest read as the page prints them.
        $text  = \Cms\Site\Business::shortcodes((string) ($row['content'] ?? ''));
        $text  = (string) preg_replace('~</(p|h[1-6]|li|div|blockquote|td|th)>|<br\s*/?>~i', ' ', $text);
        $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $cut   = mb_substr($plain, 0, $max);

        return ['text' => $cut, 'cut' => $cut !== $plain];
    }

    /**
     * An excerpt is PLAIN TEXT — a sentence or two, printed escaped wherever it
     * appears. Tags are removed rather than escaped because the box is a
     * textarea with no editor: markup in it is a paste accident, and `<p>` on a
     * listing card as literal text is worse than the words without it. Runs of
     * whitespace fold to one space. Empty is NULL, so "has an excerpt" is one
     * test and not two.
     */
    public static function cleanExcerpt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $text === '' ? null : $text;
    }

    /**
     * Credit this entry to a user — its own setter, like setParent() below and
     * for the same reason: update() writes CONTENT, and a revision restore or an
     * import overwrite must not be able to move an entry to another author. One
     * caller, the entry form, for an editor or admin.
     *
     * CREDIT IS NOT AN EDIT. `updated_at` is ON UPDATE CURRENT_TIMESTAMP
     * (migration 003), so a bare `SET author_id` stamped the entry "modified
     * now": the sitemap's lastmod and the Article schema's dateModified both
     * moved for an article nobody had touched.
     * Naming the column keeps it — an explicitly assigned column is never
     * auto-updated.
     */
    /**
     * "Updated now", for an entry whose fields, terms or options changed in a
     * save that left its row as it was: `updated_at` moves with the row alone
     * (ON UPDATE CURRENT_TIMESTAMP), and those live beside it.
     */
    public static function touch(int $id): void
    {
        Database::getInstance()
            ->prepare('UPDATE posts SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':id' => $id]);
    }

    public static function setAuthor(int $id, int $userId): void
    {
        Database::getInstance()
            ->prepare('UPDATE posts SET author_id = :author, updated_at = updated_at WHERE id = :id')
            ->execute([':author' => $userId, ':id' => $id]);
    }

    /**
     * AN ENTRY'S OWN DATES, AS AN IMPORT KNOWS THEM: when it was written and
     * when it was last changed. Nothing else can
     * write `created_at`, and `updated_at` stamps itself on every UPDATE, so
     * without this an imported article is twelve years younger than it is, and
     * its sitemap lastmod and dateModified say "today".
     *
     * NULL leaves that date as it is. A value is `Y-m-d H:i:s` in UTC, as every
     * stored time here is; anything else is REFUSED, not guessed — a date the
     * database would quietly zero is worse than an error in an import's log.
     * The publish date is not here: create() and update() already write it.
     *
     * Its own setter for the reason setAuthor() is: update() writes content.
     */
    public static function setDates(int $id, ?string $createdAt = null, ?string $updatedAt = null): void
    {
        $sets   = [];
        $params = [':id' => $id];
        // The two column names are this method's own literals; only the VALUES
        // are bound.
        foreach (['created_at' => $createdAt, 'updated_at' => $updatedAt] as $column => $value) {
            if ($value === null) {
                continue;
            }
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            if ($at === false || $at->format('Y-m-d H:i:s') !== $value) {
                throw new \InvalidArgumentException(
                    'Post::setDates(): ' . $column . " must be 'Y-m-d H:i:s' in UTC, got " . var_export($value, true)
                );
            }
            $sets[]            = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        if ($sets === []) {
            return;
        }
        // Setting only created_at must not move updated_at: name it, or the
        // statement that fixes one date breaks the other.
        if ($updatedAt === null) {
            $sets[] = 'updated_at = updated_at';
        }
        Database::getInstance()
            ->prepare('UPDATE posts SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    /**
     * Set (or clear) which entry this one belongs to. Migration 047.
     *
     * ── DELIBERATELY *NOT* A COLUMN IN `update()` ───────────────────────────
     * Two callers of update() have no business touching an entry's parent:
     * `Revision::restore()`, which rolls back an entry's TEXT, and
     * `ChangeSetApplier`, which applies the assistant's edits, such as a new
     * title. Neither carries a parent to pass, because neither is about the
     * parent.
     *
     * And that is not merely the safer shape, it is the correct one. Belonging
     * is a structural fact about where a thing sits, not a content field a
     * revision captures: an older draft of a club event's write-up is not
     * evidence that it used to belong to a different club.
     *
     * So: one caller, the entry form, and one statement that says what it does.
     */
    public static function setParent(int $id, ?int $parentId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE posts SET parent_id = :parent WHERE id = :id');
        $stmt->execute([
            // AN ENTRY CANNOT BE ITS OWN PARENT, and the cheapest place to say
            // so is the statement that would store it. The form cannot offer
            // this and a hand-made POST can.
            ':parent' => ($parentId !== null && $parentId > 0 && $parentId !== $id) ? $parentId : null,
            ':id'     => $id,
        ]);
    }

    /**
     * id => title for the ids given, in ONE query.
     *
     * The entry list draws a parent's name on every child row, and asking per
     * row is one SELECT per entry on a screen that already draws sixty. Zeroes
     * and duplicates are filtered here rather than at each call site.
     *
     * @param  list<int> $ids
     * @return array<int, string>
     */
    public static function titlesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        // Placeholders FROM A COUNT, values bound — never the ids spliced into
        // the string, even though they are integers by the line above.
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = Database::getInstance()->prepare(
            "SELECT id, title FROM posts WHERE id IN ($marks)"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['title'];
        }

        return $out;
    }

    /**
     * The entries that belong to `$parentId`, in the order their list shows.
     *
     * @return list<array<string, mixed>>
     */
    public static function childrenOf(int $parentId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM posts
              WHERE parent_id = :parent AND status != \'trash\'
           ORDER BY menu_order ASC, title ASC'
        );
        $stmt->execute([':parent' => $parentId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The entries of one type that belong to `$parentId`, in their order
     * there, as `childrenOf()` orders them: the rows of its panel on the
     * parent's editor (`Ui\ChildRows`).
     *
     * @return list<array<string, mixed>>
     */
    public static function childrenOfType(int $parentId, string $postType): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM posts
              WHERE parent_id = :parent AND post_type = :type AND status != \'trash\'
           ORDER BY menu_order ASC, title ASC'
        );
        $stmt->execute([':parent' => $parentId, ':type' => $postType]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Every entry of a type a hand-ordered list arranges at once, in its
     * order: all of them but the trash's, as its list shows them with no
     * filter.
     *
     * @return list<array<string, mixed>>
     */
    public static function ofTypeInOrder(string $postType): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM posts WHERE post_type = :type AND status != \'trash\' ORDER BY menu_order ASC, created_at DESC'
        );
        $stmt->execute([':type' => $postType]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The place a new entry of this type takes among the entries of one
     * parent: after the last of them, since their order is kept within it.
     */
    public static function nextPositionUnder(string $postType, int $parentId): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COALESCE(MAX(menu_order), 0) + 1 FROM posts
              WHERE post_type = :type AND parent_id = :parent AND status != 'trash'"
        );
        $stmt->execute([':type' => $postType, ':parent' => $parentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * THE ORDER, AS ARRANGED: each entry its place in `$ids`, from 1, written
     * only where it moved, so an entry that kept its place keeps its "last
     * updated". The caller has checked that `$ids` is the whole of what it
     * orders and that each may be changed.
     *
     * @param list<int> $ids
     * @return int how many moved
     */
    public static function setOrder(array $ids): int
    {
        $stmt  = Database::getInstance()->prepare(
            'UPDATE posts SET menu_order = :n WHERE id = :id AND menu_order <> :m'
        );
        $moved = 0;
        foreach (array_values($ids) as $i => $id) {
            $stmt->execute([':n' => $i + 1, ':m' => $i + 1, ':id' => (int) $id]);
            $moved += $stmt->rowCount();
        }

        return $moved;
    }

    /**
     * The entry that holds this slug in `$lang` ('' the site's own language)
     * — any type, the trash included, because a slug is UNIQUE across all of
     * them within a language — or null. For the message that names what is in
     * the way, where a bare "taken" would leave the author hunting for it.
     *
     * @return array<string, mixed>|null
     */
    public static function slugOwner(string $slug, ?int $excludeId = null, string $lang = ''): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, post_type, title, status FROM posts
              WHERE slug = :slug AND id <> :id AND ' . self::inLanguage('', $lang) . ' LIMIT 1'
        );
        $stmt->execute([':slug' => $slug, ':id' => $excludeId ?? 0]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Check whether a slug is already taken in the posts table, within
     * `$lang` ('' the site's own language).
     * Pass $excludeId when editing an existing post (exclude self).
     */
    public static function slugExists(string $slug, ?int $excludeId = null, string $lang = ''): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM posts WHERE slug = :slug AND id != :id AND ' . self::inLanguage('', $lang) . ' LIMIT 1');
        $stmt->execute([':slug' => $slug, ':id' => $excludeId ?? 0]);

        return $stmt->fetch() !== false;
    }

    /**
     * Delete a post by ID.
     */
    public static function delete(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM posts WHERE id = :id');
        $stmt->execute([':id' => $id]);
        // Its share link names it by id, and nothing cascades to it.
        \Cms\Content\Share::deleteFor('post', $id);
    }

    /**
     * Generate a URL-safe slug from a title, guaranteed unique in the posts
     * table within `$lang` ('' the site's own language).
     * Optionally exclude a post ID (for edits — don't conflict with self).
     */
    public static function generateSlug(string $title, ?int $excludeId = null, string $lang = ''): string
    {
        // Build base slug: lowercase, alphanumeric + hyphens only.
        $base = strtolower($title);
        $base = preg_replace('/[^a-z0-9\s-]/', '', $base) ?? $base;
        $base = preg_replace('/[\s-]+/', '-', trim($base)) ?? $base;
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'post';
        }

        $candidate = $base;
        $suffix    = 2;

        while (self::slugExists($candidate, $excludeId, $lang)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate; // Unique — done.
    }
}
