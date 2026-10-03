<?php
/**
 * Company & receipt settings (the `settings` key/value table), edited by admins.
 * Read values anywhere with setting('key').
 */
declare(strict_types=1);

final class Settings
{
    /** The sample values shipped in database.sql — shown as a reminder until replaced. */
    public const PLACEHOLDERS = [
        'shop_address' => 'Company Address, City, Province',
        'shop_phone'   => '(000) 000-0000',
    ];

    /** Current values of every editable key. */
    public static function all(): array
    {
        return [
            'shop_name'      => setting('shop_name', 'EXECOM Logistics'),
            'shop_address'   => setting('shop_address'),
            'shop_phone'     => setting('shop_phone'),
            'shop_tin'       => setting('shop_tin'),
            'vat_rate'       => setting('vat_rate', '12.00'),
            'receipt_footer' => setting('receipt_footer'),
            'job_quote_threshold' => JobOrders::quoteThreshold(),
        ];
    }

    /** True while the sample address/phone are still in use. */
    public static function hasPlaceholders(): bool
    {
        foreach (self::PLACEHOLDERS as $key => $value) {
            if (setting($key) === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>} [clean values, field errors]
     */
    public static function validate(array $input): array
    {
        $data = [
            'shop_name'      => input_string($input, 'shop_name', 100),
            'shop_address'   => input_string($input, 'shop_address', 255),
            'shop_phone'     => input_string($input, 'shop_phone', 30),
            'shop_tin'       => input_string($input, 'shop_tin', 20),
            'receipt_footer' => input_string($input, 'receipt_footer', 255),
        ];
        $errors = [];

        if (mb_strlen($data['shop_name']) < 2) {
            $errors['shop_name'] = 'Enter the company name (at least 2 characters).';
        }
        if ($data['shop_address'] === '') {
            $errors['shop_address'] = 'Enter the address printed on receipts.';
        }
        if ($data['shop_phone'] !== '' && !preg_match('/^[0-9+()\s-]{7,30}$/', $data['shop_phone'])) {
            $errors['shop_phone'] = 'Use digits, spaces, + ( ) or - (7–30 characters).';
        }
        // BIR TIN: 000-000-000 or 000-000-000-00000 (digits and dashes)
        if ($data['shop_tin'] !== '' && !preg_match('/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/', $data['shop_tin'])) {
            $errors['shop_tin'] = 'Use the TIN format 000-000-000-000 (or leave it empty).';
        }

        $vat = input_decimal($input, 'vat_rate', 0, 100, 2);
        if ($vat === null) {
            $errors['vat_rate'] = 'Enter a VAT rate from 0 to 100 (e.g. 12).';
        } else {
            $data['vat_rate'] = number_format($vat, 2, '.', '');
        }

        // Missing from older forms / scripts: keep the saved value.
        if (!array_key_exists('job_quote_threshold', $input)) {
            $data['job_quote_threshold'] = JobOrders::quoteThreshold();
        } else {
            $raw = is_string($input['job_quote_threshold']) ? str_replace(',', '', trim($input['job_quote_threshold'])) : '';
            $min = input_decimal(['v' => $raw], 'v', 0, 9999999.99, 2);
            if ($min === null) {
                $errors['job_quote_threshold'] = 'Enter an amount from 0 (e.g. 1,000.00).';
            } else {
                $data['job_quote_threshold'] = number_format($min, 2, '.', '');
            }
        }

        return [$data, $errors];
    }

    /** Save all values at once. */
    public static function save(array $data): void
    {
        if (!Auth::can('settings.manage')) {
            throw new HttpException(403, 'You do not have permission to change the company settings.');
        }
        [$old, $new] = Audit::diff(self::all(), $data);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($data as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            if ($new) {
                Audit::record('settings', 'update', 'settings', null, 'Company & receipt', $old, $new);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
