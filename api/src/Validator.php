<?php

class Validator
{
    /**
     * Validates $data against simple rules.
     * Rules example:
     *   ['email' => 'required|email', 'name' => 'required|min:2|max:150']
     * Returns an array of error messages, empty if valid.
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                $param = null;
                if (strpos($rule, ':') !== false) {
                    [$rule, $param] = explode(':', $rule, 2);
                }

                switch ($rule) {
                    case 'required':
                        if ($value === null || $value === '') {
                            $errors[$field][] = "$field is required";
                        }
                        break;
                    case 'email':
                        if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "$field must be a valid email";
                        }
                        break;
                    case 'min':
                        if ($value !== null && $value !== '' && strlen((string) $value) < (int) $param) {
                            $errors[$field][] = "$field must be at least $param characters";
                        }
                        break;
                    case 'max':
                        if ($value !== null && strlen((string) $value) > (int) $param) {
                            $errors[$field][] = "$field must not exceed $param characters";
                        }
                        break;
                    case 'numeric':
                        if ($value !== null && $value !== '' && !is_numeric($value)) {
                            $errors[$field][] = "$field must be numeric";
                        }
                        break;
                    case 'date':
                        if ($value && !self::isValidDate($value)) {
                            $errors[$field][] = "$field must be a valid date (YYYY-MM-DD)";
                        }
                        break;
                    case 'in':
                        $allowed = explode(',', $param);
                        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
                            $errors[$field][] = "$field must be one of: " . implode(', ', $allowed);
                        }
                        break;
                }
            }
        }

        return $errors;
    }

    private static function isValidDate(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}
