<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

/**
 * PayNow 電子發票 REST v1 開立 payload 組裝與驗證。
 *
 * 移植自 ys-enhance-hosting 的 YSEInvoicePayloadBuilder（該實作經 PayNow 正式機三輪實測定案）。
 * 本類別為純資料轉換，不碰資料庫、不發 HTTP，故可完全以單元測試覆蓋。
 *
 * 【為何這些規則不能憑文件推測】
 * PayNow 官方文件對數個欄位的標記與實際行為不符，以下規則全部來自正式機 422 實測，
 * 錯一個欄位就整張開不出來。修改前務必先讀懂各欄位上方註解引用的 request_id。
 */
final class InvoicePayloadBuilder
{
    /** 稅別代碼 → PayNow enum */
    private const TAX_MAP = [
        '1' => 'SaleTax',
        '2' => 'ZeroTax',
        '3' => 'FreeTax',
    ];

    /** 舊版載具代碼 → REST v1 強型別 enum */
    private const CARRIER_MAP = [
        '3J0002' => 'PhoneBarCodeCarrier',
        '1K0001' => 'EasyCardCarrier',
        'CQ0001' => 'CitizenDigitalCardNo',
    ];

    /** 需要帶載具明碼／隱碼的載具類型 */
    private const CARRIER_NEEDS_ID = [
        'PhoneBarCodeCarrier',
        'EasyCardCarrier',
        'CitizenDigitalCardNo',
    ];

