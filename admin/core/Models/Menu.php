<?php

declare(strict_types=1);

namespace Cms\Models;

use Cms\Database\Database;
use PDO;

/**
 * A MENU, AND THE LOCATIONS IT OCCUPIES.
 *
 * ── LOCATIONS LIVE IN `menu_locations`, NOT ON THIS ROW ─────────────────────
 * Migration 049 moved them. `menus.location_slug` was one varchar with a UNIQUE
 * index, which enforced both "one menu per location" (true) and "one location per
 * menu" (invented). Putting the same menu in the header and the footer — the
 * commonest thing anybody does with a small site's navigation — silently emptied
 * the header.
 *
 * Every read of a menu's locations goes through `locationsFor()` or arrives on the
 * row as `locations` from `all()`. There is no second place that answers the
 * question, deliberately: two answers to one question disagree, and the screen
 * reading the stale one lies to its owner.
 */
class Menu
{
    /**
     * Every menu, each carrying the locations it occupies.
     *
     * ONE QUERY FOR THE LOCATIONS, not one per menu. The list screen draws a row
     * per menu and the edit screen's switcher draws every menu there is; a lookup
     * inside the loop is the N+1 that makes a menus screen slow on the one install
     * that has thirty of them.
     *
     * @return list<array<string, mixed>>  each with `item_count` and `locations`
     */
    public static function all(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM menu_items WHERE menu_id = m.id) AS item_count
             FROM menus m
             ORDER BY m.name ASC"
        );
        $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byMenu = [];
        foreach (self::locationMap() as $slug => $menuId) {
            $byMenu[$menuId][] = $slug;
        }

        foreach ($menus as &$menu) {
            $menu['locations'] = $byMenu[(int) $menu['id']] ?? [];
        }
        unset($menu);

        return $menus;
    }

    public static function find(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM menus WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * The menu shown in a location, or null when nothing is assigned there.
     *
     * This is the public renderer's entry point, run once per location per page
     * load — hence the unique index on `location_slug` rather than a scan.
     */
    public static function findByLocation(string $slug): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT m.* FROM menus m
               JOIN menu_locations ml ON ml.menu_id = m.id
              WHERE ml.location_slug = :slug"
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Every occupied location: slug => menu id.
     *
     * What "(Currently set to: X)" is built from, and what the Manage Locations
     * screen draws. Returned whole rather than queried per location because both
     * callers want all of it and there are three of them in this install.
     *
     * @return array<string, int>
     */
    public static function locationMap(): array
    {
        $pdo  = Database::getInstance();
        $rows = $pdo->query("SELECT location_slug, menu_id FROM menu_locations")
                    ->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['location_slug']] = (int) $row['menu_id'];
        }

        return $out;
    }

    /**
     * The locations one menu occupies.
     *
     * @return list<string>
     */
    public static function locationsFor(int $menuId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT location_slug FROM menu_locations WHERE menu_id = :id"
        );
        $stmt->execute([':id' => $menuId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * HOW THIS MENU BEHAVES IN EACH PLACE IT APPEARS — slug => raw JSON.
     *
     * Migration 050. On the PAIR and not on the menu, because the same menu in
     * the header and the footer wants different answers: a header opens
     * sub-menus on hover, a footer has none to open. That only became
     * expressible when migration 049 let one menu occupy both.
     *
     * @return array<string, string|null>
     */
    public static function settingsFor(int $menuId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT location_slug, settings FROM menu_locations WHERE menu_id = :id"
        );
        $stmt->execute([':id' => $menuId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['location_slug']] = $row['settings'];
        }

        return $out;
    }

    /**
     * Store the behaviour bag for one (menu, location) pair.
     *
     * SCOPED BY BOTH, so a stale slug in a posted payload cannot rewrite the
     * settings of a location this menu does not hold — the `WHERE` is the
     * authorisation.
     */
    public static function setLocationSettings(int $menuId, string $slug, ?string $json): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE menu_locations SET settings = :s
              WHERE menu_id = :id AND location_slug = :slug"
        );
        $stmt->execute([':s' => $json, ':id' => $menuId, ':slug' => $slug]);
    }

    /**
     * The phone's toggle bar for every location this menu holds — migration
     * 052, slug => stored JSON (NULL for the default bar).
     *
     * @return array<string, ?string>
     */
    public static function toggleBlocksFor(int $menuId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT location_slug, toggle_blocks FROM menu_locations WHERE menu_id = :id"
        );
        $stmt->execute([':id' => $menuId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['location_slug']] = $row['toggle_blocks'];
        }

        return $out;
    }

    /** Store one pair's toggle bar — scoped by both ids, as its settings are. */
    public static function setToggleBlocks(int $menuId, string $slug, ?string $json): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE menu_locations SET toggle_blocks = :t
              WHERE menu_id = :id AND location_slug = :slug"
        );
        $stmt->execute([':t' => $json, ':id' => $menuId, ':slug' => $slug]);
    }

    public static function create(string $name): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO menus (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Name and the auto-add switch. Locations are `setLocations()`'s job — a
     * method that took all three would make "save the name" and "take this menu
     * out of the footer" the same act, and a caller that only had a name would
     * have to pass something for the locations it did not mean to touch.
     */
    public static function update(int $id, string $name, ?bool $autoAddPages = null): bool
    {
        $pdo = Database::getInstance();

        if ($autoAddPages === null) {
            $stmt = $pdo->prepare("UPDATE menus SET name = :name WHERE id = :id");
            return $stmt->execute([':name' => $name, ':id' => $id]);
        }

        $stmt = $pdo->prepare(
            "UPDATE menus SET name = :name, auto_add_pages = :auto WHERE id = :id"
        );
        return $stmt->execute([
            ':name' => $name,
            ':auto' => $autoAddPages ? 1 : 0,
            ':id'   => $id,
        ]);
    }

    /**
     * Put this menu in exactly these locations, and nowhere else.
     *
     * ── TAKING A LOCATION OFF ANOTHER MENU IS THE POINT, NOT A SIDE EFFECT ──
     * A location holds one menu. Ticking "Primary Navigation" here necessarily
     * takes it from whichever menu had it, which is why the checkbox on the edit
     * screen is annotated "(Currently set to: X)" — the owner is told what they
     * are about to displace BEFORE they tick it, rather than discovering it when
     * their other menu goes dark.
     *
     * ── ONE TRANSACTION ─────────────────────────────────────────────────────
     * The delete and the inserts are one act. A failure between them would leave a
     * menu assigned nowhere and the site's navigation blank, which is not a state
     * anybody asked for and is not visible in this admin until somebody loads the
     * front of the site.
     *
     * @param list<string> $slugs  already filtered to registered locations
     */
    public static function setLocations(int $menuId, array $slugs): void
    {
        $pdo = Database::getInstance();

        // Duplicates in the posted array would violate the unique index and abort
        // the whole save over a checkbox sent twice.
        $slugs = array_values(array_unique(array_filter(
            array_map('strval', $slugs),
            static fn (string $s): bool => $s !== ''
        )));

        $own = $pdo->inTransaction() === false;
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM menu_locations WHERE menu_id = :id");
            $stmt->execute([':id' => $menuId]);

            if ($slugs !== []) {
                // The location's previous occupant loses it. `DELETE` then
                // `INSERT` rather than `INSERT … ON DUPLICATE KEY UPDATE`,
                // because the row being replaced belongs to a DIFFERENT menu and
                // an upsert would silently rewrite its owner — the same operation
                // either way, but only one of them says so.
                $clear  = $pdo->prepare("DELETE FROM menu_locations WHERE location_slug = :slug");
                $insert = $pdo->prepare(
                    "INSERT INTO menu_locations (location_slug, menu_id) VALUES (:slug, :id)"
                );

                foreach ($slugs as $slug) {
                    $clear->execute([':slug' => $slug]);
                    $insert->execute([':slug' => $slug, ':id' => $menuId]);
                }
            }

            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Assign one location to one menu — the Manage Locations screen's act.
     *
     * A null or empty menu id EMPTIES the location rather than assigning it to
     * nothing: "— Select a menu —" is a real choice on that screen and it means
     * "nothing here", not "leave it as it was".
     */
    public static function assignLocation(string $slug, ?int $menuId): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("DELETE FROM menu_locations WHERE location_slug = :slug");
        $stmt->execute([':slug' => $slug]);

        if ($menuId === null || $menuId <= 0) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO menu_locations (location_slug, menu_id) VALUES (:slug, :id)"
        );
        $stmt->execute([':slug' => $slug, ':id' => $menuId]);
    }

    /**
     * Every menu that has asked for new pages to be added to it automatically.
     *
     * @return list<array<string, mixed>>
     */
    public static function autoAddMenus(): array
    {
        $pdo = Database::getInstance();

        return $pdo->query("SELECT * FROM menus WHERE auto_add_pages = 1")
                   ->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function delete(int $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM menus WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
