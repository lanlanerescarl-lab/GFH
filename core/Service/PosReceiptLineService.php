<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\PosRepository;
use Gfh\Core\Support\Uuid;

/**
 * Extension lines for the POS receipt (Batch 8.3b, hook pos.receipt.lines).
 *
 * The terminal builds its receipt in the browser from the snapshot it already holds (Batch 7.2), so the one honest
 * place to add lines is the snapshot. forSnapshot() runs the hook while the snapshot is built and returns what the
 * terminal copies onto the receipt:
 *
 *   ['variants' => [variantId => list<string>], 'footer' => list<string>]    (a key is left out when it is empty)
 *
 * A callback returns a list of {text, variant_id?}. A line with a variant_id is printed under every sale line of that
 * variant; a line without one is printed once, under the totals. The limits are enforced here, whatever the callback
 * returned: text only (one line, no tag, no control character), 1 to 120 characters, at most 3 lines per variant and
 * at most 3 in the footer, and the variant must be one the snapshot holds. A line that breaks a rule is dropped and
 * logged (never an error for the terminal): a receipt line is not worth a snapshot the terminal cannot download.
 *
 * Nothing is added unless a callback is registered, so without an extension the snapshot is what it always was.
 */
final class PosReceiptLineService
{
    public const MAX_PER_VARIANT = 3;
    public const MAX_FOOTER = 3;
    public const MAX_LENGTH = 120;
    /** Problems written to the log for one snapshot; the rest are counted in one closing line. */
    private const LOGGED_PROBLEMS = 5;

    public function __construct(private readonly Hooks $hooks, private readonly PosRepository $repository)
    {
    }

    /**
     * @param array<string, mixed>       $terminal the terminal row (id, code, name, branch_id, branch_name)
     * @param list<array<string, mixed>> $variantRows the snapshot's variant rows (variant_id, product_id, sku, product_name, variant_name)
     * @return array{variants?: array<string, list<string>>, footer?: list<string>}|null null: nothing to add
     */
    public function forSnapshot(array $terminal, array $variantRows): ?array
    {
        if (!$this->hooks->has(Hooks::POS_RECEIPT_LINES)) {
            return null;
        }

        $attributes = $this->repository->extensionAttributes();
        $variants = [];
        foreach ($variantRows as $row) {
            $variants[] = [
                'variant_id' => (string) $row['variant_id'],
                'product_id' => (string) $row['product_id'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['product_name'] . ((string) $row['variant_name'] !== '' ? ' - ' . (string) $row['variant_name'] : ''),
                // Only the extension-owned (ext_) attributes of the product: the rest of the catalog is not for extensions.
                'attributes' => $attributes[(string) $row['product_id']] ?? [],
            ];
        }

        try {
            $raw = $this->hooks->filter(Hooks::POS_RECEIPT_LINES, [], [
                'terminal' => [
                    'id' => (string) $terminal['id'],
                    'code' => (string) $terminal['code'],
                    'name' => (string) $terminal['name'],
                    'branch_id' => (string) $terminal['branch_id'],
                    'branch_name' => (string) ($terminal['branch_name'] ?? ''),
                ],
                'variants' => $variants,
            ]);
        } catch (HttpException) {
            // A refusal means nothing for a receipt line, and the terminal must always get its prices and stock.
            $this->hooks->problem(Hooks::POS_RECEIPT_LINES, 'a callback threw an HttpException; no receipt lines were added', 'http');

            return null;
        }

        $problems = 0;
        $report = function (string $reason, string $key) use (&$problems): void {
            $problems++;
            if ($problems <= self::LOGGED_PROBLEMS) {
                $this->hooks->problem(Hooks::POS_RECEIPT_LINES, $reason, $key);
            }
        };
        $lines = self::normalize(is_array($raw) ? $raw : [], array_column($variants, 'variant_id'), $report);
        if ($problems > self::LOGGED_PROBLEMS) {
            $this->hooks->problem(Hooks::POS_RECEIPT_LINES, sprintf('%d more receipt lines were ignored', $problems - self::LOGGED_PROBLEMS), 'more');
        }

        return $lines === [] ? null : $lines;
    }

    /**
     * Keeps the lines that obey the rules.
     *
     * @param array<mixed>                          $raw the hook's value
     * @param list<string>                          $variantIds the variants the snapshot holds
     * @param (callable(string, string): void)|null $problem told the reason and a key for each line that is dropped
     * @return array{variants?: array<string, list<string>>, footer?: list<string>}
     */
    public static function normalize(array $raw, array $variantIds, ?callable $problem = null): array
    {
        $report = static function (string $reason, string $key) use ($problem): void {
            if ($problem !== null) {
                $problem($reason, $key);
            }
        };
        $known = array_fill_keys($variantIds, true);

        $byVariant = [];
        $footer = [];
        foreach ($raw as $position => $item) {
            $where = 'line ' . (is_int($position) ? $position : 'x');
            if (!is_array($item)) {
                $report($where . ' ignored: it is not an object {text, variant_id?}', $where);
                continue;
            }

            $text = $item['text'] ?? null;
            $text = is_string($text) ? trim($text) : null;
            if ($text === null || $text === '' || mb_strlen($text) > self::MAX_LENGTH || !CheckoutFieldService::isPlainText($text)) {
                $report(sprintf('%s ignored: text must be one line of plain text, 1 to %d characters, with no tag', $where, self::MAX_LENGTH), $where);
                continue;
            }

            $variantId = $item['variant_id'] ?? null;
            if ($variantId === null || $variantId === '') {
                if (in_array($text, $footer, true)) {
                    continue;
                }
                if (count($footer) >= self::MAX_FOOTER) {
                    $report(sprintf('%s ignored: at most %d lines go under the totals', $where, self::MAX_FOOTER), 'footer');
                    continue;
                }
                $footer[] = $text;
                continue;
            }

            if (!is_string($variantId) || !Uuid::isValid($variantId) || !isset($known[$variantId])) {
                $report($where . ' ignored: variant_id is not a variant of this terminal\'s snapshot', $where);
                continue;
            }
            if (in_array($text, $byVariant[$variantId] ?? [], true)) {
                continue;
            }
            if (count($byVariant[$variantId] ?? []) >= self::MAX_PER_VARIANT) {
                $report(sprintf('variant %s: more than %d lines ignored', $variantId, self::MAX_PER_VARIANT), 'variant:' . $variantId);
                continue;
            }
            $byVariant[$variantId][] = $text;
        }

        $result = [];
        if ($byVariant !== []) {
            $result['variants'] = $byVariant;
        }
        if ($footer !== []) {
            $result['footer'] = $footer;
        }

        return $result;
    }
}
