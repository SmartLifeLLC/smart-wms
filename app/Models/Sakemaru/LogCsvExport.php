<?php

namespace App\Models\Sakemaru;


use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Arr;

class LogCsvExport extends CustomModel
{
    protected $guarded = [];

    public static function addNumberFile($file_path, $file_count, $extension = 'csv')
    {
        $base_path = explode('.', $file_path)[0];
        return $base_path . '_' . $file_count . '.' . $extension;
    }

    public function files(): array
    {
        if ($this->file_count == 1) {
            return [$this->path];
        }
        return Arr::map(range(1, $this->file_count), fn($i) => self::addNumberFile($this->path, $i));
    }
}
