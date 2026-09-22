<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class Validator
{
    private array $errors = [];
    private array $data;
    private array $rules;

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * 執行驗證
     *
     * @param array $data 待驗證資料
     * @param array $rules 規則定義，例如：['username' => 'required|string|min:4|max:50']
     * @return static
     */
    public static function make(array $data, array $rules): static
    {
        $validator = new static($data, $rules);
        $validator->validate();
        return $validator;
    }

    public function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $label = $field;

            foreach ($rules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $method = 'rule' . ucfirst($rule);
                if (method_exists($this, $method)) {
                    $this->$method($field, $label, $value, ...$params);
                }
            }
        }
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? null;
        }
        return null;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    // --- 驗證規則 ---

    private function ruleRequired(string $field, string $label, mixed $value): void
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, "{$label} 為必填欄位");
        }
    }

    private function ruleString(string $field, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '' && !is_string($value)) {
            $this->addError($field, "{$label} 必須為字串");
        }
    }

    private function ruleEmail(string $field, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "{$label} 格式不正確");
        }
    }

    private function ruleMin(string $field, string $label, mixed $value, string $min): void
    {
        $minVal = (int) $min;
        if ($value !== null && $value !== '') {
            if (is_string($value) && mb_strlen($value) < $minVal) {
                $this->addError($field, "{$label} 至少需要 {$minVal} 個字元");
            } elseif (is_numeric($value) && (float)$value < $minVal) {
                $this->addError($field, "{$label} 最小值為 {$minVal}");
            }
        }
    }

    private function ruleMax(string $field, string $label, mixed $value, string $max): void
    {
        $maxVal = (int) $max;
        if ($value !== null && $value !== '') {
            if (is_string($value) && mb_strlen($value) > $maxVal) {
                $this->addError($field, "{$label} 不可超過 {$maxVal} 個字元");
            } elseif (is_numeric($value) && (float)$value > $maxVal) {
                $this->addError($field, "{$label} 最大值為 {$maxVal}");
            }
        }
    }

    private function ruleConfirmed(string $field, string $label, mixed $value): void
    {
        $confirmation = $this->data[$field . '_confirmation'] ?? null;
        if ($value !== null && $value !== '' && $value !== $confirmation) {
            $this->addError($field, "{$label} 確認不一致");
        }
    }

    private function ruleIn(string $field, string $label, mixed $value, string ...$allowed): void
    {
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->addError($field, "{$label} 必須為以下值之一: " . implode(', ', $allowed));
        }
    }

    private function ruleRegex(string $field, string $label, mixed $value, string $pattern): void
    {
        if ($value !== null && $value !== '' && !preg_match($pattern, (string)$value)) {
            $this->addError($field, "{$label} 格式不正確");
        }
    }

    private function ruleNumeric(string $field, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->addError($field, "{$label} 必須為數字");
        }
    }

    private function ruleInteger(string $field, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '' && !ctype_digit((string)$value)) {
            $this->addError($field, "{$label} 必須為整數");
        }
    }

    private function ruleUrl(string $field, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, "{$label} 必須為有效的 URL");
        }
    }
}
