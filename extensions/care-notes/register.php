<?php

declare(strict_types=1);

use Gfh\Core\Extension\ExtensionContext;
use Gfh\Core\Http\HttpException;

/*
 * Care notes: the demonstration extension (shared; switched on by the organization's care_notes feature).
 *
 * One short note per product. It is kept in the product's attributes under the extension-owned key ext_care_notes
 * (the Migrator does not run an extension's own migrations yet), edited in a "Care notes" tab on the Admin product
 * form, and printed in a "Care notes" section on the Shop product page. Nothing here is a core file, and everything
 * is closures, so loading it for several organizations never declares a function twice.
 */
return function (ExtensionContext $x): void {
    $key = 'ext_care_notes';
    $maxLength = 200; // the longest value a product attribute can hold

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // The stored note of a product (an Admin or public product record), or '' when it has none.
    $noteOf = static function (mixed $product) use ($key): string {
        $attributes = is_array($product) ? ($product['attributes'] ?? []) : [];
        if ($attributes instanceof \stdClass) {
            $attributes = (array) $attributes;
        }
        $note = is_array($attributes) ? ($attributes[$key] ?? '') : '';

        return is_string($note) ? $note : '';
    };

    // An item for people who may edit products. It links to the Products list: extension pages are not supported
    // yet, and the note itself is edited in the product's own "Care notes" tab.
    $x->hook('navigation.admin', function (array $items, array $context): array {
        $items[] = [
            'label' => 'Care notes',
            'url' => rtrim((string) ($context['base_url'] ?? ''), '/') . '/products',
            'module' => 'products',
            'action' => 'edit',
        ];

        return $items;
    });

    $x->hook('product.form.tabs', function (array $tabs, array $context) use ($escape, $noteOf, $maxLength): array {
        $product = is_array($context['product'] ?? null) ? $context['product'] : [];
        $input = is_array($context['input'] ?? null) ? $context['input'] : [];
        $errors = is_array($context['errors'] ?? null) ? $context['errors'] : [];
        $note = array_key_exists('care_notes', $input) && is_string($input['care_notes']) ? $input['care_notes'] : $noteOf($product);
        $error = is_string($errors['care_notes'] ?? null) ? $errors['care_notes'] : '';

        if (empty($context['can_edit'])) {
            $html = '<p>' . ($note === '' ? 'No care note yet.' : $escape($note)) . '</p>';
        } else {
            $action = rtrim((string) ($context['admin_base'] ?? ''), '/') . '/products/' . rawurlencode((string) ($product['id'] ?? ''));
            $describedBy = 'care-notes-help' . ($error !== '' ? ' care-notes-error' : '');
            $html = '<form method="post" action="' . $escape($action) . '" novalidate>'
                . '<input type="hidden" name="_csrf" value="' . $escape((string) ($context['csrf'] ?? '')) . '">'
                . '<input type="hidden" name="tab" value="care-notes">'
                . '<div class="field">'
                . '<label class="field__label" for="care-notes-field">Care note</label>'
                . '<input type="text" id="care-notes-field" name="care_notes" value="' . $escape($note) . '" maxlength="' . $maxLength . '"'
                . ($error !== '' ? ' aria-invalid="true"' : '') . ' aria-describedby="' . $describedBy . '">'
                . '<p class="help" id="care-notes-help">Shown to customers on the product page. Up to ' . $maxLength . ' characters; leave it empty for no note.</p>'
                . ($error !== '' ? '<p class="field-error" id="care-notes-error" role="alert">' . $escape($error) . '</p>' : '')
                . '</div>'
                . '<button type="submit" class="button">Save care note</button>'
                . '</form>';
        }

        $tabs[] = ['id' => 'care-notes', 'label' => 'Care notes', 'contentHtml' => $html];

        return $tabs;
    });

    // Validates and stores the note when the form carries one. A save without the field leaves the note alone.
    $x->hook('product.form.save', function (array $data, array $context) use ($key, $maxLength): array {
        if (!array_key_exists('care_notes', $data)) {
            return $data;
        }
        $raw = $data['care_notes'];
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            throw HttpException::unprocessable(['care_notes' => 'Enter the care note as plain text.']);
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $note) === 1) {
            throw HttpException::unprocessable(['care_notes' => 'Enter the care note as plain text.']);
        }
        if (mb_strlen($note) > $maxLength) {
            throw HttpException::unprocessable(['care_notes' => 'Enter up to ' . $maxLength . ' characters.']);
        }

        // The complete attribute set: the posted one, or the stored one when the form posts only the note.
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : (array) ($context['current_attributes'] ?? []);
        $attributes[$key] = $note; // an empty note is dropped by the catalog
        $data['attributes'] = $attributes;
        unset($data['care_notes']);

        return $data;
    });

    $x->hook('product.detail.sections', function (array $sections, array $context) use ($x, $escape, $noteOf): array {
        $note = $noteOf($context['product'] ?? null);
        if ($note === '') {
            return $sections;
        }
        $title = trim((string) $x->setting('care_notes.section_title'));
        $sections[] = [
            'id' => 'care-notes',
            'title' => $title === '' ? 'Care notes' : $title,
            'bodyHtml' => '<p>' . $escape($note) . '</p>',
        ];

        return $sections;
    });
};
