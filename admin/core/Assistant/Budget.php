<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\Assistant\Transport\ClaudeTransport;
use Cms\Database\Database;
use Cms\Models\Option;

/**
 * Per-install spend ceiling for the assistant.
 *
 * An agent that calls a paid API on user input needs a hard stop. Without one a
 * single site — or a single confused loop — can run up unbounded cost, and the
 * bill arrives a month later with no way to attribute it.
 *
 * The ceiling is checked BEFORE each call, not after: refusing to start is the
 * only enforcement that actually works, since a request already in flight cannot
 * be un-billed.
 *
 * Prices are Claude API list rates in USD per million tokens. They live here
 * rather than in config because they change rarely and a stale config value
 * silently under-reports spend; override per install only if you have negotiated
 * rates.
 */
final class Budget
{
    /** Monthly ceiling in USD. 0 disables the assistant; null means no limit. */
    public const OPTION_LIMIT = 'assistant_monthly_limit_usd';

    /** @var array<string, array{input: float, output: float}> USD per 1M tokens */
    private const PRICING = [
        'claude-opus-5'   => ['input' => 5.00,  'output' => 25.00],
        'claude-sonnet-5' => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5'=> ['input' => 1.00,  'output' => 5.00],
    ];

    private const DEFAULT_LIMIT = 25.00;

    /**
     * The configured ceiling, or null when spending is unlimited.
     *
     * An unset option means "use the default" rather than "unlimited" — an
     * install that never visits Settings should still be protected.
     */
    public static function limit(): ?float
    {
        $raw = Option::get(self::OPTION_LIMIT);

        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }

        if (strtolower(trim($raw)) === 'unlimited') {
            return null;
        }

        return max(0.0, (float) $raw);
    }

    /** Spend so far in the current calendar month, in USD. */
    public static function spentThisMonth(string $model = ClaudeTransport::DEFAULT_MODEL): float
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query(
            'SELECT COALESCE(SUM(input_tokens), 0)  AS input,
                    COALESCE(SUM(output_tokens), 0) AS output
               FROM assistant_messages
              WHERE created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")'
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['input' => 0, 'output' => 0];

        return self::cost((int) $row['input'], (int) $row['output'], $model);
    }

    /** Convert a token count to USD for a model. */
    public static function cost(int $inputTokens, int $outputTokens, string $model): float
    {
        $rates = self::PRICING[$model] ?? self::PRICING['claude-opus-5'];

        return ($inputTokens / 1_000_000) * $rates['input']
             + ($outputTokens / 1_000_000) * $rates['output'];
    }

    /**
     * May another request be made?
     *
     * @return array{allowed: bool, reason: string|null, spent: float, limit: float|null}
     */
    public static function check(string $model = ClaudeTransport::DEFAULT_MODEL): array
    {
        $limit = self::limit();
        $spent = self::spentThisMonth($model);

        if ($limit === null) {
            return ['allowed' => true, 'reason' => null, 'spent' => $spent, 'limit' => null];
        }

        if ($limit <= 0.0) {
            return [
                'allowed' => false,
                'reason'  => 'The assistant is disabled — its monthly spending limit is set to $0.',
                'spent'   => $spent,
                'limit'   => $limit,
            ];
        }

        if ($spent >= $limit) {
            return [
                'allowed' => false,
                'reason'  => sprintf(
                    'The assistant has reached its monthly limit of $%s (used $%s). '
                    . 'Raise it in Settings → General, or wait until next month.',
                    number_format($limit, 2),
                    number_format($spent, 2)
                ),
                'spent'   => $spent,
                'limit'   => $limit,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'spent' => $spent, 'limit' => $limit];
    }

    /** Fraction of the ceiling used, 0.0–1.0. Returns 0 when unlimited. */
    public static function fractionUsed(string $model = ClaudeTransport::DEFAULT_MODEL): float
    {
        $limit = self::limit();
        if ($limit === null || $limit <= 0.0) {
            return 0.0;
        }
        return min(1.0, self::spentThisMonth($model) / $limit);
    }
}