    /**
     * 由原始值組出開立 payload。
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     * @throws \InvalidArgumentException 任一必要欄位不合法（一律 fail-closed，不送出殘缺 payload）
     */
    public static function buildFromValues(array $values): array
    {
        $orderNo = trim((string) ($values['order_no'] ?? ''));
        $amount  = max(0, (int) ($values['amount'] ?? 0));
        $type    = (string) ($values['profile_type'] ?? 'b2c');
        $email   = trim((string) ($values['buyer_email'] ?? ''));
        $name    = mb_substr(trim((string) ($values['buyer_name'] ?? '')), 0, 50);
        $phone   = trim((string) ($values['buyer_phone'] ?? ''));

        if ($orderNo === '' || $amount < 1) {
            throw new \InvalidArgumentException('電子發票訂單編號或金額不正確。');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('請填寫發票買受人名稱。');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('REST 電子發票需要有效的買受人 Email。');
        }

        // PayNow 正式機三輪實測的 BuyerPhone 規則（2026-08-01）：
        //   null（省略）   → 422 "The BuyerPhone field is required."（request_id 6f453311）
        //   市話等非手機值 → 422 "Invalid BuyerPhone"（request_id b6ea5dab）
        //   空字串 ''      → 通過並成功開立（發票 DP24824308）
        // 即「09 開頭 10 碼手機，或空字串」——與官方 V1.5「非手機留空」慣例一致。
        // 留空僅代表不做手機會員載具歸戶與簡訊，不影響發票效力。
        $countryCode = strtoupper(trim((string) ($values['country'] ?? 'TW')));
        if (($countryCode === '' || $countryCode === 'TW') && preg_match('/^09\d{8}$/', $phone) !== 1) {
            $phone = '';
        }

        $taxType = self::TAX_MAP[(string) ($values['default_tax_type'] ?? '1')] ?? 'SaleTax';
        $reason  = (string) ($values['zero_tax_rate_reason'] ?? 'None');
        if ($taxType === 'ZeroTax' && $reason === 'None') {
            throw new \InvalidArgumentException('零稅率發票必須指定零稅率原因。');
        }
        if ($taxType !== 'ZeroTax') {
            $reason = 'None';
        }

        $isB2b      = $type === 'b2b';
        $identifier = $isB2b ? preg_replace('/\D+/', '', (string) ($values['buyer_identifier'] ?? '')) : '';
        if ($isB2b && preg_match('/^\d{8}$/', (string) $identifier) !== 1) {
            throw new \InvalidArgumentException('公司電子發票需要 8 碼統一編號。');
        }

        // B2B 開立含稅金額時需自行拆算稅額（400→19、12600→600）；
        // B2C 帶 0 由國稅局計算。
        $taxAmount = $isB2b && $taxType === 'SaleTax'
            ? (int) round($amount - ($amount / 1.05), 0, PHP_ROUND_HALF_UP)
            : 0;

        // PayNow 正式機實測：carrier_type 送 'None' 會回 InValid CarrierType/CarrierId1/CarrierId2
        // ——'None' 專屬「實體列印發票」情境（send_paper=true）。
        // 官方「統編發票」「非統編發票」兩份範例的預設載具均為 BuyerSno（PayNow 會員載具，
        // 依買方電話歸戶），載具明碼／隱碼帶空字串。
        // 另注意：空字串在 REST 的 JSON 反序列化層會直接炸（could not be converted to CarrierType），
        // 故「無載具偏好」不得送空字串，一律落到 BuyerSno。
        $legacyCarrier = strtoupper(trim((string) ($values['carrier_type'] ?? '')));
        $carrierType   = self::CARRIER_MAP[$legacyCarrier] ?? 'BuyerSno';
        // 這裡的判斷只用於「要不要把使用者輸入的號碼讀進來」；
        // 真正的驗證用的是分支跑完之後重算的值（見下方）。
        $needsCarrierId = in_array($carrierType, self::CARRIER_NEEDS_ID, true);
        $carrierId1     = $needsCarrierId ? trim((string) ($values['carrier_id_1'] ?? '')) : '';
        $carrierId2     = $needsCarrierId ? trim((string) ($values['carrier_id_2'] ?? $carrierId1)) : '';

        $npoban         = $type === 'donate'
            ? (string) preg_replace('/\D+/', '', (string) ($values['love_code'] ?? ''))
            : '';

        if ($type === 'donate') {
            // 🔴 【這裡原本送空字串，會讓每一張捐贈發票都開不出來】
            // 官方文件確實寫「捐贈發票載具可帶空」，但那條規則**對 REST 的空字串不成立**：
            // REST 的 carrier_type 是強型別 enum，空字串在 JSON 反序列化階段就會被拒
            //（could not be converted to ...CarrierType），連欄位驗證都到不了。
            // 這與本檔上方註解所述的規則是同一條 —— 原本只套用在「無載具偏好」，
            // 漏掉了捐贈這條路徑。捐贈的語意由 npoban（愛心碼）表達，載具欄位一律 BuyerSno。
            //
            // ⚠️ 此修正依據正式機實測紀錄（見 docs/api/paynow-rest-v1.md 與交接文件），
            // 尚未以真實捐贈發票驗證 —— 首次開立捐贈發票時請確認。
            $carrierType = 'BuyerSno';
            $carrierId1  = '';
            $carrierId2  = '';

            if (preg_match('/^\d{3,7}$/', $npoban) !== 1) {
                throw new \InvalidArgumentException('捐贈發票需要 3～7 碼的愛心碼。');
            }
        }
        if ($isB2b) {
            $carrierType = 'BuyerSno';
            $carrierId1  = '';
            $carrierId2  = '';
            $npoban      = '';
        }

        // 🔴 【驗證必須排在 donate / b2b 分支之後】那兩個分支會把載具整組清空。
        // 原本驗證寫在它們之前，於是對一個**即將被丟棄的欄位**做驗證並丟例外：
        // 客戶 profile 從 B2C＋手機條碼改成 B2B 之後，殘留的 carrier_type 會讓
        // 每一張 B2B 發票在送出前就終局失敗，而後台顯示的理由是「對方回覆終局拒絕」
        // —— 操作者去翻 API 紀錄，卻根本沒有任何 API 呼叫發生過。
        // 🔴 以**最終**的 carrierType 重算，不能沿用第一次的結果：
        // donate / b2b 分支會把它改成 BuyerSno，而 BuyerSno 不需要載具號碼。
        // 用舊值判斷的話，那些分支清空的欄位仍會被拿去驗證並丟例外。
        $needsCarrierId = in_array($carrierType, self::CARRIER_NEEDS_ID, true);

        // 🔴 載具號碼格式在此擋掉，不要讓它送到 PayNow。
        // 送出去的兩種結局都不好：格式明顯錯的回 422（還算好，看得到錯誤），
        // 格式「像是對的」卻不存在的**會成功開立** —— 發票開在一個無效載具上，
        // 消費者查不到、歸不了戶，而唯一的補救是作廢重開（國稅局留下作廢紀錄）。
        // 事前擋下的成本是一個表單錯誤訊息，事後補救的成本是一張作廢發票。
        if ($needsCarrierId) {
            $ok = match ($carrierType) {
                // 手機條碼：斜線 + 7 碼（大寫英數與 + - . ）
                'PhoneBarCodeCarrier'  => preg_match('#^/[0-9A-Z+.-]{7}$#', $carrierId1) === 1,
                // 自然人憑證：2 碼大寫英文 + 14 碼數字
                'CitizenDigitalCardNo' => preg_match('/^[A-Z]{2}\d{14}$/', $carrierId1) === 1,
                // 悠遊卡等實體卡：**沒有可引用的公告格式**，因此只檢查「有填」。
                // 刻意不自創長度規則 —— 猜錯的代價是擋掉合法載具，
                // 而那比放行更難查（客戶拿不到載具發票，客服看不出原因）。
                default                => $carrierId1 !== '',
            };

            if (!$ok) {
                throw new \InvalidArgumentException(match ($carrierType) {
                    'PhoneBarCodeCarrier'  => '手機條碼載具格式不正確（應為斜線開頭共 8 碼，例如 /ABC+123）。',
                    'CitizenDigitalCardNo' => '自然人憑證載具格式不正確（應為 2 碼英文 + 14 碼數字）。',
                    default                => '載具號碼格式不正確。',
                });
            }

            // 隱碼未填時沿用明碼（PayNow 兩者皆需帶值）。
            if ($carrierId2 === '') {
                $carrierId2 = $carrierId1;
            }
        }

        $description = mb_substr(trim((string) ($values['description'] ?? $orderNo)), 0, 80);
        if ($description === '') {
            $description = $orderNo;
        }

        return [
            'order_no'             => mb_substr($orderNo, 0, 64),
            'send_paper'           => false,
            'send_sms'             => false,
            'carrier_type'         => $carrierType,
            'carrier_id1'          => $carrierId1,
            'carrier_id2'          => $carrierId2,
            'npoban'               => $npoban,
            'total_amount'         => $amount,
            'tax_amount'           => $taxAmount,
            'tax_type'             => $taxType,
            'main_remark'          => '',
            'is_pass_customs'      => false,
            'zero_tax_rate_reason' => $reason,
            'buyer'                => [
                'name'       => $name,
                'identifier' => (string) $identifier,
                // 本系統只開純電子發票（send_paper=false）。PayNow 對 null 會做 required 驗證，
                // 因此保留字串欄位但不傳送實際地址。
                'address'    => '',
                'phone'      => $phone,
                'email'      => $email,
            ],
            'items'                => [
                [
                    'quantity'    => 1,
                    'unit_price'  => $amount,
                    'amount'      => $amount,
                    'tax_type'    => $taxType,
                    'tax_amount'  => $taxAmount,
                    'description' => $description,
                ],
            ],
        ];
    }

