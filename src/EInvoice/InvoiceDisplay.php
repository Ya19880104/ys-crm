<?php
declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Core\Encryption;

/** Presentation only: never writes a profile or constructs an issue request. */
final class InvoiceDisplay
{
    public static function decode(string $stored): array
    {
        if (trim($stored) === '') { return []; }
        try {
            // Historical snapshots were plain JSON; newer snapshots are AES-GCM.
            $json = str_starts_with(ltrim($stored), '{') ? $stored : (new Encryption())->decrypt($stored);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function fromIntent(array $intent): array
    {
        return self::summary([
            'profile_type' => $intent['profile_type'] ?? '',
            'buyer' => ['name' => $intent['buyer_name'] ?? '', 'identifier' => $intent['buyer_identifier'] ?? '',
                'email' => $intent['buyer_email'] ?? '', 'phone' => $intent['buyer_phone'] ?? ''],
            'carrier_type' => $intent['carrier_type'] ?? '',
            'carrier_id1' => $intent['carrier_id_1'] ?? '', 'carrier_id2' => $intent['carrier_id_2'] ?? '',
            'npoban' => $intent['love_code'] ?? '',
        ]);
    }

    public static function summary(array $snapshot): array
    {
        $str = static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '';
        $buyer = is_array($snapshot['buyer'] ?? null) ? $snapshot['buyer'] : [];
        $identifier = $str($buyer['identifier'] ?? '');
        $donation = $str($snapshot['npoban'] ?? '');
        $type = $str($snapshot['profile_type'] ?? '');
        if (!in_array($type, ['b2b','b2c','donate'], true)) {
            $type = $donation !== '' ? 'donate' : (preg_match('/^\d{8}$/', $identifier) === 1 ? 'b2b' : 'unknown');
        }
        $carrier = $str($snapshot['carrier_type'] ?? '');
        $carrier = match ($carrier) {
            '3J0002', 'PhoneBarCodeCarrier' => '手機條碼',
            '1K0001', 'EasyCardCarrier' => '悠遊卡',
            'CQ0001', 'CitizenDigitalCardNo' => '自然人憑證',
            'BuyerSno' => 'PayNow 會員載具',
            'None' => '實體列印',
            default => $carrier,
        };
        return [
            'type' => $type,
            'label' => match ($type) {'b2b' => '三聯式（公司）', 'b2c' => '二聯式（個人）', 'donate' => '捐贈', default => '類型不明（歷史資料不足）'},
            'name' => $str($buyer['name'] ?? ''), 'identifier' => $identifier,
            'email' => $str($buyer['email'] ?? ''), 'phone' => $str($buyer['phone'] ?? ''),
            'donation' => $donation,
            'carrier' => in_array($type, ['b2b','donate'], true) ? '' : $carrier,
            'carrier_id1' => $str($snapshot['carrier_id1'] ?? ''),
            'carrier_id2' => $str($snapshot['carrier_id2'] ?? ''),
        ];
    }
}
