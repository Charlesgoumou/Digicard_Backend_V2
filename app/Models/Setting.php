<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Récupère une valeur de paramètre par sa clé
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Définit ou met à jour un paramètre
     * 
     * @param string $key
     * @param mixed $value
     * @return Setting
     */
    public static function set(string $key, $value): Setting
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Vérifie si un paramètre existe
     * 
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return self::where('key', $key)->exists();
    }
}