    /**
     * 由 CRM 的付款 + 發票資料組出 payload，並產生「不可竄改快照」。
     *
     * 快照用途：發票明細一旦開立即固定，日後即使報價被改動，稽核仍能還原當時送出的內容。
     * 加密後存進 invoices.payload_preview。
     *
     * @param array<string,mixed> $payment  payments 表列
     * @param array<string,mixed> $snapshot 發票資料（買受人/載具/統編…）
     * @param array<string,mixed> $settings 發票設定（稅別預設值…）
     * @param string $paynowOrderNo 送給 PayNow 的訂單號（作廢重開時為 -R{id} 版本）
     * @return array{payload:array<string,mixed>,snapshot:array<string,mixed>}
     */
    public static function buildForPayment(
        array $payment,
        array $snapshot,
        array $settings,
        string $paynowOrderNo = ''
    ): array {
        $orderNo = $paynowOrderNo !== ''
            ? $paynowOrderNo
            : (string) ($payment['payment_no'] ?? '');

        $payload = self::buildFromValues([
            'order_no'             => $orderNo,
            'amount'               => (int) round((float) ($payment['amount'] ?? 0)),
            'description'          => (string) ($snapshot['description'] ?? $orderNo),
            'profile_type'         => (string) ($snapshot['profile_type'] ?? 'b2c'),
            'buyer_name'           => (string) ($snapshot['buyer_name'] ?? ''),
            'buyer_email'          => (string) ($snapshot['buyer_email'] ?? ''),
            'buyer_phone'          => (string) ($snapshot['buyer_phone'] ?? ''),
            'country'              => (string) ($snapshot['country'] ?? 'TW'),
            'buyer_identifier'     => (string) ($snapshot['buyer_identifier'] ?? ''),
            'carrier_type'         => (string) ($snapshot['carrier_type'] ?? ''),
            'carrier_id_1'         => (string) ($snapshot['carrier_id_1'] ?? ''),
            'carrier_id_2'         => (string) ($snapshot['carrier_id_2'] ?? ''),
            'love_code'            => (string) ($snapshot['love_code'] ?? ''),
            'default_tax_type'     => (string) ($settings['default_tax_type'] ?? '1'),
            'zero_tax_rate_reason' => (string) ($settings['zero_tax_rate_reason'] ?? 'None'),
        ]);

        return [
            'payload'  => $payload,
            'snapshot' => [
                'order_no'     => (string) $payload['order_no'],
                'total_amount' => (int) $payload['total_amount'],
                'tax_amount'   => (int) $payload['tax_amount'],
                'sales_amount' => max(0, (int) $payload['total_amount'] - (int) $payload['tax_amount']),
                'tax_type'     => (string) $payload['tax_type'],
                'profile_type' => (string) ($snapshot['profile_type'] ?? 'b2c'),
                'carrier_type' => (string) $payload['carrier_type'],
                'carrier_id1'  => (string) $payload['carrier_id1'],
                'carrier_id2'  => (string) $payload['carrier_id2'],
                'npoban'       => (string) $payload['npoban'],
                'buyer'        => (array) $payload['buyer'],
                'items'        => (array) $payload['items'],
            ],
        ];
    }

    /**
     * 電話正規化：去除非數字、把國碼 886 轉回 09 開頭本地格式。
     * 僅台灣需要；其他國家原樣回傳（由 buildFromValues 決定是否清空）。
     */
    public static function normalizePhone(string $phone, string $country = 'TW'): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if (strtoupper($country) !== 'TW' && $country !== '') {
            return $digits;
        }
        // 886912345678 / +886912345678 → 0912345678
        if (str_starts_with($digits, '886')) {
            $rest = substr($digits, 3);
            $digits = str_starts_with($rest, '0') ? $rest : '0' . $rest;
        }
        return $digits;
    }
}
