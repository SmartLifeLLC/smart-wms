<?php


namespace App\Traits;


use Illuminate\Support\Arr;

trait EnumExtensionTrait
{
    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function ids(): array
    {
        return Arr::map(self::cases(), fn($case) => $case->getID());
    }

    public static function getRandom(): self
    {
        $cases = self::cases();
        return Arr::random($cases);
    }

    public static function nameValues(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            $array[$case->name] = $case->value;
        }
        return $array;
    }

    public static function valueNames(): array
    {
        $array = [];
        foreach (self::cases() as $case) {
            $array[$case->value] = $case->name();
        }
        return $array;
    }

    /**
     * @param string $name
     * @return static|null
     */
    public static function fromName(string $name): static|null
    {
        foreach (self::cases() as $case) {
            if ($case->name == $name) {
                return $case;
            }
        }
        return null;
    }

    /**
     * name()で指定した値から変換
     * @param string $name
     * @return static|null
     */
    public static function fromLabelName(string $name): static|null
    {
        foreach (self::cases() as $case) {
            if ($case->name() == $name) {
                return $case;
            }
        }
        return null;
    }

    public static function fromId(mixed $id, $is_null_strict = false): static|null
    {

        //他の条件とはベッツにnullだけstrictチェックする
        if ($is_null_strict && $id === null) return null;

        foreach (self::cases() as $case) {
            if ($case->getID() == $id) {
                return $case;
            }
        }
        return null;
    }

    /**
     * @param string|null $value
     * @param $default
     * @return static|null
     */
    public static function fromValue(?string $value, $default = null): static|null
    {
        foreach (self::cases() as $case) {
            if ($case->value == $value) {
                return $case;
            }
        }

        if ($default) {
            return $default;
        }
        return null;
    }

    public function isSameAs(null|string|self $value): bool
    {
        if ($value instanceof self) {
            $value = $value->value;
        }

        return $this->value == $value;
    }

    public function isSameId(?int $id): bool
    {
        return $this->getID() == $id;
    }

    public function isIn(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isSameAs($value)) {
                return true;
            }
        }
        return false;
    }

    public static function idValues(): array
    {
        return Arr::mapWithKeys(self::cases(), function ($case) {
            return [$case->getID() => $case->value];
        });
    }

    public static function idNames($with_unspecified = false, $unspecified_id = 0): array
    {
        $data = Arr::mapWithKeys(self::cases(), function ($case) {
            return [$case->getID() => $case->name()];
        });
        if ($with_unspecified) {
            $data = array_merge([$unspecified_id => '未指定'], $data);
        }
        return $data;
    }

    public static function valueColors(): array
    {
        return Arr::mapWithKeys(self::cases(), function ($case) {
            return [$case->value => $case->color()];
        });
    }
}
